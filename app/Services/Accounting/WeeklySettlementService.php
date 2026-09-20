<?php

namespace App\Services\Accounting;

use App\Models\Branch;
use App\Models\IdempotencyKey;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WeeklySettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WeeklySettlementService
{
    public function record(User $user, int $companyId, array $data, string $idempotencyKey): WeeklySettlement
    {
        return DB::transaction(function () use ($user, $companyId, $data, $idempotencyKey) {
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $stored = IdempotencyKey::query()
                ->where('company_id', $companyId)
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($stored) {
                if ($stored->operation !== 'weekly_settlement.create' || $stored->request_hash !== $hash) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['La clave ya fue usada con otra operación.'],
                    ]);
                }

                return WeeklySettlement::query()->findOrFail($stored->response_body['weekly_settlement_id']);
            }

            $branch = Branch::query()
                ->where('company_id', $companyId)
                ->whereKey($data['branch_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($branch->status !== 'active') {
                throw ValidationException::withMessages(['branch_id' => ['La banca está inactiva.']]);
            }

            if (WeeklySettlement::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branch->id)
                ->whereDate('week_start', $data['week_start'])
                ->whereDate('week_end', $data['week_end'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'week_start' => ['Ya existe un cuadre para esta banca y semana.'],
                ]);
            }

            $sales = $this->normalizeAmount($data['sales_amount']);
            $prizes = $this->normalizeAmount($data['prizes_amount']);
            $commissionRate = $this->normalizeAmount($data['commission_rate']);
            if (bccomp($commissionRate, '100.00', 2) > 0) {
                throw ValidationException::withMessages([
                    'commission_rate' => ['El porcentaje de comisión no puede ser mayor que 100.'],
                ]);
            }
            $commission = $this->roundAmount(bcdiv(bcmul($sales, $commissionRate, 4), '100', 4));
            $cashDelivered = $this->normalizeAmount($data['cash_delivered_amount']);
            if (bccomp($sales, '0.00', 2) === 0
                && bccomp($prizes, '0.00', 2) === 0
                && bccomp($cashDelivered, '0.00', 2) === 0) {
                throw ValidationException::withMessages([
                    'sales_amount' => ['El cuadre debe incluir al menos un monto mayor que cero.'],
                ]);
            }

            $weeklyBalance = bcadd(bcsub(bcsub($sales, $prizes, 2), $commission, 2), $cashDelivered, 2);
            $balanceBefore = (string) $branch->current_balance;
            $balanceAfter = bcadd($balanceBefore, $weeklyBalance, 2);

            $settlement = WeeklySettlement::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'week_start' => $data['week_start'],
                'week_end' => $data['week_end'],
                'sales_amount' => $sales,
                'prizes_amount' => $prizes,
                'commission_rate' => $commissionRate,
                'commission_amount' => $commission,
                'cash_delivered_amount' => $cashDelivered,
                'weekly_balance' => $weeklyBalance,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'notes' => $data['notes'] ?? null,
                'status' => match (bccomp($balanceAfter, '0.00', 2)) {
                    -1 => 'negative_balance',
                    0 => 'settled',
                    default => 'pending',
                },
                'idempotency_key' => $idempotencyKey,
                'created_by' => $user->id,
            ]);

            $runningBalance = $balanceBefore;
            $this->createEntry($settlement, $user, $sales, $runningBalance, 'weekly_sales', 'Ventas semanales registradas');
            $runningBalance = bcadd($runningBalance, $sales, 2);
            $this->createEntry($settlement, $user, bcmul($prizes, '-1', 2), $runningBalance, 'weekly_prizes', 'Premios pagados registrados');
            $runningBalance = bcsub($runningBalance, $prizes, 2);
            $this->createEntry($settlement, $user, bcmul($commission, '-1', 2), $runningBalance, 'weekly_commission', 'Comisión semanal de la banca');
            $runningBalance = bcsub($runningBalance, $commission, 2);
            $this->createEntry($settlement, $user, $cashDelivered, $runningBalance, 'weekly_cash_delivered', 'Efectivo entregado a la banca');

            $branch->update(['current_balance' => $balanceAfter]);

            if (bccomp($cashDelivered, '0.00', 2) > 0) {
                app(CashBoxService::class)->record(
                    $user,
                    $companyId,
                    [
                        'movement_type' => 'branch_transfer',
                        'amount' => $cashDelivered,
                        'business_date' => $data['week_end'],
                        'reason' => 'Efectivo entregado según cuadre semanal',
                        'branch_id' => $branch->id,
                        'reference' => 'Cuadre semanal #'.$settlement->id,
                        'notes' => $data['notes'] ?? null,
                        'adjust_branch_balance' => false,
                    ],
                    sourceType: WeeklySettlement::class,
                    sourceId: $settlement->id,
                );
            }

            IdempotencyKey::query()->create([
                'company_id' => $companyId,
                'user_id' => $user->id,
                'key' => $idempotencyKey,
                'operation' => 'weekly_settlement.create',
                'request_hash' => $hash,
                'response_code' => 201,
                'response_body' => ['weekly_settlement_id' => $settlement->id],
            ]);

            return $settlement->fresh();
        });
    }

    private function createEntry(
        WeeklySettlement $settlement,
        User $user,
        string $signedAmount,
        string $balanceBefore,
        string $entryType,
        string $description,
    ): void {
        if (bccomp($signedAmount, '0.00', 2) === 0) {
            return;
        }

        LedgerEntry::query()->create([
            'company_id' => $settlement->company_id,
            'branch_id' => $settlement->branch_id,
            'source_type' => WeeklySettlement::class,
            'source_id' => $settlement->id,
            'entry_type' => $entryType,
            'signed_amount' => $signedAmount,
            'balance_before' => $balanceBefore,
            'balance_after' => bcadd($balanceBefore, $signedAmount, 2),
            'business_date' => $settlement->week_end,
            'description' => $description,
            'created_by' => $user->id,
        ]);
    }

    private function normalizeAmount(string $amount): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages([
                'amount' => ['Los montos deben ser decimales con un máximo de dos decimales.'],
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (ltrim($whole, '0') ?: '0').'.'.str_pad($fraction, 2, '0');
    }

    private function roundAmount(string $amount): string
    {
        return bcdiv(bcadd($amount, '0.005', 3), '1', 2);
    }
}
