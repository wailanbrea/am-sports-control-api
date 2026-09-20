<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ManualResult;
use App\Services\Accounting\ManualResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManualResultController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $query = ManualResult::query()
            ->where('company_id', $companyId)
            ->with(['branch:id,code,name', 'creator:id,name']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->filled('business_date')) {
            $query->where('business_date', $request->string('business_date'));
        }

        $results = $query->orderByDesc('business_date')->orderByDesc('id')->get();

        return response()->json([
            'success' => true,
            'message' => 'Resultados obtenidos correctamente.',
            'data' => $results,
        ]);
    }

    public function store(Request $request, ManualResultService $service): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric'],
            'business_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $companyId = $this->activeCompanyId($request);
        $key = $request->header('Idempotency-Key');
        abort_unless($key && preg_match('/^[0-9a-fA-F-]{36}$/', $key), 422, 'Idempotency-Key es obligatorio.');

        $result = $service->record($request->user(), $companyId, $data, $key);

        return response()->json([
            'success' => true,
            'message' => 'Resultado registrado correctamente.',
            'data' => $result,
        ], 201);
    }

    private function activeCompanyId(Request $request): int
    {
        $companyId = $request->user()->companies()->wherePivot('status', 'active')->value('companies.id');
        abort_unless($companyId, 403, 'El usuario no tiene una empresa activa.');

        return $companyId;
    }
}
