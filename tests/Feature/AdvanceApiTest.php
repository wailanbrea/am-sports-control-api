<?php

namespace Tests\Feature;

use App\Models\Advance;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_company_member_can_create_an_advance_as_an_atomic_negative_ledger_entry(): void
    {
        [$user, $company, $branch] = $this->accountingContext('35.00');

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/advances', [
            'branch_id' => $branch->id,
            'amount' => '12.5',
            'business_date' => '2026-09-17',
            'reason' => 'Pago anticipado',
            'payment_method' => 'transfer',
        ], ['Idempotency-Key' => 'a1111111-1111-4111-8111-111111111111']);

        $response->assertCreated()->assertJsonPath('data.amount', '12.50');
        $advance = Advance::query()->sole();
        $this->assertDatabaseHas('ledger_entries', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'source_type' => Advance::class,
            'source_id' => $advance->id,
            'entry_type' => 'advance',
            'signed_amount' => '-12.50',
            'balance_before' => '35.00',
            'balance_after' => '22.50',
        ]);
        $this->assertSame('22.50', $branch->fresh()->current_balance);
    }

    public function test_user_without_an_active_company_membership_cannot_create_an_advance(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create(['name' => 'Inactiva']);
        $branch = Branch::query()->create(['company_id' => $company->id, 'code' => 'B-01', 'name' => 'Banca']);
        $user->companies()->attach($company, ['role' => 'collector', 'status' => 'inactive']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/advances', [
            'branch_id' => $branch->id,
            'amount' => '10.00',
            'business_date' => '2026-09-17',
            'reason' => 'Pago anticipado',
        ], ['Idempotency-Key' => 'a2222222-2222-4222-8222-222222222222'])
            ->assertForbidden();
    }

    /** @return array{User, Company, Branch} */
    private function accountingContext(string $balance): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create(['name' => 'BTM']);
        $user->companies()->attach($company, ['role' => 'admin', 'status' => 'active']);
        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'B-01',
            'name' => 'Banca Uno',
            'current_balance' => $balance,
        ]);

        return [$user, $company, $branch];
    }
}
