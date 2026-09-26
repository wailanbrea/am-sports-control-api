<?php

namespace App\Services\Accounting;

use App\Models\Branch;
use App\Models\Collection;
use App\Models\IdempotencyKey;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WeeklySettlement;
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

            $latestSettlement = WeeklySettlement::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branch->id)
                ->orderByDesc('week_end')
                ->orderByDesc('id')
                ->first();
            if ($latestSettlement) {
                $latestSettlement->update([
                    'status' => bccomp($balanceAfter, '0.00', 2) === 0 ? 'paid' : 'partially_paid',
                ]);
            }

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

    public function update(User $user, int $companyId, Collection $collection, array $data): Collection
    {
        return DB::transaction(function () use ($user, $companyId, $collection, $data) {
            if ($collection->company_id !== $companyId) {
                abort(404, 'Cobro no encontrado.');
            }

            if ($collection->status !== 'confirmed') {
                throw ValidationException::withMessages(['status' => ['Solo se pueden editar cobros confirmados.']]);
            }

            $branch = Branch::query()
                ->where('company_id', $companyId)
                ->whereKey($collection->branch_id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldAmount = (string) $collection->amount;
            $newAmount = $this->normalizeAmount($data['amount']);
            $oldPaymentMethod = $collection->payment_method;
            $newPaymentMethod = $data['payment_method'];
            $newBusinessDate = $data['business_date'];

            // Delta in branch balance:
            // Old collection reduced debt by $oldAmount.
            // If new amount is higher (collected more), debt decreases further by delta.
            // If new amount is lower (collected less), debt increases by -delta.
            // Therefore: new_balance = current_balance - (newAmount - oldAmount).
            $delta = bcsub($newAmount, $oldAmount, 2);
            $newBranchBalance = bcsub((string) $branch->current_balance, $delta, 2);

            $branch->update(['current_balance' => $newBranchBalance]);

            // Update collection record
            $collection->update([
                'amount' => $newAmount,
                'business_date' => $newBusinessDate,
                'payment_method' => $newPaymentMethod,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Update corresponding LedgerEntry
            $ledgerEntry = LedgerEntry::query()
                ->where('company_id', $companyId)
                ->where('source_type', Collection::class)
                ->where('source_id', $collection->id)
                ->first();

            if ($ledgerEntry) {
                $newBalanceAfter = bcsub((string) $ledgerEntry->balance_before, $newAmount, 2);
                $ledgerEntry->update([
                    'signed_amount' => bcmul($newAmount, '-1', 2),
                    'balance_after' => $newBalanceAfter,
                    'business_date' => $newBusinessDate,
                    'description' => 'Cobro modificado' . (! empty($data['reference']) ? ': '.$data['reference'] : ''),
                ]);
            }

            // Adjust CashBox if payment method was or is cash
            $cashMovement = \App\Models\CashMovement::query()
                ->where('company_id', $companyId)
                ->where('source_type', Collection::class)
                ->where('source_id', $collection->id)
                ->first();

            $company = \App\Models\Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();

            if ($oldPaymentMethod === 'cash' && $newPaymentMethod === 'cash') {
                if ($cashMovement) {
                    $newCashBalance = bcadd((string) $company->cash_balance, $delta, 2);
                    $cashMovement->update([
                        'amount' => $newAmount,
                        'signed_amount' => $newAmount,
                        'balance_after' => bcadd((string) $cashMovement->balance_before, $newAmount, 2),
                        'business_date' => $newBusinessDate,
                        'reference' => $data['reference'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ]);
                    $company->update(['cash_balance' => $newCashBalance]);
                }
            } elseif ($oldPaymentMethod === 'cash' && $newPaymentMethod !== 'cash') {
                if ($cashMovement) {
                    $newCashBalance = bcsub((string) $company->cash_balance, $oldAmount, 2);
                    $cashMovement->delete();
                    $company->update(['cash_balance' => $newCashBalance]);
                }
            } elseif ($oldPaymentMethod !== 'cash' && $newPaymentMethod === 'cash') {
                $balanceBefore = (string) $company->cash_balance;
                $balanceAfter = bcadd($balanceBefore, $newAmount, 2);
                \App\Models\CashMovement::query()->create([
                    'company_id' => $companyId,
                    'branch_id' => $branch->id,
                    'movement_type' => 'income',
                    'amount' => $newAmount,
                    'signed_amount' => $newAmount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'business_date' => $newBusinessDate,
                    'reason' => 'Cobro modificado a efectivo',
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'source_type' => Collection::class,
                    'source_id' => $collection->id,
                    'created_by' => $user->id,
                ]);
                $company->update(['cash_balance' => $balanceAfter]);
            }

            return $collection->fresh();
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

