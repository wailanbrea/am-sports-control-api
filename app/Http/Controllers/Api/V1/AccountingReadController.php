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
        $companyId = $this->activeCompanyId($request);
        $role = $request->user()->companies()->wherePivot('status', 'active')->first()?->pivot?->role ?? 'collector';

        $query = Branch::query()
            ->where('company_id', $companyId)
            ->with(['collector:id,name,email'])
            ->orderBy('code');

        if ($role === 'collector') {
            $query->where('collector_user_id', $request->user()->id);
        } elseif ($request->filled('collector_id')) {
            $query->where('collector_user_id', $request->integer('collector_id'));
        }

        $branches = $query->get();

        return $this->respond('Sucursales obtenidas correctamente.', $branches);
    }

    public function branch(Request $request, int $branch): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $role = $request->user()->companies()->wherePivot('status', 'active')->first()?->pivot?->role ?? 'collector';

        $branchModel = Branch::query()
            ->where('company_id', $companyId)
            ->with([
                'collector:id,name,email',
                'collections' => fn ($query) => $query->orderByDesc('business_date')->orderByDesc('id'),
                'advances' => fn ($query) => $query->orderByDesc('business_date')->orderByDesc('id'),
                'weeklySettlements' => fn ($query) => $query->orderByDesc('week_end')->orderByDesc('id'),
                'ledger' => fn ($query) => $query->orderByDesc('business_date')->orderByDesc('id'),
            ])
            ->findOrFail($branch);

        if ($role === 'collector' && (int) $branchModel->collector_user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'No tiene autorización para consultar esta banca.',
            ], 403);
        }

        return $this->respond('Sucursal obtenida correctamente.', $branchModel);
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
        $role = $request->user()->companies()->wherePivot('status', 'active')->first()?->pivot?->role ?? 'collector';
        $isAdmin = ($role === 'admin');

        $branchQuery = Branch::query()
            ->where('company_id', $companyId)
            ->where('status', 'active');

        if (! $isAdmin) {
            $branchQuery->where('collector_user_id', $request->user()->id);
        }

        $branches = $branchQuery->get(['id', 'current_balance']);
        $branchIds = $branches->pluck('id');

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

        $moneyDeliveredWeek = '0.00';
        $moneyDeliveredMonth = '0.00';
        if ($isAdmin) {
            $moneyDeliveredWeek = (string) (\App\Models\MoneyDelivery::query()
                ->where('company_id', $companyId)
                ->whereBetween('business_date', [$startOfWeek, $endOfWeek])
                ->sum('delivered_amount') ?: '0.00');

            $moneyDeliveredMonth = (string) (\App\Models\MoneyDelivery::query()
                ->where('company_id', $companyId)
                ->whereBetween('business_date', [$startOfMonth, $endOfMonth])
                ->sum('delivered_amount') ?: '0.00');
        }

        $collectionsWeekQuery = Collection::query()
            ->where('company_id', $companyId)
            ->whereBetween('business_date', [$startOfWeek, $endOfWeek]);
        $collectionsMonthQuery = Collection::query()
            ->where('company_id', $companyId)
            ->whereBetween('business_date', [$startOfMonth, $endOfMonth]);
        $recentActivityQuery = LedgerEntry::query()
            ->where('company_id', $companyId);

        if (! $isAdmin) {
            $collectionsWeekQuery->whereIn('branch_id', $branchIds);
            $collectionsMonthQuery->whereIn('branch_id', $branchIds);
            $recentActivityQuery->whereIn('branch_id', $branchIds);
        }

        $collectedWeek = (string) ($collectionsWeekQuery->sum('amount') ?: '0.00');
        $collectedMonth = (string) ($collectionsMonthQuery->sum('amount') ?: '0.00');

        $recentActivity = $recentActivityQuery
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $totalCollections = '0.00';
        $collectionsTotalQuery = Collection::query()
            ->where('company_id', $companyId)
            ->where('status', 'confirmed');
        if (! $isAdmin) {
            $collectionsTotalQuery->whereIn('branch_id', $branchIds);
        }
        foreach ($collectionsTotalQuery->pluck('amount') as $amount) {
            $totalCollections = bcadd($totalCollections, $amount, 2);
        }

        return $this->respond('Resumen obtenido correctamente.', [
            'receivable_total' => $receivableTotal,
            'branch_credit_total' => $isAdmin ? $branchCreditTotal : '0.00',
            'net_position' => $isAdmin ? bcsub($receivableTotal, $branchCreditTotal, 2) : $receivableTotal,
            'collections_total' => $totalCollections,
            'advances_total' => $isAdmin ? $this->total(Advance::class, $companyId) : '0.00',
            'currency_code' => $company->currency_code ?: 'USD',
            'cash_balance' => $isAdmin ? $company->cash_balance : '0.00',
            'active_branches_count' => $branches->count(),
            'positive_branches_count' => $positiveCount,
            'negative_branches_count' => $isAdmin ? $negativeCount : 0,
            'zero_branches_count' => $zeroCount,
            'total_pending_to_collect' => $receivableTotal,
            'total_to_collect_next_monday' => $receivableTotal,
            'total_money_delivered_this_week' => $moneyDeliveredWeek,
            'total_money_delivered_this_month' => $moneyDeliveredMonth,
            'total_collected_this_week' => $collectedWeek,
            'total_collected_this_month' => $collectedMonth,
            'alerts' => [
                'negative_branches' => [
                    'count' => $isAdmin ? $negativeCount : 0,
                    'total_required' => $isAdmin ? $branchCreditTotal : '0.00',
                    'message' => $isAdmin ? "{$negativeCount} bancas requieren dinero." : '',
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
