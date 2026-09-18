<?php

namespace App\Services\Accounting;

use App\Models\Advance;
use App\Models\Branch;
use App\Models\IdempotencyKey;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdvanceService
{
    public function record(User $user, int $companyId, array $data, string $idempotencyKey): Advance
    {
        return DB::transaction(function () use ($user, $companyId, $data, $idempotencyKey) {
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $stored = IdempotencyKey::query()
                ->where('company_id', $companyId)
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($stored) {
                if ($stored->operation !== 'advance.create' || $stored->request_hash !== $hash) {
                    throw ValidationException::withMessages(['idempotency_key' => ['La clave ya fue usada con otra operación.']]);
                }

                return Advance::query()->findOrFail($stored->response_body['advance_id']);
            }

            $branch = Branch::query()->where('company_id', $companyId)->whereKey($data['branch_id'])->lockForUpdate()->firstOrFail();
            if ($branch->status !== 'active') {
                throw ValidationException::withMessages(['branch_id' => ['La banca está inactiva.']]);
            }

            $amount = $this->normalizeAmount($data['amount']);
            if (bccomp($amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => ['El monto debe ser mayor que cero.']]);
            }

            $balanceBefore = (string) $branch->current_balance;
            $balanceAfter = bcsub($balanceBefore, $amount, 2);
            $advance = Advance::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'amount' => $amount,
                'business_date' => $data['business_date'],
                'reason' => $data['reason'],
                'payment_method' => $data['payment_method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'confirmed',
                'idempotency_key' => $idempotencyKey,
                'created_by' => $user->id,
                'confirmed_at' => now(),
            ]);

            LedgerEntry::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'source_type' => Advance::class,
                'source_id' => $advance->id,
                'entry_type' => 'advance',
                'signed_amount' => bcmul($amount, '-1', 2),
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'business_date' => $data['business_date'],
                'description' => 'Adelanto registrado',
                'created_by' => $user->id,
            ]);

            $branch->update(['current_balance' => $balanceAfter]);
            IdempotencyKey::query()->create([
                'company_id' => $companyId,
                'user_id' => $user->id,
                'key' => $idempotencyKey,
                'operation' => 'advance.create',
                'request_hash' => $hash,
                'response_code' => 201,
                'response_body' => ['advance_id' => $advance->id],
            ]);

            return $advance;
        });
    }

    private function normalizeAmount(string $amount): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages(['amount' => ['El monto debe ser un decimal positivo con un máximo de dos decimales.']]);
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (ltrim($whole, '0') ?: '0').'.'.str_pad($fraction, 2, '0');
    }
}
