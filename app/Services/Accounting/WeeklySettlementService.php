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

            // Ganancia bruta de la banca: Ventas - Premios
            $grossProfit = bcsub($sales, $prizes, 2);

            // Comisión de la banca: Se calcula sobre las ventas brutas según el porcentaje de la banca o el monto directo
            if (isset($data['commission_amount']) && bccomp((string) $data['commission_amount'], '0.00', 2) > 0) {
                $commission = $this->normalizeAmount($data['commission_amount']);
            } elseif (bccomp($commissionRate, '0.00', 2) > 0) {
                $commission = $this->roundAmount(bcdiv(bcmul($sales, $commissionRate, 4), '100', 4));
            } else {
                $commission = '0.00';
            }

            $cashDelivered = isset($data['cash_delivered_amount'])
                ? $this->normalizeAmount($data['cash_delivered_amount'])
                : '0.00';

            if (bccomp($sales, '0.00', 2) === 0
                && bccomp($prizes, '0.00', 2) === 0
                && bccomp($cashDelivered, '0.00', 2) === 0) {
                throw ValidationException::withMessages([
                    'sales_amount' => ['El cuadre debe incluir al menos un monto mayor que cero.'],
                ]);
            }

            // Resultado del juego (hoja de MegaLottery): Ventas - Premios - Comisión
            $gameResult = bcsub(bcsub($sales, $prizes, 2), $commission, 2);

            // Resultado neto semanal considerando si se aportó efectivo para premios:
            $netWithCash = bcadd($gameResult, $cashDelivered, 2);

            if (bccomp($netWithCash, '0.00', 2) >= 0) {
                // El vendedor tiene dinero en mano de las ventas netas para entregarle al consorcio
                $weeklyBalance = $netWithCash;
                $lossAbsorbedAmount = '0.00';
                $settlementType = 'gain';
            } else {
                // Hay déficit neto: El consorcio absorbe la pérdida de la semana.
                // El balance semanal para el vendedor cierra en 0.00 (no se le carga deuda adicional).
                // El cuadre semanal NO saca dinero de Caja Chica (el dinero para premios se registró cuando se llevó).
                $lossAbsorbedAmount = bcmul($netWithCash, '-1', 2);
                $weeklyBalance = '0.00';
                $settlementType = 'loss_absorbed';
            }

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
                'loss_absorbed_amount' => $lossAbsorbedAmount,
                'weekly_balance' => $weeklyBalance,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'notes' => $data['notes'] ?? null,
                'status' => $settlementType === 'loss_absorbed' ? 'settled' : match (bccomp($balanceAfter, '0.00', 2)) {
                    -1 => 'negative_balance',
                    0 => 'settled',
                    default => 'pending',
                },
                'settlement_type' => $settlementType,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $user->id,
            ]);

            $runningBalance = $balanceBefore;
            $this->createEntry($settlement, $user, $sales, $runningBalance, 'weekly_sales', 'Ventas semanales registradas');
            $runningBalance = bcadd($runningBalance, $sales, 2);
            $this->createEntry($settlement, $user, bcmul($prizes, '-1', 2), $runningBalance, 'weekly_prizes', 'Premios pagados registrados');
            $runningBalance = bcsub($runningBalance, $prizes, 2);

            if (bccomp($commission, '0.00', 2) > 0) {
                $this->createEntry($settlement, $user, bcmul($commission, '-1', 2), $runningBalance, 'weekly_commission', 'Comisión semanal de la banca');
                $runningBalance = bcsub($runningBalance, $commission, 2);
            }

            if (bccomp($cashDelivered, '0.00', 2) > 0) {
                $this->createEntry($settlement, $user, $cashDelivered, $runningBalance, 'weekly_cash_delivered', 'Fondo de caja chica aportado para premios');
                $runningBalance = bcadd($runningBalance, $cashDelivered, 2);
            }

            if (bccomp($lossAbsorbedAmount, '0.00', 2) > 0) {
                $this->createEntry($settlement, $user, $lossAbsorbedAmount, $runningBalance, 'weekly_loss_absorbed', 'Pérdida semanal asumida por el consorcio (Semana a Cero)');
                $runningBalance = bcadd($runningBalance, $lossAbsorbedAmount, 2);
            }

            $branch->update(['current_balance' => $balanceAfter]);

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
