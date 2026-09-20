<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\ManualResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MoneyDeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_record_money_delivery_to_compensate_negative_balance(): void
    {
        [$user, $company] = $this->companyContext('5000.00');

        // Banca en pérdida -$1,663.00
        $branch = $this->branch($company, 'B-02', '-1663.00');

        $result = ManualResult::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'amount' => '-1663.00',
            'classification' => 'negative',
            'business_date' => '2026-09-20',
            'status' => 'confirmed',
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => $user->id,
        ]);

        $uuid = (string) Str::uuid();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', $uuid)
            ->postJson('/api/v1/money-deliveries', [
                'branch_id' => $branch->id,
                'manual_result_id' => $result->id,
                'suggested_amount' => '1663.00',
                'amount' => '1663.00',
                'business_date' => '2026-09-20',
                'reason' => 'Cubrir pérdida operativa domingo',
                'notes' => 'Entregado en efectivo',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        // El balance de la banca debe quedar en 0.00 (-1663 + 1663)
        $this->assertEquals('0.00', (string) $branch->fresh()->current_balance);

        // Caja de la empresa debe haberse reducido en 1663 (5000 - 1663 = 3337)
        $this->assertEquals('3337.00', (string) $company->fresh()->cash_balance);

        $this->assertDatabaseHas('ledger_entries', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'entry_type' => 'money_delivery',
            'signed_amount' => '1663.00',
            'balance_after' => '0.00',
        ]);

        $this->assertDatabaseHas('cash_movements', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'movement_type' => 'branch_delivery',
            'amount' => '1663.00',
        ]);
    }

    public function test_can_query_money_deliveries_summary_by_branch(): void
    {
        [$user, $company] = $this->companyContext('10000.00');

        $banca2 = $this->branch($company, 'B-02', '-1663.00');
        $banca4 = $this->branch($company, 'B-04', '-2800.00');

        $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/money-deliveries', [
            'branch_id' => $banca2->id,
            'amount' => '1663.00',
            'business_date' => '2026-09-20',
            'reason' => 'Pérdida',
        ]);

        $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/money-deliveries', [
            'branch_id' => $banca4->id,
            'amount' => '2800.00',
            'business_date' => '2026-09-20',
            'reason' => 'Pérdida',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/money-deliveries');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_delivered', '4463.00');
    }

    private function companyContext(string $cashBalance = '0.00'): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create(['name' => 'BTM', 'cash_balance' => $cashBalance]);
        $user->companies()->attach($company, ['role' => 'admin', 'status' => 'active']);

        return [$user, $company];
    }

    private function branch(Company $company, string $code, string $balance = '0.00'): Branch
    {
        return Branch::query()->create([
            'company_id' => $company->id,
            'code' => $code,
            'name' => 'Banca '.$code,
            'current_balance' => $balance,
        ]);
    }
}
