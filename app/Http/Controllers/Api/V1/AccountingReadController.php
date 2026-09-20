<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Advance;
use App\Models\Branch;
use App\Models\Collection;
use App\Models\Company;
use App\Models\LedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingReadController extends Controller
{
    public function branches(Request $request): JsonResponse
    {
        $branches = Branch::query()
            ->where('company_id', $this->activeCompanyId($request))
            ->orderBy('code')
            ->get();

        return $this->respond('Sucursales obtenidas correctamente.', $branches);
    }

    public function branch(Request $request, int $branch): JsonResponse
    {
        $branch = Branch::query()
            ->where('company_id', $this->activeCompanyId($request))
            ->with([
                'collections' => fn ($query) => $query->orderByDesc('business_date')->orderByDesc('id'),
                'advances' => fn ($query) => $query->orderByDesc('business_date')->orderByDesc('id'),
                'weeklySettlements' => fn ($query) => $query->orderByDesc('week_end')->orderByDesc('id'),
                'ledger' => fn ($query) => $query->orderByDesc('business_date')->orderByDesc('id'),
            ])
            ->findOrFail($branch);

        return $this->respond('Sucursal obtenida correctamente.', $branch);
    }

    public function ledger(Request $request): JsonResponse
    {
        $entries = LedgerEntry::query()
            ->where('company_id', $this->activeCompanyId($request))
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->get();

        return $this->respond('Libro mayor obtenido correctamente.', $entries);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $company = Company::query()->findOrFail($companyId);
        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->get(['id', 'current_balance']);

        $receivableTotal = '0.00';
        $branchCreditTotal = '0.00';
        $positiveCount = 0;
        $negativeCount = 0;
        $zeroCount = 0;

        foreach ($branches as $branch) {
            $balance = (string) $branch->current_balance;
            $cmp = bccomp($balance, '0', 2);
            if ($cmp > 0) {
                $receivableTotal = bcadd($receivableTotal, $balance, 2);
                $positiveCount++;
            } elseif ($cmp < 0) {
                $branchCreditTotal = bcadd($branchCreditTotal, bcmul($balance, '-1', 2), 2);
                $negativeCount++;
            } else {
                $zeroCount++;
            }
        }

        $now = \Illuminate\Support\Carbon::now();
        $startOfWeek = $now->copy()->startOfWeek()->toDateString();
        $endOfWeek = $now->copy()->endOfWeek()->toDateString();
        $startOfMonth = $now->copy()->startOfMonth()->toDateString();
        $endOfMonth = $now->copy()->endOfMonth()->toDateString();

        $moneyDeliveredWeek = (string) (\App\Models\MoneyDelivery::query()
            ->where('company_id', $companyId)
            ->whereBetween('business_date', [$startOfWeek, $endOfWeek])
            ->sum('delivered_amount') ?: '0.00');

        $moneyDeliveredMonth = (string) (\App\Models\MoneyDelivery::query()
            ->where('company_id', $companyId)
            ->whereBetween('business_date', [$startOfMonth, $endOfMonth])
            ->sum('delivered_amount') ?: '0.00');

        $collectedWeek = (string) (Collection::query()
            ->where('company_id', $companyId)
            ->whereBetween('business_date', [$startOfWeek, $endOfWeek])
            ->sum('amount') ?: '0.00');

        $collectedMonth = (string) (Collection::query()
            ->where('company_id', $companyId)
            ->whereBetween('business_date', [$startOfMonth, $endOfMonth])
            ->sum('amount') ?: '0.00');

        $recentActivity = LedgerEntry::query()
            ->where('company_id', $companyId)
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return $this->respond('Resumen obtenido correctamente.', [
            'receivable_total' => $receivableTotal,
            'branch_credit_total' => $branchCreditTotal,
            'net_position' => bcsub($receivableTotal, $branchCreditTotal, 2),
            'collections_total' => $this->total(Collection::class, $companyId),
            'advances_total' => $this->total(Advance::class, $companyId),
            'currency_code' => $company->currency_code ?: 'USD',
            'cash_balance' => $company->cash_balance,
            'active_branches_count' => $branches->count(),
            'positive_branches_count' => $positiveCount,
            'negative_branches_count' => $negativeCount,
            'zero_branches_count' => $zeroCount,
            'total_pending_to_collect' => $receivableTotal,
            'total_to_collect_next_monday' => $receivableTotal,
            'total_money_delivered_this_week' => $moneyDeliveredWeek,
            'total_money_delivered_this_month' => $moneyDeliveredMonth,
            'total_collected_this_week' => $collectedWeek,
            'total_collected_this_month' => $collectedMonth,
            'alerts' => [
                'negative_branches' => [
                    'count' => $negativeCount,
                    'total_required' => $branchCreditTotal,
                    'message' => "{$negativeCount} bancas requieren dinero.",
                ],
                'pending_collections' => [
                    'count' => $positiveCount,
                    'total_to_collect' => $receivableTotal,
                    'message' => "{$positiveCount} bancas con saldo por cobrar (Total: {$receivableTotal}).",
                ],
            ],
            'recent_activity' => $recentActivity,
        ]);
    }

    private function activeCompanyId(Request $request): int
    {
        $companyId = $request->user()->companies()
            ->wherePivot('status', 'active')
            ->value('companies.id');

        abort_unless($companyId, 403, 'El usuario no tiene una empresa activa.');

        return $companyId;
    }

    /** @param class-string<Collection|Advance> $model */
    private function total(string $model, int $companyId): string
    {
        $total = '0.00';

        foreach ($model::query()
            ->where('company_id', $companyId)
            ->where('status', 'confirmed')
            ->pluck('amount') as $amount) {
            $total = bcadd($total, $amount, 2);
        }

        return $total;
    }

    private function respond(string $message, mixed $data): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }
}
