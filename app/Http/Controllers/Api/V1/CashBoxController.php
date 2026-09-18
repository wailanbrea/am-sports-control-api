<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\Company;
use App\Services\Accounting\CashBoxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashBoxController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $company = Company::query()->findOrFail($companyId);
        $entries = CashMovement::query()
            ->where('company_id', $companyId)
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Caja obtenida correctamente.',
            'data' => [
                'currency_code' => $company->currency_code,
                'current_balance' => $company->cash_balance,
                'entries' => $entries,
            ],
        ]);
    }

    public function income(Request $request, CashBoxService $service): JsonResponse
    {
        return $this->store($request, $service, 'income', 'cash_box.income');
    }

    public function expense(Request $request, CashBoxService $service): JsonResponse
    {
        return $this->store($request, $service, 'expense', 'cash_box.expense');
    }

    public function branchTransfer(Request $request, CashBoxService $service): JsonResponse
    {
        return $this->store($request, $service, 'branch_transfer', 'cash_box.branch_transfer', true);
    }

    private function store(
        Request $request,
        CashBoxService $service,
        string $movementType,
        string $operation,
        bool $requiresBranch = false,
    ): JsonResponse {
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'business_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
            'branch_id' => [$requiresBranch ? 'required' : 'nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $companyId = $this->activeCompanyId($request);
        $key = $request->header('Idempotency-Key');

        if (! $key || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => ['Idempotency-Key es obligatorio y debe ser un UUID válido.']]);
        }

        $data['movement_type'] = $movementType;
        $movement = $service->record($request->user(), $companyId, $data, $operation, $key);

        return response()->json([
            'success' => true,
            'message' => 'Movimiento de caja registrado correctamente.',
            'data' => $movement,
        ], 201);
    }

    private function activeCompanyId(Request $request): int
    {
        $companyId = $request->user()->companies()->wherePivot('status', 'active')->value('companies.id');

        abort_unless($companyId, 403, 'El usuario no tiene una empresa activa.');

        return $companyId;
    }
}
