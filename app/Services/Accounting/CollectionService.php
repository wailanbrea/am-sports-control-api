<?php

namespace App\Services\Accounting;

use App\Models\Branch;
use App\Models\Collection;
use App\Models\IdempotencyKey;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectionService
{
    public function record(User $user, int $companyId, array $data, string $idempotencyKey): Collection
    {
        return DB::transaction(function () use ($user, $companyId, $data, $idempotencyKey) {
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $stored = IdempotencyKey::query()
                ->where('company_id', $companyId)
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($stored) {
                if ($stored->operation !== 'collection.create' || $stored->request_hash !== $hash) {
                    throw ValidationException::withMessages(['idempotency_key' => ['La clave ya fue usada con otra operación.']]);
                }

                return Collection::query()->findOrFail($stored->response_body['collection_id']);
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
                throw ValidationException::withMessages(['amount' => ['El monto debe ser mayor que cero.']]);
            }
            if (bccomp($amount, (string) $branch->current_balance, 2) > 0) {
                throw ValidationException::withMessages(['amount' => ['El cobro supera el saldo pendiente y requiere autorización especial.']]);
            }

            $balanceBefore = (string) $branch->current_balance;
            $balanceAfter = bcsub($balanceBefore, $amount, 2);
            $collection = Collection::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'amount' => $amount,
                'business_date' => $data['business_date'],
                'payment_method' => $data['payment_method'],
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
                'source_type' => Collection::class,
                'source_id' => $collection->id,
                'entry_type' => 'collection',
                'signed_amount' => bcmul($amount, '-1', 2),
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'business_date' => $data['business_date'],
                'description' => 'Cobro recibido',
                'created_by' => $user->id,
            ]);

            $branch->update(['current_balance' => $balanceAfter]);

            if ($data['payment_method'] === 'cash') {
                app(CashBoxService::class)->record(
                    $user,
                    $companyId,
                    [
                        'movement_type' => 'income',
                        'amount' => $amount,
                        'business_date' => $data['business_date'],
                        'reason' => 'Cobro recibido',
                        'branch_id' => $branch->id,
                        'reference' => $data['reference'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ],
                    sourceType: Collection::class,
                    sourceId: $collection->id,
                );
            }

            IdempotencyKey::query()->create([
                'company_id' => $companyId,
                'user_id' => $user->id,
                'key' => $idempotencyKey,
                'operation' => 'collection.create',
                'request_hash' => $hash,
                'response_code' => 201,
                'response_body' => ['collection_id' => $collection->id],
            ]);

            return $collection;
        });
    }

    private function normalizeAmount(string $amount): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages(['amount' => ['El monto debe ser un decimal positivo con un máximo de dos decimales.']]);
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        $whole = ltrim($whole, '0') ?: '0';

        return $whole.'.'.str_pad($fraction, 2, '0');
    }
}
