<?php

namespace App\Services\Accounting;

use App\Models\Advance;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Collection;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\ManualResult;
use App\Models\MoneyDelivery;
use App\Models\User;
use App\Models\WeeklySettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerReversalService
{
    public function reverse(User $user, int $companyId, int $entryId, array $data): LedgerEntry
    {
        return DB::transaction(function () use ($user, $companyId, $entryId, $data) {
            $entry = LedgerEntry::query()
                ->where('company_id', $companyId)
                ->whereKey($entryId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($entry->reversal_of_entry_id !== null) {
                throw ValidationException::withMessages(['ledger_entry' => ['No se puede revertir un asiento de reverso.']]);
            }
            if ($entry->reversed_at !== null || LedgerEntry::query()->where('reversal_of_entry_id', $entry->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['ledger_entry' => ['El asiento ya fue revertido.']]);
            }

            $branch = Branch::query()
                ->where('company_id', $companyId)
                ->whereKey($entry->branch_id)
                ->lockForUpdate()
                ->firstOrFail();

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
                'business_date' => $data['business_date'] ?? now()->toDateString(),
                'description' => 'Reverso: ' . ($data['reason'] ?? 'Corrección'),
                'created_by' => $user->id,
                'reversal_of_entry_id' => $entry->id,
            ]);

            $entry->update(['reversed_at' => now()]);
            $branch->update(['current_balance' => $balanceAfter]);

            // Revertir cualquier asiento hermano si proviene de la misma entrega o cobro
            $this->reverseSiblingLedgerEntries($user, $companyId, $branch, $entry, $data);

            // Revertir el modelo de origen
            $this->markSourceReversed($entry);

            // Revertir movimientos de caja chica y saldos de efectivo correspondientes
            $this->reverseCashMovements($user, $companyId, $entry, $reversal, $data);

            return $reversal;
        });
    }

    private function reverseSiblingLedgerEntries(
        User $user,
        int $companyId,
        Branch $branch,
        LedgerEntry $entry,
        array $data
    ): void {
        if (in_array($entry->source_type, [MoneyDelivery::class, 'App\Models\MoneyDelivery'], true)) {
            $siblings = LedgerEntry::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branch->id)
                ->where('source_type', $entry->source_type)
                ->where('source_id', $entry->source_id)
                ->where('id', '!=', $entry->id)
                ->whereNull('reversed_at')
                ->whereNull('reversal_of_entry_id')
                ->lockForUpdate()
                ->get();

            foreach ($siblings as $sibling) {
                $curBranch = Branch::query()->whereKey($branch->id)->lockForUpdate()->first();
                $bBefore = (string) $curBranch->current_balance;
                $sAmount = bcmul((string) $sibling->signed_amount, '-1', 2);
                $bAfter = bcadd($bBefore, $sAmount, 2);

                LedgerEntry::query()->create([
                    'company_id' => $companyId,
                    'branch_id' => $branch->id,
                    'source_type' => LedgerEntry::class,
                    'source_id' => $sibling->id,
                    'entry_type' => 'reversal',
                    'signed_amount' => $sAmount,
                    'balance_before' => $bBefore,
                    'balance_after' => $bAfter,
                    'business_date' => $data['business_date'] ?? now()->toDateString(),
                    'description' => 'Reverso automático por compensación: ' . ($data['reason'] ?? ''),
                    'created_by' => $user->id,
                    'reversal_of_entry_id' => $sibling->id,
                ]);

                $sibling->update(['reversed_at' => now()]);
                $curBranch->update(['current_balance' => $bAfter]);
            }
        }
    }

    private function reverseCashMovements(
        User $user,
        int $companyId,
        LedgerEntry $entry,
        LedgerEntry $reversal,
        array $data
    ): void {
        $cashMovements = collect();

        if (in_array($entry->source_type, [CashMovement::class, 'App\Models\CashMovement'], true)) {
            $movement = CashMovement::query()
                ->where('company_id', $companyId)
                ->whereKey($entry->source_id)
                ->lockForUpdate()
                ->first();
            if ($movement) {
                $cashMovements->push($movement);
            }
        } else {
            $movements = CashMovement::query()
                ->where('company_id', $companyId)
                ->where('source_type', $entry->source_type)
                ->where('source_id', $entry->source_id)
                ->lockForUpdate()
                ->get();
            foreach ($movements as $m) {
                $cashMovements->push($m);
            }
        }

        if ($cashMovements->isEmpty()) {
            return;
        }

        $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();

        foreach ($cashMovements as $cashMovement) {
            $alreadyReversed = CashMovement::query()
                ->where('company_id', $companyId)
                ->where('source_type', LedgerEntry::class)
                ->where('source_id', $reversal->id)
                ->where('reference', 'LIKE', '%#' . $cashMovement->id . '%')
                ->exists();

            if ($alreadyReversed) {
                continue;
            }

            // Invertir el monto firmado original
            // Si signed_amount era -516.00 (salida), el reverso es +516.00 (devolución a caja)
            // Si signed_amount era +500.00 (ingreso), el reverso es -500.00 (salida de caja)
            $reversalSignedAmount = bcmul((string) $cashMovement->signed_amount, '-1', 2);
            $reversalAbsAmount = number_format(abs((float) $reversalSignedAmount), 2, '.', '');
            $movementType = bccomp($reversalSignedAmount, '0.00', 2) > 0 ? 'income' : 'expense';

            $companyBefore = (string) $company->cash_balance;
            $companyAfter = bcadd($companyBefore, $reversalSignedAmount, 2);
            $company->update(['cash_balance' => $companyAfter]);

            $cashBox = null;
            if ($cashMovement->cash_box_id) {
                $cashBox = CashBox::query()
                    ->where('company_id', $companyId)
                    ->whereKey($cashMovement->cash_box_id)
                    ->lockForUpdate()
                    ->first();
            }
            if (! $cashBox) {
                $cashBox = CashBox::query()
                    ->where('company_id', $companyId)
                    ->where('is_default', true)
                    ->lockForUpdate()
                    ->first()
                    ?? CashBox::query()->where('company_id', $companyId)->first();
            }

            $boxBefore = $cashBox ? (string) $cashBox->balance : $companyBefore;
            $boxAfter = $cashBox ? bcadd($boxBefore, $reversalSignedAmount, 2) : $companyAfter;
            if ($cashBox) {
                $cashBox->update(['balance' => $boxAfter]);
            }

            CashMovement::query()->create([
                'company_id' => $companyId,
                'cash_box_id' => $cashBox?->id,
                'branch_id' => $entry->branch_id,
                'movement_type' => $movementType,
                'amount' => $reversalAbsAmount,
                'signed_amount' => $reversalSignedAmount,
                'balance_before' => $boxBefore,
                'balance_after' => $boxAfter,
                'business_date' => $data['business_date'] ?? now()->toDateString(),
                'reason' => 'Reverso de caja por anulación de asiento #' . $entry->id . ': ' . ($data['reason'] ?? ''),
                'reference' => 'Reverso de movimiento #' . $cashMovement->id,
                'notes' => 'Generado automáticamente por el reverso del asiento #' . $entry->id,
                'source_type' => LedgerEntry::class,
                'source_id' => $reversal->id,
                'created_by' => $user->id,
            ]);
        }
    }

    private function markSourceReversed(LedgerEntry $entry): void
    {
        $sourceType = $entry->source_type;
        $sourceId = $entry->source_id;

        if (in_array($sourceType, [Collection::class, 'App\Models\Collection', Advance::class, 'App\Models\Advance'], true)) {
            $sourceType::query()->whereKey($sourceId)->update(['status' => 'reversed', 'reversed_at' => now()]);
        } elseif (in_array($sourceType, [MoneyDelivery::class, 'App\Models\MoneyDelivery'], true)) {
            MoneyDelivery::query()->whereKey($sourceId)->update(['status' => 'cancelled']);
        } elseif (in_array($sourceType, [WeeklySettlement::class, 'App\Models\WeeklySettlement'], true)) {
            WeeklySettlement::query()->whereKey($sourceId)->update(['status' => 'cancelled']);
        } elseif (in_array($sourceType, [ManualResult::class, 'App\Models\ManualResult'], true)) {
            ManualResult::query()->whereKey($sourceId)->update(['status' => 'cancelled']);
        }
    }
}
