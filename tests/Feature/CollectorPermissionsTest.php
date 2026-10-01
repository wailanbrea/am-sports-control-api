<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashBoxMovement;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectorPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function collectorContext(): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create(['name' => 'BTM Contabilidad', 'cash_balance' => '5000.00']);
        $user->companies()->attach($company, ['role' => 'collector', 'status' => 'active']);

        return [$user, $company];
    }

    public function test_collector_can_view_branches_and_record_a_collection(): void
    {
        [$collector, $company] = $this->collectorContext();
        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'B-01',
            'name' => 'Banca Centro',
            'current_balance' => '1000.00',
            'status' => 'active',
            'collector_user_id' => $collector->id,
        ]);

        // 1. Collector CAN read branches
        $readResponse = $this->actingAs($collector, 'sanctum')->getJson('/api/v1/branches');
        $readResponse->assertOk()
            ->assertJsonPath('success', true);

        // 2. Collector CAN record a collection
        $collectResponse = $this->actingAs($collector, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/collections', [
                'branch_id' => $branch->id,
                'amount' => '300.00',
                'business_date' => now()->toDateString(),
                'payment_method' => 'cash',
                'notes' => 'Cobro realizado en ruta',
            ]);

        $collectResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', '300.00');

        $this->assertDatabaseHas('collections', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'amount' => '300.00',
        ]);
    }

    public function test_collector_cannot_create_or_modify_branches(): void
    {
        [$collector, $company] = $this->collectorContext();
        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'B-01',
            'name' => 'Banca Centro',
            'current_balance' => '0.00',
            'status' => 'active',
        ]);

        // Cannot create branch
        $createResponse = $this->actingAs($collector, 'sanctum')->postJson('/api/v1/branches', [
            'code' => 'B-99',
            'name' => 'Banca Invalida',
            'status' => 'active',
        ]);
        $createResponse->assertForbidden()
            ->assertJsonPath('success', false);

        // Cannot update branch
        $updateResponse = $this->actingAs($collector, 'sanctum')->putJson("/api/v1/branches/{$branch->id}", [
            'name' => 'Banca Modificada',
            'status' => 'active',
        ]);
        $updateResponse->assertForbidden()
            ->assertJsonPath('success', false);

        // Cannot delete branch
        $deleteResponse = $this->actingAs($collector, 'sanctum')->deleteJson("/api/v1/branches/{$branch->id}");
        $deleteResponse->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_collector_cannot_deliver_money_or_record_expenses(): void
    {
        [$collector, $company] = $this->collectorContext();
        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'B-01',
            'name' => 'Banca Centro',
            'current_balance' => '-500.00',
            'status' => 'active',
        ]);

        // Cannot record money delivery
        $deliveryResponse = $this->actingAs($collector, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/money-deliveries', [
                'branch_id' => $branch->id,
                'delivered_amount' => '500.00',
                'delivery_date' => now()->toDateString(),
                'reason' => 'Cubrir premios',
            ]);
        $deliveryResponse->assertForbidden()
            ->assertJsonPath('success', false);

        // Cannot record expense
        $expenseResponse = $this->actingAs($collector, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/cash-box/expenses', [
                'amount' => '100.00',
                'movement_date' => now()->toDateString(),
                'reason' => 'Gasto no autorizado',
            ]);
        $expenseResponse->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_collector_cannot_manage_collectors_or_reverse_ledger(): void
    {
        [$collector, $company] = $this->collectorContext();

        // Cannot list collectors
        $listResponse = $this->actingAs($collector, 'sanctum')->getJson('/api/v1/collectors');
        $listResponse->assertForbidden()
            ->assertJsonPath('success', false);

        // Cannot create new collector
        $createResponse = $this->actingAs($collector, 'sanctum')->postJson('/api/v1/collectors', [
            'name' => 'Otro Cobrador',
            'email' => 'otro@btm.com',
            'password' => 'secret123',
        ]);
        $createResponse->assertForbidden()
            ->assertJsonPath('success', false);

        // Cannot reverse ledger
        $reverseResponse = $this->actingAs($collector, 'sanctum')->postJson('/api/v1/ledger-entries/1/reverse', [
            'reason' => 'Reversion no autorizada',
        ]);
        $reverseResponse->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_collector_cannot_view_cash_box_or_modify_collections(): void
    {
        [$collector, $company] = $this->collectorContext();
        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'B-01',
            'name' => 'Banca Centro',
            'current_balance' => '1000.00',
            'status' => 'active',
            'collector_user_id' => $collector->id,
        ]);

        // Record a collection
        $collectResponse = $this->actingAs($collector, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/collections', [
                'branch_id' => $branch->id,
                'amount' => '300.00',
                'business_date' => now()->toDateString(),
                'payment_method' => 'cash',
            ]);
        $collectionId = $collectResponse->json('data.id');

        // 1. Collector CANNOT access cash box
        $cashBoxResponse = $this->actingAs($collector, 'sanctum')->getJson('/api/v1/cash-box');
        $cashBoxResponse->assertForbidden()
            ->assertJsonPath('success', false);

        // 2. Collector CANNOT update collection
        $updateResponse = $this->actingAs($collector, 'sanctum')->putJson("/api/v1/collections/{$collectionId}", [
            'amount' => '400.00',
            'business_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ]);
        $updateResponse->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_update_collection_and_recalculate_balances(): void
    {
        $admin = User::factory()->create();
        $company = Company::query()->create(['name' => 'BTM Contabilidad', 'cash_balance' => '5000.00']);
        $admin->companies()->attach($company, ['role' => 'admin', 'status' => 'active']);

        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'B-01',
            'name' => 'Banca Centro',
            'current_balance' => '1000.00',
            'status' => 'active',
        ]);

        // Create collection of 300 in cash
        $collectResponse = $this->actingAs($admin, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/collections', [
                'branch_id' => $branch->id,
                'amount' => '300.00',
                'business_date' => now()->toDateString(),
                'payment_method' => 'cash',
            ]);
        $collectionId = $collectResponse->json('data.id');

        // Initial branch balance: 1000 - 300 = 700.00
        $this->assertEquals('700.00', $branch->fresh()->current_balance);
        // Initial cash balance: 5000 + 300 = 5300.00
        $this->assertEquals('5300.00', $company->fresh()->cash_balance);

        // Admin updates collection to 450 in cash (delta +150)
        $updateResponse = $this->actingAs($admin, 'sanctum')->putJson("/api/v1/collections/{$collectionId}", [
            'amount' => '450.00',
            'business_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference' => 'Recibo Corregido #123',
        ]);
        $updateResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', '450.00');

        // New branch balance: 700 - 150 = 550.00
        $this->assertEquals('550.00', $branch->fresh()->current_balance);
        // New cash balance: 5300 + 150 = 5450.00
        $this->assertEquals('5450.00', $company->fresh()->cash_balance);
    }
}
