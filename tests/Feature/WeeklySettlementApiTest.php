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
            ->assertJsonPath('data.commission_rate', '20.00')
            ->assertJsonPath('data.commission_amount', '1200.00')
            ->assertJsonPath('data.weekly_balance', '3800.00')
            ->assertJsonPath('data.balance_before', '5000.00')
            ->assertJsonPath('data.balance_after', '8800.00')
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame('8800.00', $branch->fresh()->current_balance);
        $this->assertSame('18000.00', $company->fresh()->cash_balance);
        $this->assertDatabaseCount('ledger_entries', 4);
        $this->assertDatabaseHas('ledger_entries', [
            'entry_type' => 'weekly_commission',
            'signed_amount' => '-1200.00',
        ]);
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
        $this->assertDatabaseCount('ledger_entries', 4);
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

    public function test_commission_rate_cannot_exceed_one_hundred_percent(): void
    {
        [$user, , $branch] = $this->context();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/weekly-settlements', [
            ...$this->payload($branch->id),
            'commission_rate' => '100.01',
        ], ['Idempotency-Key' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('commission_rate');
    }

    public function test_partial_collection_preserves_balance_and_marks_latest_settlement_partial(): void
    {
        [$user, , $branch] = $this->context();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/weekly-settlements', $this->payload($branch->id), [
            'Idempotency-Key' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        ])->assertCreated();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/collections', [
            'branch_id' => $branch->id,
            'amount' => '2000.00',
            'business_date' => '2026-09-21',
            'payment_method' => 'transfer',
        ], ['Idempotency-Key' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'])
            ->assertCreated();

        $this->assertSame('6800.00', $branch->fresh()->current_balance);
        $this->assertDatabaseHas('weekly_settlements', [
            'branch_id' => $branch->id,
            'status' => 'partially_paid',
        ]);
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
            'commission_rate' => '20.00',
            'cash_delivered_amount' => '2000.00',
        ];
    }
}
