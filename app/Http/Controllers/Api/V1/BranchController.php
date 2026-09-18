<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BranchController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $data = $request->validate($this->rules($companyId));

        $branch = Branch::query()->create([
            ...$data,
            'company_id' => $companyId,
            'created_by' => $request->user()->id,
            'current_balance' => '0.00',
        ]);

        return $this->respond('Banca creada correctamente.', $branch, 201);
    }

    public function update(Request $request, int $branch): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $branch = $this->branchForCompany($companyId, $branch);
        $data = $request->validate($this->rules($companyId, $branch->id));

        $branch->update($data);

        return $this->respond('Banca actualizada correctamente.', $branch->fresh());
    }

    public function destroy(Request $request, int $branch): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);

        DB::transaction(function () use ($companyId, $branch): void {
            $branch = Branch::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($branch);

            if ($branch->ledger()->exists()) {
                throw new ConflictHttpException('No se puede eliminar una banca con movimientos contables.');
            }

            if (bccomp((string) $branch->current_balance, '0.00', 2) !== 0) {
                throw new ConflictHttpException('No se puede eliminar una banca con saldo distinto de cero.');
            }

            $branch->delete();
        });

        return $this->respond('Banca eliminada correctamente.', null);
    }

    /** @return array<string, list<string|object>> */
    private function rules(int $companyId, ?int $branchId = null): array
    {
        $uniqueCode = Rule::unique('branches', 'code')->where('company_id', $companyId);

        if ($branchId !== null) {
            $uniqueCode->ignore($branchId);
        }

        return [
            'code' => ['required', 'string', 'max:100', $uniqueCode],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'route' => ['nullable', 'string', 'max:255'],
            'operator_name' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    private function activeCompanyId(Request $request): int
    {
        $companyId = $request->user()->companies()
            ->wherePivot('status', 'active')
            ->value('companies.id');

        abort_unless($companyId, 403, 'El usuario no tiene una empresa activa.');

        return $companyId;
    }

    private function branchForCompany(int $companyId, int $branchId): Branch
    {
        return Branch::query()
            ->where('company_id', $companyId)
            ->findOrFail($branchId);
    }

    private function respond(string $message, mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
