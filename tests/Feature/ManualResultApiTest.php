<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualResultApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_record_positive_result_and_increase_balance(): void
    {
        [$user, $company] = $this->companyContext();
        $branch = $this->branch($company, 'B-01', '0.00');

        $uuid = (string) Str::uuid();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', $uuid)
            ->postJson('/api/v1/results', [
                'branch_id' => $branch->id,
                'amount' => '1400.00',
                'business_date' => '2026-09-20',
                'notes' => 'Ganancia domingo',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.classification', 'positive')
            ->assertJsonPath('data.requires_money_delivery', false);

        $this->assertEquals('1400.00', (string) $branch->fresh()->current_balance);
        $this->assertDatabaseHas('manual_results', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'amount' => '1400.00',
            'classification' => 'positive',
        ]);
        $this->assertDatabaseHas('ledger_entries', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'entry_type' => 'result_positive',
            'signed_amount' => '1400.00',
            'balance_after' => '1400.00',
        ]);
    }

    public function test_can_record_negative_result_and_decrease_balance(): void
    {
        [$user, $company] = $this->companyContext();
        $branch = $this->branch($company, 'B-01', '0.00');

        $uuid = (string) Str::uuid();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', $uuid)
            ->postJson('/api/v1/results', [
                'branch_id' => $branch->id,
                'amount' => '-1663.00',
                'business_date' => '2026-09-20',
                'notes' => 'Pérdida domingo',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.classification', 'negative')
            ->assertJsonPath('data.requires_money_delivery', true);

        $this->assertEquals('-1663.00', (string) $branch->fresh()->current_balance);
        $this->assertDatabaseHas('ledger_entries', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'entry_type' => 'result_negative',
            'signed_amount' => '-1663.00',
            'balance_after' => '-1663.00',
        ]);
    }

    public function test_balances_compensate_automatically(): void
    {
        [$user, $company] = $this->companyContext();

        // Caso 1: Saldo previo +400, nueva pérdida -700 => Saldo -300
        $branch = $this->branch($company, 'B-01', '400.00');

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/results', [
                'branch_id' => $branch->id,
                'amount' => '-700.00',
                'business_date' => '2026-09-20',
            ]);

        $response->assertStatus(201);
        $this->assertEquals('-300.00', (string) $branch->fresh()->current_balance);

        // Caso 2: Saldo -500, nueva ganancia +1400 => Saldo +900
        $branch2 = $this->branch($company, 'B-02', '-500.00');

        $response2 = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/results', [
                'branch_id' => $branch2->id,
                'amount' => '1400.00',
                'business_date' => '2026-09-20',
            ]);

        $response2->assertStatus(201);
        $this->assertEquals('900.00', (string) $branch2->fresh()->current_balance);
    }

    public function test_idempotent_result_retry_does_not_duplicate(): void
    {
        [$user, $company] = $this->companyContext();
        $branch = $this->branch($company, 'B-01', '0.00');

        $uuid = (string) Str::uuid();

        $payload = [
            'branch_id' => $branch->id,
            'amount' => '1400.00',
            'business_date' => '2026-09-20',
        ];

        $res1 = $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', $uuid)->postJson('/api/v1/results', $payload);
        $res1->assertStatus(201);

        $res2 = $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', $uuid)->postJson('/api/v1/results', $payload);
        $res2->assertStatus(201);

        $this->assertEquals('1400.00', (string) $branch->fresh()->current_balance);
        $this->assertCount(1, $branch->manualResults);
    }

    private function companyContext(): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create(['name' => 'BTM']);
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
