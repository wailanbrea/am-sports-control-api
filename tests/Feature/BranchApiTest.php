<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_member_can_create_a_branch_with_a_zero_balance(): void
    {
        [$user, $company] = $this->companyContext();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/branches', [
            'code' => 'B-02',
            'name' => 'Banca Norte',
            'description' => 'Ruta norte',
            'route' => 'Norte',
            'operator_name' => 'Ana Perez',
            'status' => 'active',
            'current_balance' => '999.99',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.company_id', $company->id)
            ->assertJsonPath('data.current_balance', '0.00')
            ->assertJsonPath('data.route', 'Norte')
            ->assertJsonPath('data.operator_name', 'Ana Perez');
        $this->assertDatabaseHas('branches', ['company_id' => $company->id, 'code' => 'B-02', 'current_balance' => '0.00']);
    }

    public function test_branch_update_changes_only_allowed_fields(): void
    {
        [$user, $company] = $this->companyContext();
        $branch = $this->branch($company, 'B-01', '25.00');

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/branches/{$branch->id}", [
            'code' => 'B-03',
            'name' => 'Banca Actualizada',
            'description' => null,
            'route' => null,
            'operator_name' => 'Luis Diaz',
            'status' => 'inactive',
            'current_balance' => '0.00',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'B-03')
            ->assertJsonPath('data.current_balance', '25.00')
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_branch_codes_are_unique_within_a_company_and_input_is_validated(): void
    {
        [$user, $company] = $this->companyContext();
        $this->branch($company, 'B-01');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/branches', [
            'code' => 'B-01',
            'name' => 'Duplicada',
            'status' => 'active',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/branches', [
            'code' => 'B-02',
            'name' => 'Inválida',
            'status' => 'pending',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_branch_codes_can_be_reused_by_another_company(): void
    {
        [$user, $company] = $this->companyContext();
        $this->branch($company, 'B-01');
        $otherCompany = Company::query()->create(['name' => 'Otra empresa']);
        $otherUser = User::factory()->create();
        $otherUser->companies()->attach($otherCompany, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($otherUser, 'sanctum')->postJson('/api/v1/branches', [
            'code' => 'B-01',
            'name' => 'Banca propia',
            'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.company_id', $otherCompany->id);
    }

    public function test_branch_updates_and_deletes_are_scoped_to_the_active_company(): void
    {
        [$user] = $this->companyContext();
        $otherCompany = Company::query()->create(['name' => 'Otra empresa']);
        $otherBranch = $this->branch($otherCompany, 'OT-01');

        $payload = ['code' => 'OT-02', 'name' => 'Ajena', 'status' => 'active'];
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/branches/{$otherBranch->id}", $payload)->assertNotFound();
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/branches/{$otherBranch->id}")->assertNotFound();
        $this->assertDatabaseHas('branches', ['id' => $otherBranch->id, 'code' => 'OT-01']);
    }

    public function test_branch_cannot_be_deleted_with_ledger_entries_or_a_nonzero_balance(): void
    {
        [$user, $company] = $this->companyContext();
        $withLedger = $this->branch($company, 'B-01');
        $withBalance = $this->branch($company, 'B-02', '1.00');
        LedgerEntry::query()->create([
            'company_id' => $company->id,
            'branch_id' => $withLedger->id,
            'source_type' => 'test',
            'source_id' => 1,
            'entry_type' => 'test',
            'signed_amount' => '0.00',
            'balance_before' => '0.00',
            'balance_after' => '0.00',
            'business_date' => '2026-09-17',
            'description' => 'Movimiento histórico',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/branches/{$withLedger->id}")->assertStatus(409);
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/branches/{$withBalance->id}")->assertStatus(409);
        $this->assertDatabaseHas('branches', ['id' => $withLedger->id]);
        $this->assertDatabaseHas('branches', ['id' => $withBalance->id]);
    }

    public function test_empty_zero_balance_branch_can_be_deleted(): void
    {
        [$user, $company] = $this->companyContext();
        $branch = $this->branch($company, 'B-01');

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/branches/{$branch->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);
        $this->assertDatabaseMissing('branches', ['id' => $branch->id]);
    }

    /** @return array{User, Company} */
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
