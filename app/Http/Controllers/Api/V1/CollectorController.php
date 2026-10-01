<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class CollectorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);

        $assignedBranchCounts = Branch::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereNotNull('collector_user_id')
            ->select('collector_user_id', DB::raw('count(*) as total'))
            ->groupBy('collector_user_id')
            ->pluck('total', 'collector_user_id');

        $collectors = User::query()
            ->whereHas('companies', function ($query) use ($companyId) {
                $query->where('companies.id', $companyId);
            })
            ->with(['companies' => function ($query) use ($companyId) {
                $query->where('companies.id', $companyId);
            }])
            ->get()
            ->map(function (User $user) use ($assignedBranchCounts) {
                $pivot = $user->companies->first()?->pivot;
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $pivot?->role ?? 'collector',
                    'status' => $pivot?->status ?? 'active',
                    'assigned_branches_count' => (int) ($assignedBranchCounts[$user->id] ?? 0),
                    'created_at' => $user->created_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Cobradores y usuarios obtenidos correctamente.',
            'data' => $collectors,
        ]);
    }

    public function assignBranches(Request $request, int $collector): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $user = User::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))
            ->findOrFail($collector);

        $data = $request->validate([
            'branch_ids' => ['present', 'array'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
        ]);

        $selectedIds = array_map('intval', $data['branch_ids']);

        DB::transaction(function () use ($companyId, $user, $selectedIds) {
            // Desasignar las bancas de este cobrador que no estén en selectedIds
            Branch::query()
                ->where('company_id', $companyId)
                ->where('collector_user_id', $user->id)
                ->whereNotIn('id', $selectedIds)
                ->update(['collector_user_id' => null]);

            // Asignar las bancas seleccionadas a este cobrador
            if (!empty($selectedIds)) {
                Branch::query()
                    ->where('company_id', $companyId)
                    ->whereIn('id', $selectedIds)
                    ->update(['collector_user_id' => $user->id]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Bancas asignadas correctamente al cobrador.',
            'data' => [
                'collector_id' => $user->id,
                'assigned_count' => count($selectedIds),
                'branch_ids' => $selectedIds,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['nullable', 'string', 'in:collector,admin'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $role = $data['role'] ?? 'collector';
        $status = $data['status'] ?? 'active';

        $user = DB::transaction(function () use ($data, $companyId, $role, $status) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $user->companies()->attach($companyId, [
                'role' => $role,
                'status' => $status,
            ]);

            return $user;
        });

        return response()->json([
            'success' => true,
            'message' => 'Cobrador creado correctamente.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $role,
                'status' => $status,
            ],
        ], 201);
    }

    public function update(Request $request, int $collector): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);

        $user = User::query()
            ->whereHas('companies', function ($query) use ($companyId) {
                $query->where('companies.id', $companyId);
            })
            ->findOrFail($collector);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['sometimes', 'string', 'in:collector,admin'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);

        DB::transaction(function () use ($user, $data, $companyId) {
            $userFields = [];
            if (isset($data['name'])) $userFields['name'] = $data['name'];
            if (isset($data['email'])) $userFields['email'] = $data['email'];
            if (!empty($data['password'])) $userFields['password'] = Hash::make($data['password']);

            if (!empty($userFields)) {
                $user->update($userFields);
            }

            $pivotFields = [];
            if (isset($data['role'])) $pivotFields['role'] = $data['role'];
            if (isset($data['status'])) $pivotFields['status'] = $data['status'];

            if (!empty($pivotFields)) {
                $user->companies()->updateExistingPivot($companyId, $pivotFields);
            }
        });

        $updatedPivot = $user->fresh()->companies()->where('companies.id', $companyId)->first()?->pivot;

        return response()->json([
            'success' => true,
            'message' => 'Cobrador actualizado correctamente.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $updatedPivot?->role ?? 'collector',
                'status' => $updatedPivot?->status ?? 'active',
            ],
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
}
