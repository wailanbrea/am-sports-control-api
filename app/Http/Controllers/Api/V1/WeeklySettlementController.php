<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WeeklySettlement;
use App\Services\Accounting\WeeklySettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WeeklySettlementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = WeeklySettlement::query()
            ->where('company_id', $this->activeCompanyId($request))
            ->orderByDesc('week_end')
            ->orderByDesc('id');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        return response()->json([
            'success' => true,
            'message' => 'Cuadres semanales obtenidos correctamente.',
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request, WeeklySettlementService $service): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'week_start' => ['required', 'date'],
            'week_end' => ['required', 'date', 'after_or_equal:week_start'],
            'sales_amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'prizes_amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'commission_rate' => ['required', 'string', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/'],
            'cash_delivered_amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $key = $request->header('Idempotency-Key');
        if (! $key || ! Str::isUuid($key)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Idempotency-Key es obligatorio y debe ser un UUID válido.'],
            ]);
        }

        $settlement = $service->record(
            $request->user(),
            $this->activeCompanyId($request),
            $data,
            $key,
        );

        return response()->json([
            'success' => true,
            'message' => 'Cuadre semanal registrado correctamente.',
            'data' => $settlement,
        ], 201);
    }

    private function activeCompanyId(Request $request): int
    {
        $companyId = $request->user()->companies()->wherePivot('status', 'active')->value('companies.id');

        abort_unless($companyId, 403, 'El usuario no tiene una empresa activa.');

        return $companyId;
    }
}
