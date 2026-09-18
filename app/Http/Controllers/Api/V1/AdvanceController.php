<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Advance;
use App\Services\Accounting\AdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $advances = Advance::query()
            ->where('company_id', $companyId)
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'message' => 'Adelantos obtenidos correctamente.', 'data' => $advances]);
    }

    public function store(Request $request, AdvanceService $service): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'business_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'payment_method' => ['nullable', 'in:cash,transfer,other'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $companyId = $this->activeCompanyId($request);
        $key = $request->header('Idempotency-Key');
        abort_unless($key && preg_match('/^[0-9a-fA-F-]{36}$/', $key), 422, 'Idempotency-Key es obligatorio.');

        $advance = $service->record($request->user(), $companyId, $data, $key);

        return response()->json(['success' => true, 'message' => 'Adelanto registrado correctamente.', 'data' => $advance], 201);
    }

    private function activeCompanyId(Request $request): int
    {
        $companyId = $request->user()->companies()->wherePivot('status', 'active')->value('companies.id');

        abort_unless($companyId, 403, 'El usuario no tiene una empresa activa.');

        return $companyId;
    }
}
