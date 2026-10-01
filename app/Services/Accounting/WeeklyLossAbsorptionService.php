<?php

namespace App\Services\Accounting;

use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\IdempotencyKey;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WeeklyLossAbsorptionService
{
    public function absorb(User $user, int $companyId, array $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $companyId, $data, $idempotencyKey) {
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $stored = IdempotencyKey::query()
                ->where('company_id', $companyId)
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($stored) {
                if ($stored->operation !== 'branch.absorb_loss' || $stored->request_hash !== $hash) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['La clave ya fue usada con otra operación.'],
                    ]);
                }

                return $stored->response_body;
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
                throw ValidationException::withMessages(['amount' => ['El monto a absorber debe ser mayor a cero.']]);
            }

            $balanceBefore = (string) $branch->current_balance;
            $newBalance = bcadd($balanceBefore, $amount, 2);

            $reason = ! empty($data['reason']) ? trim($data['reason']) : 'Pérdida semanal asumida por el consorcio';
            $notes = ! empty($data['notes']) ? trim($data['notes']) : null;
            $businessDate = $data['business_date'] ?? now()->toDateString();
            $deductCashBox = filter_var($data['deduct_cash_box'] ?? true, FILTER_VALIDATE_BOOLEAN);

            $cashMovement = null;
            if ($deductCashBox) {
                $cashBoxId = $data['cash_box_id'] ?? null;
                $cashBox = null;
                if ($cashBoxId) {
                    $cashBox = CashBox::query()
                        ->where('company_id', $companyId)
                        ->whereKey($cashBoxId)
                        ->lockForUpdate()
                        ->first();
                } else {
                    $cashBox = CashBox::query()
                        ->where('company_id', $companyId)
                        ->where('is_default', true)
                        ->lockForUpdate()
                        ->first()
                        ?? CashBox::query()->where('company_id', $companyId)->first();
                }

                $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
                $cashBefore = (string) $company->cash_balance;
                $cashAfter = bcsub($cashBefore, $amount, 2);
                $company->update(['cash_balance' => $cashAfter]);

                $boxBefore = $cashBox ? (string) $cashBox->balance : $cashBefore;
                $boxAfter = $cashBox ? bcsub($boxBefore, $amount, 2) : $cashAfter;
                if ($cashBox) {
                    $cashBox->update(['balance' => $boxAfter]);
                }

                $cashMovement = CashMovement::query()->create([
                    'company_id' => $companyId,
                    'cash_box_id' => $cashBox?->id,
                    'branch_id' => $branch->id,
                    'movement_type' => 'loss_absorption',
                    'amount' => $amount,
                    'signed_amount' => bcmul($amount, '-1', 2),
                    'balance_before' => $boxBefore,
                    'balance_after' => $boxAfter,
                    'business_date' => $businessDate,
                    'reason' => $reason,
                    'reference' => 'Absorción Pérdida Banca ' . $branch->code,
                    'notes' => $notes,
                    'created_by' => $user->id,
                ]);
            }

            $ledgerEntry = LedgerEntry::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'source_type' => Branch::class,
                'source_id' => $branch->id,
                'entry_type' => 'weekly_loss_absorbed',
                'signed_amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $newBalance,
                'business_date' => $businessDate,
                'description' => $reason . ' (Semana a Cero)',
                'created_by' => $user->id,
            ]);

            $branch->update(['current_balance' => $newBalance]);

            $response = [
                'branch_id' => $branch->id,
                'branch_code' => $branch->code,
                'branch_name' => $branch->name,
                'amount_absorbed' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $newBalance,
                'ledger_entry_id' => $ledgerEntry->id,
                'cash_movement_id' => $cashMovement?->id,
                'deducted_from_cash_box' => $deductCashBox,
            ];

            IdempotencyKey::query()->create([
                'company_id' => $companyId,
                'user_id' => $user->id,
                'key' => $idempotencyKey,
                'operation' => 'branch.absorb_loss',
                'request_hash' => $hash,
                'response_code' => 200,
                'response_body' => $response,
                'expires_at' => now()->addHours(24),
            ]);

            return $response;
        });
    }

    private function normalizeAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
