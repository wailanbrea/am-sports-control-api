<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Services\Accounting\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $collections = Collection::query()
            ->where('company_id', $companyId)
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'message' => 'Cobros obtenidos correctamente.', 'data' => $collections]);
    }

    public function store(Request $request, CollectionService $service): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'business_date' => ['required', 'date'],
            'payment_method' => ['required', 'in:cash,transfer,other'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $companyId = $this->activeCompanyId($request);
        $key = $request->header('Idempotency-Key');
        abort_unless($key && preg_match('/^[0-9a-fA-F-]{36}$/', $key), 422, 'Idempotency-Key es obligatorio.');

        $collection = $service->record($request->user(), $companyId, $data, $key);

        return response()->json(['success' => true, 'message' => 'Cobro registrado correctamente.', 'data' => $collection], 201);
    }

    private function activeCompanyId(Request $request): int
    {
        $companyId = $request->user()->companies()->wherePivot('status', 'active')->value('companies.id');

        abort_unless($companyId, 403, 'El usuario no tiene una empresa activa.');

        return $companyId;
    }
}
