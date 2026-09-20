<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MoneyDelivery;
use App\Services\Accounting\MoneyDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MoneyDeliveryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $query = MoneyDelivery::query()
            ->where('company_id', $companyId)
            ->with(['branch:id,code,name', 'creator:id,name']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        // Filtro por período
        $period = $request->input('period');
        $now = Carbon::now();

        if ($period === 'today') {
            $query->whereDate('business_date', $now->toDateString());
        } elseif ($period === 'this_week') {
            $query->whereBetween('business_date', [
                $now->copy()->startOfWeek()->toDateString(),
                $now->copy()->endOfWeek()->toDateString(),
            ]);
        } elseif ($period === 'this_month') {
            $query->whereBetween('business_date', [
                $now->copy()->startOfMonth()->toDateString(),
                $now->copy()->endOfMonth()->toDateString(),
            ]);
        } elseif ($period === 'last_month') {
            $lastMonth = $now->copy()->subMonth();
            $query->whereBetween('business_date', [
                $lastMonth->copy()->startOfMonth()->toDateString(),
                $lastMonth->copy()->endOfMonth()->toDateString(),
            ]);
        } elseif ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('business_date', [
                $request->input('date_from'),
                $request->input('date_to'),
            ]);
        }

        $deliveries = $query->orderByDesc('business_date')->orderByDesc('id')->get();

        // Calcular totales y agrupado por banca
        $totalDelivered = '0.00';
        $byBranch = [];

        foreach ($deliveries as $delivery) {
            $amt = (string) $delivery->delivered_amount;
            $totalDelivered = bcadd($totalDelivered, $amt, 2);

            $branchKey = $delivery->branch ? $delivery->branch->name : "Banca #{$delivery->branch_id}";
            if (!isset($byBranch[$branchKey])) {
                $byBranch[$branchKey] = [
                    'branch_id' => $delivery->branch_id,
                    'branch_name' => $branchKey,
                    'total' => '0.00',
                    'count' => 0,
                ];
            }
            $byBranch[$branchKey]['total'] = bcadd($byBranch[$branchKey]['total'], $amt, 2);
            $byBranch[$branchKey]['count']++;
        }

        return response()->json([
            'success' => true,
            'message' => 'Entregas de dinero obtenidas correctamente.',
            'data' => [
                'deliveries' => $deliveries,
                'total_delivered' => $totalDelivered,
                'by_branch' => array_values($byBranch),
            ],
        ]);
    }

    public function store(Request $request, MoneyDeliveryService $service): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'suggested_amount' => ['nullable', 'numeric'],
            'manual_result_id' => ['nullable', 'integer'],
            'business_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $companyId = $this->activeCompanyId($request);
        $key = $request->header('Idempotency-Key');
        abort_unless($key && preg_match('/^[0-9a-fA-F-]{36}$/', $key), 422, 'Idempotency-Key es obligatorio.');

        $delivery = $service->record($request->user(), $companyId, $data, $key);

        return response()->json([
            'success' => true,
            'message' => 'Dinero llevado registrado correctamente.',
            'data' => $delivery,
        ], 201);
    }

    private function activeCompanyId(Request $request): int
    {
        $companyId = $request->user()->companies()->wherePivot('status', 'active')->value('companies.id');
        abort_unless($companyId, 403, 'El usuario no tiene una empresa activa.');

        return $companyId;
    }
}
