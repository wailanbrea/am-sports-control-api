<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashBox;
use App\Models\CashMovement;
use App\Models\Company;
use App\Services\Accounting\CashBoxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashBoxController extends Controller
{
    public function indexBoxes(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $company = Company::query()->findOrFail($companyId);
        $boxes = CashBox::query()
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Cajas chicas obtenidas correctamente.',
            'data' => [
                'total_balance' => (string) $company->cash_balance,
                'currency_code' => $company->currency_code,
                'boxes' => $boxes,
            ],
        ]);
    }

    public function storeBox(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $company = Company::query()->findOrFail($companyId);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'initial_balance' => ['nullable', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $initialBalance = $data['initial_balance'] ?? '0.00';
        $isDefault = $data['is_default'] ?? false;

        if ($isDefault) {
            CashBox::query()->where('company_id', $companyId)->update(['is_default' => false]);
        }

        $box = CashBox::query()->create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'balance' => $initialBalance,
            'currency_code' => $company->currency_code,
            'is_default' => $isDefault,
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Caja chica creada correctamente.',
            'data' => $box,
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $company = Company::query()->findOrFail($companyId);
        $entries = CashMovement::query()
            ->with(['branch:id,code,name', 'createdBy:id,name,email'])
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
            'cash_box_id' => ['nullable', 'integer', 'exists:cash_boxes,id'],
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
