<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Collection;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashBoxApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_member_can_record_cash_income(): void
    {
        [$user, $company] = $this->context();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/cash-box/income', [
            'amount' => '125.50',
            'business_date' => '2026-09-18',
            'reason' => 'Reposición de caja',
        ], ['Idempotency-Key' => '11111111-1111-4111-8111-111111111111'])
            ->assertCreated()
            ->assertJsonPath('data.movement_type', 'income')
            ->assertJsonPath('data.signed_amount', '125.50');

        $this->assertSame('125.50', $company->fresh()->cash_balance);
        $this->assertDatabaseHas('cash_movements', ['company_id' => $company->id, 'balance_after' => '125.50']);
    }

    public function test_expense_requires_a_reason(): void
    {
        [$user] = $this->context('50.00');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/cash-box/expenses', [
            'amount' => '10.00',
            'business_date' => '2026-09-18',
        ], ['Idempotency-Key' => '22222222-2222-4222-8222-222222222222'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_expense_cannot_exceed_the_company_cash_balance(): void
    {
        [$user, $company] = $this->context('10.00');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/cash-box/expenses', [
            'amount' => '10.01',
            'business_date' => '2026-09-18',
            'reason' => 'Compra',
        ], ['Idempotency-Key' => '33333333-3333-4333-8333-333333333333'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame('10.00', $company->fresh()->cash_balance);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_branch_transfer_requires_and_associates_an_active_company_branch(): void
    {
        [$user, $company] = $this->context('100.00');
        $branch = Branch::query()->create(['company_id' => $company->id, 'code' => 'B-02', 'name' => 'Norte']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/cash-box/branch-transfers', [
            'branch_id' => $branch->id,
            'amount' => '35.00',
            'business_date' => '2026-09-18',
            'reason' => 'Entrega a sucursal',
        ], ['Idempotency-Key' => '44444444-4444-4444-8444-444444444444'])
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $branch->id)
            ->assertJsonPath('data.signed_amount', '-35.00');

        $this->assertSame('65.00', $company->fresh()->cash_balance);
        $this->assertSame('35.00', $branch->fresh()->current_balance);
        $this->assertDatabaseHas('ledger_entries', [
            'branch_id' => $branch->id,
            'entry_type' => 'branch_support_payment',
            'signed_amount' => '35.00',
            'balance_before' => '0.00',
            'balance_after' => '35.00',
        ]);
    }

    public function test_cash_box_is_scoped_to_the_active_company(): void
    {
        [$user, $company] = $this->context('20.00');
        $otherCompany = Company::query()->create(['name' => 'Otra empresa', 'cash_balance' => '90.00']);
        CashMovement::query()->create([
            'company_id' => $otherCompany->id,
            'movement_type' => 'income',
            'amount' => '90.00',
            'signed_amount' => '90.00',
            'balance_before' => '0.00',
            'balance_after' => '90.00',
            'business_date' => '2026-09-18',
            'reason' => 'Ajeno',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/cash-box')
            ->assertOk()
            ->assertJsonPath('data.current_balance', '20.00')
            ->assertJsonCount(0, 'data.entries');
    }

    public function test_transfer_to_negative_branch_compensates_its_accumulated_balance(): void
    {
        [$user, $company, $branch] = $this->context('1000.00');
        $branch->update(['current_balance' => '-450.00']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/cash-box/branch-transfers', [
            'branch_id' => $branch->id,
            'amount' => '300.00',
            'business_date' => '2026-09-19',
            'reason' => 'Completar premios',
        ], ['Idempotency-Key' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa'])
            ->assertCreated();

        $this->assertSame('-150.00', $branch->fresh()->current_balance);
        $this->assertSame('700.00', $company->fresh()->cash_balance);
        $this->assertDatabaseHas('ledger_entries', [
            'branch_id' => $branch->id,
            'entry_type' => 'branch_support_payment',
            'signed_amount' => '300.00',
            'balance_before' => '-450.00',
            'balance_after' => '-150.00',
        ]);
    }

    public function test_cash_collection_creates_one_movement_on_idempotent_retry_but_transfer_does_not(): void
    {
        [$user, $company, $branch] = $this->context('100.00');
        $payload = [
            'branch_id' => $branch->id,
            'amount' => '15.00',
            'business_date' => '2026-09-18',
            'payment_method' => 'cash',
        ];
        $headers = ['Idempotency-Key' => '55555555-5555-4555-8555-555555555555'];

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/collections', $payload, $headers)->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/collections', $payload, $headers)->assertCreated();

        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame('115.00', $company->fresh()->cash_balance);
        $this->assertSame(Collection::class, CashMovement::query()->sole()->source_type);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/collections', [
            ...$payload,
            'payment_method' => 'transfer',
        ], ['Idempotency-Key' => '66666666-6666-4666-8666-666666666666'])->assertCreated();

        $this->assertDatabaseCount('cash_movements', 1);
    }

    /** @return array{User, Company, Branch} */
    private function context(string $cashBalance = '0.00'): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create(['name' => 'BTM', 'cash_balance' => $cashBalance]);
        $user->companies()->attach($company, ['role' => 'admin', 'status' => 'active']);
        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'B-01',
            'name' => 'Centro',
            'current_balance' => '100.00',
        ]);

        return [$user, $company, $branch];
    }
}
