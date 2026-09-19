<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeeklySettlementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_settlement_updates_branch_and_cash_box_using_the_accounting_formula(): void
    {
        [$user, $company, $branch] = $this->context();
        $payload = $this->payload($branch->id);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/weekly-settlements', $payload, [
                'Idempotency-Key' => '77777777-7777-4777-8777-777777777777',
            ])
            ->assertCreated()
            ->assertJsonPath('data.weekly_balance', '5000.00')
            ->assertJsonPath('data.balance_before', '5000.00')
            ->assertJsonPath('data.balance_after', '10000.00');

        $this->assertSame('10000.00', $branch->fresh()->current_balance);
        $this->assertSame('18000.00', $company->fresh()->cash_balance);
        $this->assertDatabaseCount('ledger_entries', 3);
        $this->assertDatabaseHas('cash_movements', [
            'movement_type' => 'branch_transfer',
            'amount' => '2000.00',
            'signed_amount' => '-2000.00',
        ]);
    }

    public function test_retry_with_the_same_key_does_not_duplicate_the_settlement(): void
    {
        [$user, , $branch] = $this->context();
        $payload = $this->payload($branch->id);
        $headers = ['Idempotency-Key' => '88888888-8888-4888-8888-888888888888'];

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/weekly-settlements', $payload, $headers)->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/weekly-settlements', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('data.id', 1);

        $this->assertDatabaseCount('weekly_settlements', 1);
        $this->assertDatabaseCount('ledger_entries', 3);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_same_week_cannot_be_registered_twice_with_a_different_key(): void
    {
        [$user, , $branch] = $this->context();
        $payload = $this->payload($branch->id);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/weekly-settlements', $payload, [
            'Idempotency-Key' => '99999999-9999-4999-8999-999999999999',
        ])->assertCreated();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/weekly-settlements', $payload, [
            'Idempotency-Key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ])->assertUnprocessable()->assertJsonValidationErrors('week_start');
    }

    public function test_weekly_settlement_requires_a_non_zero_amount(): void
    {
        [$user, , $branch] = $this->context();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/weekly-settlements', [
            ...$this->payload($branch->id),
            'sales_amount' => '0.00',
            'prizes_amount' => '0.00',
            'cash_delivered_amount' => '0.00',
        ], ['Idempotency-Key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sales_amount');
    }

    /** @return array{User, Company, Branch} */
    private function context(): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create(['name' => 'BTM', 'cash_balance' => '20000.00']);
        $user->companies()->attach($company, ['role' => 'admin', 'status' => 'active']);
        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'B-01',
            'name' => 'Centro',
            'current_balance' => '5000.00',
        ]);

        return [$user, $company, $branch];
    }

    private function payload(int $branchId): array
    {
        return [
            'branch_id' => $branchId,
            'week_start' => '2026-09-14',
            'week_end' => '2026-09-20',
            'sales_amount' => '6000.00',
            'prizes_amount' => '3000.00',
            'cash_delivered_amount' => '2000.00',
        ];
    }
}
