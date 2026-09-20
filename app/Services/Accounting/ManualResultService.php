<?php

namespace App\Services\Accounting;

use App\Models\Branch;
use App\Models\IdempotencyKey;
use App\Models\LedgerEntry;
use App\Models\ManualResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualResultService
{
    public function record(User $user, int $companyId, array $data, string $idempotencyKey): ManualResult
    {
        return DB::transaction(function () use ($user, $companyId, $data, $idempotencyKey) {
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $stored = IdempotencyKey::query()
                ->where('company_id', $companyId)
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($stored) {
                if ($stored->operation !== 'manual_result.create' || $stored->request_hash !== $hash) {
                    throw ValidationException::withMessages(['idempotency_key' => ['La clave ya fue usada con otra operación.']]);
                }

                return ManualResult::query()->findOrFail($stored->response_body['manual_result_id']);
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
            $cmp = bccomp($amount, '0.00', 2);

            if ($cmp > 0) {
                $classification = 'positive';
                $entryType = 'result_positive';
                $signedAmount = $amount;
                $description = 'Ganancia operativa registrada: +' . number_format((float) $amount, 2);
            } elseif ($cmp < 0) {
                $classification = 'negative';
                $entryType = 'result_negative';
                $signedAmount = $amount; // es negativo
                $description = 'Pérdida operativa registrada: ' . number_format((float) $amount, 2);
            } else {
                $classification = 'zero';
                $entryType = 'result_neutral';
                $signedAmount = '0.00';
                $description = 'Resultado neutral (sin ganancia ni pérdida)';
            }

            $balanceBefore = (string) $branch->current_balance;
            $balanceAfter = bcadd($balanceBefore, $signedAmount, 2);

            $manualResult = ManualResult::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'amount' => $amount,
                'classification' => $classification,
                'business_date' => $data['business_date'],
                'notes' => $data['notes'] ?? null,
                'status' => 'confirmed',
                'idempotency_key' => $idempotencyKey,
                'created_by' => $user->id,
            ]);

            LedgerEntry::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'source_type' => ManualResult::class,
                'source_id' => $manualResult->id,
                'entry_type' => $entryType,
                'signed_amount' => $signedAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'business_date' => $data['business_date'],
                'description' => $description,
                'created_by' => $user->id,
            ]);

            $branch->update(['current_balance' => $balanceAfter]);

            IdempotencyKey::query()->create([
                'company_id' => $companyId,
                'user_id' => $user->id,
                'key' => $idempotencyKey,
                'operation' => 'manual_result.create',
                'request_hash' => $hash,
                'response_code' => 201,
                'response_body' => [
                    'manual_result_id' => $manualResult->id,
                    'balance_after' => $balanceAfter,
                ],
                'expires_at' => now()->addHours(24),
            ]);

            $manualResult->balance_after = $balanceAfter;
            $manualResult->requires_money_delivery = ($classification === 'negative');

            return $manualResult;
        });
    }

    private function normalizeAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
