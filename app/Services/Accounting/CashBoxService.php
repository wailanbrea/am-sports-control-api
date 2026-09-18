<?php

namespace App\Services\Accounting;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashBoxService
{
    public function record(
        User $user,
        int $companyId,
        array $data,
        ?string $operation = null,
        ?string $idempotencyKey = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): CashMovement {
        return DB::transaction(function () use ($user, $companyId, $data, $operation, $idempotencyKey, $sourceType, $sourceId) {
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

            if ($operation && $idempotencyKey) {
                $stored = IdempotencyKey::query()
                    ->where('company_id', $companyId)
                    ->where('key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($stored) {
                    if ($stored->operation !== $operation || $stored->request_hash !== $hash) {
                        throw ValidationException::withMessages(['idempotency_key' => ['La clave ya fue usada con otra operación.']]);
                    }

                    return CashMovement::query()->findOrFail($stored->response_body['cash_movement_id']);
                }
            }

            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();

            if ($sourceType && $sourceId) {
                $existing = CashMovement::query()
                    ->where('company_id', $companyId)
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $amount = $this->normalizeAmount($data['amount']);
            $movementType = $data['movement_type'];
            $signedAmount = in_array($movementType, ['expense', 'branch_transfer'], true)
                ? bcmul($amount, '-1', 2)
                : $amount;
            $balanceBefore = (string) $company->cash_balance;

            if (bccomp($signedAmount, '0.00', 2) < 0 && bccomp($amount, $balanceBefore, 2) > 0) {
                throw ValidationException::withMessages(['amount' => ['El monto supera el saldo disponible de caja.']]);
            }

            $branchId = $data['branch_id'] ?? null;
            if ($movementType === 'branch_transfer') {
                $branch = Branch::query()
                    ->where('company_id', $companyId)
                    ->whereKey($branchId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($branch->status !== 'active') {
                    throw ValidationException::withMessages(['branch_id' => ['La sucursal está inactiva.']]);
                }
            }

            $balanceAfter = bcadd($balanceBefore, $signedAmount, 2);
            $movement = CashMovement::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'movement_type' => $movementType,
                'amount' => $amount,
                'signed_amount' => $signedAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'business_date' => $data['business_date'],
                'reason' => $data['reason'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'created_by' => $user->id,
            ]);

            $company->update(['cash_balance' => $balanceAfter]);

            if ($operation && $idempotencyKey) {
                IdempotencyKey::query()->create([
                    'company_id' => $companyId,
                    'user_id' => $user->id,
                    'key' => $idempotencyKey,
                    'operation' => $operation,
                    'request_hash' => $hash,
                    'response_code' => 201,
                    'response_body' => ['cash_movement_id' => $movement->id],
                ]);
            }

            return $movement;
        });
    }

    private function normalizeAmount(string $amount): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages(['amount' => ['El monto debe ser un decimal positivo con un máximo de dos decimales.']]);
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $normalized = (ltrim($whole, '0') ?: '0').'.'.str_pad($fraction, 2, '0');

        if (bccomp($normalized, '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => ['El monto debe ser mayor que cero.']]);
        }

        return $normalized;
    }
}
