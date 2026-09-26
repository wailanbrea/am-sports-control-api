<?php

namespace App\Services\Accounting;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\IdempotencyKey;
use App\Models\LedgerEntry;
use App\Models\ManualResult;
use App\Models\MoneyDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MoneyDeliveryService
{
    public function record(User $user, int $companyId, array $data, string $idempotencyKey): MoneyDelivery
    {
        return DB::transaction(function () use ($user, $companyId, $data, $idempotencyKey) {
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $stored = IdempotencyKey::query()
                ->where('company_id', $companyId)
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($stored) {
                if ($stored->operation !== 'money_delivery.create' || $stored->request_hash !== $hash) {
                    throw ValidationException::withMessages(['idempotency_key' => ['La clave ya fue usada con otra operación.']]);
                }

                return MoneyDelivery::query()->findOrFail($stored->response_body['money_delivery_id']);
            }

            $branch = Branch::query()
                ->where('company_id', $companyId)
                ->whereKey($data['branch_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($branch->status !== 'active') {
                throw ValidationException::withMessages(['branch_id' => ['La banca está inactiva.']]);
            }

            $amount = $this->normalizeAmount($data['amount']);
            if (bccomp($amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => ['El monto entregado debe ser mayor que cero.']]);
            }

            $commissionRate = isset($data['commission_rate']) ? $this->normalizeAmount($data['commission_rate']) : '0.00';
            $commissionAmount = isset($data['commission_amount']) ? $this->normalizeAmount($data['commission_amount']) : '0.00';
            $grossAmount = isset($data['gross_amount']) ? $this->normalizeAmount($data['gross_amount']) : '0.00';

            if (bccomp($grossAmount, '0.00', 2) > 0 && bccomp($commissionRate, '0.00', 2) > 0 && bccomp($commissionAmount, '0.00', 2) === 0) {
                $commissionAmount = number_format((float) $grossAmount * ((float) $commissionRate / 100), 2, '.', '');
            }

            if (bccomp($grossAmount, '0.00', 2) > 0 && bccomp($amount, $grossAmount, 2) === 0 && bccomp($commissionAmount, '0.00', 2) > 0) {
                $amount = bcsub($grossAmount, $commissionAmount, 2);
            }

            if (bccomp($grossAmount, '0.00', 2) === 0) {
                $grossAmount = bcadd($amount, $commissionAmount, 2);
            }

            $suggestedAmount = isset($data['suggested_amount'])
                ? $this->normalizeAmount($data['suggested_amount'])
                : $grossAmount;

            $manualResultId = $data['manual_result_id'] ?? null;
            if ($manualResultId) {
                $manualResult = ManualResult::query()
                    ->where('company_id', $companyId)
                    ->whereKey($manualResultId)
                    ->first();
                if ($manualResult && bccomp($suggestedAmount, '0.00', 2) === 0) {
                    $suggestedAmount = number_format(abs((float) $manualResult->amount), 2, '.', '');
                }
            }

            // Registrar entrega de dinero
            $delivery = MoneyDelivery::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'manual_result_id' => $manualResultId,
                'suggested_amount' => $suggestedAmount,
                'gross_amount' => $grossAmount,
                'commission_rate' => $commissionRate,
                'commission_amount' => $commissionAmount,
                'delivered_amount' => $amount,
                'business_date' => $data['business_date'],
                'reason' => $data['reason'] ?? 'Cubrir pérdida operativa',
                'notes' => $data['notes'] ?? null,
                'status' => 'confirmed',
                'idempotency_key' => $idempotencyKey,
                'created_by' => $user->id,
            ]);

            // Compensación en la banca: entregar dinero físico y/o aplicar comisión cancela o reduce la deuda
            $branchBalanceBefore = (string) $branch->current_balance;
            $runningBalance = $branchBalanceBefore;

            $balanceAfterDelivery = bcadd($runningBalance, $amount, 2);
            LedgerEntry::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'source_type' => MoneyDelivery::class,
                'source_id' => $delivery->id,
                'entry_type' => 'money_delivery',
                'signed_amount' => $amount,
                'balance_before' => $runningBalance,
                'balance_after' => $balanceAfterDelivery,
                'business_date' => $data['business_date'],
                'description' => 'Dinero llevado a la banca: ' . ($data['reason'] ?? 'Cubrir pérdida'),
                'created_by' => $user->id,
            ]);
            $runningBalance = $balanceAfterDelivery;

            if (bccomp($commissionAmount, '0.00', 2) > 0) {
                $balanceAfterCommission = bcadd($runningBalance, $commissionAmount, 2);
                LedgerEntry::query()->create([
                    'company_id' => $companyId,
                    'branch_id' => $branch->id,
                    'source_type' => MoneyDelivery::class,
                    'source_id' => $delivery->id,
                    'entry_type' => 'delivery_commission',
                    'signed_amount' => $commissionAmount,
                    'balance_before' => $runningBalance,
                    'balance_after' => $balanceAfterCommission,
                    'business_date' => $data['business_date'],
                    'description' => 'Descuento por comisión (' . (float) $commissionRate . '%): ' . ($data['reason'] ?? 'Entrega de dinero'),
                    'created_by' => $user->id,
                ]);
                $runningBalance = $balanceAfterCommission;
            }

            $branch->update(['current_balance' => $runningBalance]);

            // Salida física de caja (solo el efectivo real entregado)
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $cashBefore = (string) $company->cash_balance;
            $cashAfter = bcsub($cashBefore, $amount, 2);

            CashMovement::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'movement_type' => 'branch_delivery',
                'amount' => $amount,
                'signed_amount' => bcmul($amount, '-1', 2),
                'balance_before' => $cashBefore,
                'balance_after' => $cashAfter,
                'business_date' => $data['business_date'],
                'reason' => 'Dinero llevado a ' . $branch->name . ': ' . ($data['reason'] ?? 'Pérdida'),
                'notes' => $data['notes'] ?? null,
                'source_type' => MoneyDelivery::class,
                'source_id' => $delivery->id,
                'created_by' => $user->id,
            ]);

            $company->update(['cash_balance' => $cashAfter]);

            IdempotencyKey::query()->create([
                'company_id' => $companyId,
                'user_id' => $user->id,
                'key' => $idempotencyKey,
                'operation' => 'money_delivery.create',
                'request_hash' => $hash,
                'response_code' => 201,
                'response_body' => [
                    'money_delivery_id' => $delivery->id,
                    'branch_balance_after' => $runningBalance,
                    'cash_balance_after' => $cashAfter,
                ],
                'expires_at' => now()->addHours(24),
            ]);

            $delivery->branch_balance_after = $runningBalance;

            return $delivery;
        });
    }

    private function normalizeAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
