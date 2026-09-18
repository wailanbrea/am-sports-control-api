<?php

namespace App\Services\Accounting;

use App\Models\Advance;
use App\Models\Branch;
use App\Models\Collection;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerReversalService
{
    public function reverse(User $user, int $companyId, int $entryId, array $data): LedgerEntry
    {
        return DB::transaction(function () use ($user, $companyId, $entryId, $data) {
            $entry = LedgerEntry::query()->where('company_id', $companyId)->whereKey($entryId)->lockForUpdate()->firstOrFail();
            if ($entry->reversal_of_entry_id !== null) {
                throw ValidationException::withMessages(['ledger_entry' => ['No se puede revertir un asiento de reverso.']]);
            }
            if ($entry->reversed_at !== null || LedgerEntry::query()->where('reversal_of_entry_id', $entry->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['ledger_entry' => ['El asiento ya fue revertido.']]);
            }

            $branch = Branch::query()->where('company_id', $companyId)->whereKey($entry->branch_id)->lockForUpdate()->firstOrFail();
            $balanceBefore = (string) $branch->current_balance;
            $signedAmount = bcmul((string) $entry->signed_amount, '-1', 2);
            $balanceAfter = bcadd($balanceBefore, $signedAmount, 2);
            $reversal = LedgerEntry::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'source_type' => LedgerEntry::class,
                'source_id' => $entry->id,
                'entry_type' => 'reversal',
                'signed_amount' => $signedAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'business_date' => $data['business_date'],
                'description' => 'Reverso: '.$data['reason'],
                'created_by' => $user->id,
                'reversal_of_entry_id' => $entry->id,
            ]);

            $entry->update(['reversed_at' => now()]);
            $branch->update(['current_balance' => $balanceAfter]);
            $this->markSourceReversed($entry);

            return $reversal;
        });
    }

    private function markSourceReversed(LedgerEntry $entry): void
    {
        $sourceModels = [Collection::class, Advance::class];
        if (in_array($entry->source_type, $sourceModels, true)) {
            $entry->source_type::query()->whereKey($entry->source_id)->update(['status' => 'reversed', 'reversed_at' => now()]);
        }
    }
}
