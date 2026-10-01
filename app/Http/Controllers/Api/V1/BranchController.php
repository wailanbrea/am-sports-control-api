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
        $branch->load('collector:id,name,email');

        return $this->respond('Banca creada correctamente.', $branch, 201);
    }

    public function update(Request $request, int $branch): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $branch = $this->branchForCompany($companyId, $branch);
        $data = $request->validate($this->rules($companyId, $branch->id));

        $branch->update($data);

        return $this->respond('Banca actualizada correctamente.', $branch->fresh()->load('collector:id,name,email'));
    }

    public function destroy(Request $request, int $branch): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);

        DB::transaction(function () use ($companyId, $branch): void {
            $branch = Branch::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->findOrFail($branch);

            if (bccomp((string) $branch->current_balance, '0.00', 2) !== 0) {
                throw new ConflictHttpException('No se puede eliminar una banca con saldo distinto de cero ($' . number_format((float) $branch->current_balance, 2) . '). Ajuste el saldo a cero antes de eliminar.');
            }

            // Validar que no tenga transacciones financieras confirmadas reales
            $hasConfirmedCollections = \App\Models\Collection::where('branch_id', $branch->id)->where('status', 'confirmed')->exists();
            $hasConfirmedDeliveries = \App\Models\MoneyDelivery::where('branch_id', $branch->id)->where('status', 'confirmed')->exists();
            $hasSettlements = \App\Models\WeeklySettlement::where('branch_id', $branch->id)->exists();
            $hasConfirmedAdvances = \App\Models\Advance::where('branch_id', $branch->id)->where('status', 'confirmed')->exists();

            if ($hasConfirmedCollections || $hasConfirmedDeliveries || $hasSettlements || $hasConfirmedAdvances) {
                throw new ConflictHttpException('No se puede eliminar una banca con cobros, entregas o cuadres confirmados.');
            }

            // Limpieza segura en cascada de registros de prueba, cancelados o reversados
            \App\Models\ManualResult::where('branch_id', $branch->id)->delete();
            \App\Models\CashMovement::where('branch_id', $branch->id)->delete();
            \App\Models\LedgerEntry::where('branch_id', $branch->id)->update(['reversal_of_entry_id' => null]);
            \App\Models\LedgerEntry::where('branch_id', $branch->id)->delete();
            \App\Models\Collection::where('branch_id', $branch->id)->delete();
            \App\Models\MoneyDelivery::where('branch_id', $branch->id)->delete();
            \App\Models\Advance::where('branch_id', $branch->id)->delete();

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
            'phone' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:2000'],
            'route' => ['nullable', 'string', 'max:255'],
            'operator_name' => ['nullable', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'owner_phone' => ['nullable', 'string', 'max:50'],
            'owner_whatsapp' => ['nullable', 'string', 'max:50'],
            'owner_email' => ['nullable', 'email', 'max:255'],
            'owner_document' => ['nullable', 'string', 'max:50'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'manager_phone' => ['nullable', 'string', 'max:50'],
            'manager_whatsapp' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'sector' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'location_reference' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'collection_day' => ['nullable', 'string', 'max:50'],
            'commission_rate' => ['nullable', 'numeric', 'between:0,100'],
            'collector_user_id' => ['nullable', 'integer', 'exists:users,id'],
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

    public function assignCollector(Request $request, int $branch): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $branchModel = $this->branchForCompany($companyId, $branch);

        $data = $request->validate([
            'collector_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $branchModel->update(['collector_user_id' => $data['collector_user_id']]);

        return $this->respond('Cobrador asignado correctamente a la banca.', $branchModel);
    }

    public function absorbLoss(Request $request, int $branch, \App\Services\Accounting\WeeklyLossAbsorptionService $service): JsonResponse
    {
        $companyId = $this->activeCompanyId($request);
        $branchModel = $this->branchForCompany($companyId, $branch);

        $data = $request->validate([
            'amount' => ['nullable', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'business_date' => ['nullable', 'date'],
            'cash_box_id' => ['nullable', 'integer', 'exists:cash_boxes,id'],
            'deduct_cash_box' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $key = $request->header('Idempotency-Key');
        if (! $key || ! \Illuminate\Support\Str::isUuid($key)) {
            $key = (string) \Illuminate\Support\Str::uuid();
        }

        if (empty($data['amount'])) {
            if (bccomp((string) $branchModel->current_balance, '0.00', 2) < 0) {
                $data['amount'] = number_format(abs((float) $branchModel->current_balance), 2, '.', '');
            } else {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => ['La banca no tiene saldo negativo para absorber automáticamente. Especifique el monto.'],
                ]);
            }
        }

        $data['branch_id'] = $branchModel->id;

        $result = $service->absorb(
            $request->user(),
            $companyId,
            $data,
            $key,
        );

        return $this->respond('Pérdida semanal absorbida correctamente. La banca ha quedado en cero.', $result);
    }

    private function respond(string $message, mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
