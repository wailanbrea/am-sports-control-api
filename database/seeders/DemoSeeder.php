<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Collection;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Accounting\AdvanceService;
use App\Services\Accounting\CashBoxService;
use App\Services\Accounting\CollectionService;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'demo@btmcontabilidad.local'],
            ['name' => 'Usuario Demo', 'password' => 'Demo12345!']
        );

        $company = Company::query()->where('name', 'A & M Sports LLC')->first()
            ?? Company::query()->where('name', 'Consorcio Demo')->first()
            ?? new Company;
        $company->fill([
            'name' => 'A & M Sports LLC',
            'currency_code' => 'USD',
            'timezone' => 'America/Santo_Domingo',
            'week_starts_on' => 1,
            'status' => 'active',
        ]);
        $company->save();

        $company->users()->syncWithoutDetaching([
            $user->id => ['role' => 'admin', 'status' => 'active'],
        ]);

        $branches = [
            ['code' => 'B-001', 'name' => 'Banca Centro', 'route' => 'Ruta Centro', 'operator_name' => 'Ana', 'opening' => '500.00', 'week_one' => '125.00', 'week_two' => '75.00'],
            ['code' => 'B-002', 'name' => 'Banca Norte', 'route' => 'Ruta Norte', 'operator_name' => 'Luis', 'opening' => '650.00', 'week_one' => '160.00', 'week_two' => '120.00'],
            ['code' => 'B-003', 'name' => 'Banca Sur', 'route' => 'Ruta Sur', 'operator_name' => 'Marta', 'opening' => '800.00', 'week_one' => '200.00', 'week_two' => '150.00'],
            ['code' => 'B-004', 'name' => 'Banca Este', 'route' => 'Ruta Este', 'operator_name' => 'Pedro', 'opening' => '450.00', 'week_one' => '90.00', 'week_two' => '60.00'],
            ['code' => 'B-005', 'name' => 'Banca Oeste', 'route' => 'Ruta Oeste', 'operator_name' => 'Carla', 'opening' => '720.00', 'week_one' => '180.00', 'week_two' => '100.00'],
            ['code' => 'B-006', 'name' => 'Banca Mercado', 'route' => 'Ruta Mercado', 'operator_name' => 'Diego', 'opening' => '900.00', 'week_one' => '225.00', 'week_two' => '175.00'],
            ['code' => 'B-007', 'name' => 'Banca Terminal', 'route' => 'Ruta Terminal', 'operator_name' => 'Rosa', 'opening' => '600.00', 'week_one' => '140.00', 'week_two' => '90.00'],
            ['code' => 'B-008', 'name' => 'Banca Universitaria', 'route' => 'Ruta Universidad', 'operator_name' => 'Jorge', 'opening' => '780.00', 'week_one' => '195.00', 'week_two' => '130.00'],
            ['code' => 'B-009', 'name' => 'Banca Industrial', 'route' => 'Ruta Industrial', 'operator_name' => 'Elena', 'opening' => '550.00', 'week_one' => '110.00', 'week_two' => '85.00'],
            ['code' => 'B-010', 'name' => 'Banca Comunitaria', 'route' => 'Ruta Comunitaria', 'operator_name' => 'Carlos', 'opening' => '1000.00', 'week_one' => '250.00', 'week_two' => '200.00'],
        ];

        foreach ($branches as $index => $data) {
            $branch = Branch::query()->updateOrCreate(
                ['company_id' => $company->id, 'code' => $data['code']],
                [
                    'name' => $data['name'],
                    'description' => 'Banca demo para pruebas contables en USD',
                    'route' => $data['route'],
                    'operator_name' => $data['operator_name'],
                    'status' => 'active',
                    'created_by' => $user->id,
                ]
            );

            $opening = LedgerEntry::query()->firstOrCreate(
                [
                    'company_id' => $company->id,
                    'source_type' => 'opening_balance',
                    'source_id' => $branch->id,
                ],
                [
                    'branch_id' => $branch->id,
                    'entry_type' => 'adjustment',
                    'signed_amount' => $data['opening'],
                    'balance_before' => '0.00',
                    'balance_after' => $data['opening'],
                    'business_date' => '2026-09-07',
                    'description' => 'Saldo inicial demo USD',
                    'created_by' => $user->id,
                ]
            );

            if ($opening->wasRecentlyCreated) {
                $branch->update(['current_balance' => $data['opening']]);
            }

            $collectionService = app(CollectionService::class);
            $collectionService->record($user, $company->id, [
                'branch_id' => $branch->id,
                'amount' => $data['week_one'],
                'business_date' => '2026-09-12',
                'payment_method' => 'cash',
                'reference' => 'DEMO-S1-'.$data['code'],
                'notes' => 'Cobro operativo de la primera semana',
            ], $this->demoKey($index, 1, 'collection'));

            $collectionService->record($user, $company->id, [
                'branch_id' => $branch->id,
                'amount' => $data['week_two'],
                'business_date' => '2026-09-17',
                'payment_method' => 'transfer',
                'reference' => 'DEMO-S2-'.$data['code'],
                'notes' => 'Cobro operativo de la segunda semana',
            ], $this->demoKey($index, 2, 'collection'));
        }

        $advanceService = app(AdvanceService::class);
        foreach ([
            ['branch' => 'B-004', 'amount' => '35.00', 'reason' => 'Adelanto operativo semana 2'],
            ['branch' => 'B-007', 'amount' => '25.00', 'reason' => 'Adelanto operativo semana 2'],
            ['branch' => 'B-010', 'amount' => '50.00', 'reason' => 'Adelanto operativo semana 2'],
        ] as $advanceIndex => $data) {
            $branch = Branch::query()->where('company_id', $company->id)->where('code', $data['branch'])->firstOrFail();
            $advanceService->record($user, $company->id, [
                'branch_id' => $branch->id,
                'amount' => $data['amount'],
                'business_date' => '2026-09-18',
                'reason' => $data['reason'],
                'payment_method' => 'cash',
                'reference' => 'DEMO-ADV-'.$data['branch'],
                'notes' => 'Movimiento demo para validar el historial',
            ], $this->demoKey($advanceIndex, 2, 'advance'));
        }

        $cashBoxService = app(CashBoxService::class);
        Collection::query()
            ->where('company_id', $company->id)
            ->where('payment_method', 'cash')
            ->get()
            ->each(function (Collection $collection) use ($cashBoxService, $company, $user): void {
                $cashBoxService->record(
                    $user,
                    $company->id,
                    [
                        'movement_type' => 'income',
                        'amount' => (string) $collection->amount,
                        'business_date' => $collection->business_date->format('Y-m-d'),
                        'reason' => 'Cobro recibido',
                        'branch_id' => $collection->branch_id,
                        'reference' => $collection->reference,
                        'notes' => $collection->notes,
                    ],
                    null,
                    null,
                    Collection::class,
                    $collection->id
                );
            });
    }

    private function demoKey(int $index, int $week, string $operation): string
    {
        $operationCode = $operation === 'advance' ? 2 : 1;
        $sequence = ($operationCode * 100) + ($index * 10) + $week;

        return '00000000-0000-4000-8000-'.str_pad((string) $sequence, 12, '0', STR_PAD_LEFT);
    }
}
