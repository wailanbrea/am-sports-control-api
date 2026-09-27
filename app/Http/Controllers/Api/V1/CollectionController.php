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
        $role = $request->user()->companies()->wherePivot('status', 'active')->first()?->pivot?->role ?? 'collector';

        $query = Collection::query()
            ->where('company_id', $companyId)
            ->orderByDesc('business_date')
            ->orderByDesc('id');

        if ($role === 'collector') {
            $assignedBranchIds = \App\Models\Branch::query()
                ->where('company_id', $companyId)
                ->where('collector_user_id', $request->user()->id)
                ->pluck('id');
            $query->whereIn('branch_id', $assignedBranchIds);
        }

        $collections = $query->get();

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

        $role = $request->user()->companies()->wherePivot('status', 'active')->first()?->pivot?->role ?? 'collector';
        if ($role === 'collector') {
            $branch = \App\Models\Branch::query()
                ->where('company_id', $companyId)
                ->findOrFail($data['branch_id']);

            if ((int) $branch->collector_user_id !== (int) $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tiene autorización para registrar cobros en esta banca porque no la tiene asignada.',
                ], 403);
            }
        }

        $collection = $service->record($request->user(), $companyId, $data, $key);

        return response()->json(['success' => true, 'message' => 'Cobro registrado correctamente.', 'data' => $collection], 201);
    }

    public function update(Request $request, int $collection, CollectionService $service): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'business_date' => ['required', 'date'],
            'payment_method' => ['required', 'in:cash,transfer,other'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $companyId = $this->activeCompanyId($request);
        $col = Collection::query()
            ->where('company_id', $companyId)
            ->findOrFail($collection);

        $updated = $service->update($request->user(), $companyId, $col, $data);

        return response()->json(['success' => true, 'message' => 'Cobro actualizado correctamente.', 'data' => $updated]);
    }

    private function activeCompanyId(Request $request): int
    {
        $companyId = $request->user()->companies()->wherePivot('status', 'active')->value('companies.id');

        abort_unless($companyId, 403, 'El usuario no tiene una empresa activa.');

        return $companyId;
    }
}
