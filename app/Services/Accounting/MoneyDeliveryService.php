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

            $suggestedAmount = isset($data['suggested_amount'])
                ? $this->normalizeAmount($data['suggested_amount'])
                : '0.00';

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
                'delivered_amount' => $amount,
                'business_date' => $data['business_date'],
                'reason' => $data['reason'] ?? 'Cubrir pérdida operativa',
                'notes' => $data['notes'] ?? null,
                'status' => 'confirmed',
                'idempotency_key' => $idempotencyKey,
                'created_by' => $user->id,
            ]);

            // Compensación en la banca: entregar dinero físico cancela o reduce la pérdida
            $branchBalanceBefore = (string) $branch->current_balance;
            $branchBalanceAfter = bcadd($branchBalanceBefore, $amount, 2);

            LedgerEntry::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'source_type' => MoneyDelivery::class,
                'source_id' => $delivery->id,
                'entry_type' => 'money_delivery',
                'signed_amount' => $amount,
                'balance_before' => $branchBalanceBefore,
                'balance_after' => $branchBalanceAfter,
                'business_date' => $data['business_date'],
                'description' => 'Dinero llevado a la banca: ' . ($data['reason'] ?? 'Cubrir pérdida'),
                'created_by' => $user->id,
            ]);

            $branch->update(['current_balance' => $branchBalanceAfter]);

            // Salida física de caja
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
                    'branch_balance_after' => $branchBalanceAfter,
                    'cash_balance_after' => $cashAfter,
                ],
                'expires_at' => now()->addHours(24),
            ]);

            $delivery->branch_balance_after = $branchBalanceAfter;

            return $delivery;
        });
    }

    private function normalizeAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
