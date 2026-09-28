<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Collection;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerReversalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ledger_entry_can_be_reversed_once_only_with_a_compensating_entry(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create(['name' => 'BTM']);
        $user->companies()->attach($company, ['role' => 'admin', 'status' => 'active']);
        $branch = Branch::query()->create(['company_id' => $company->id, 'code' => 'B-01', 'name' => 'Uno', 'current_balance' => '30.00']);
        $collection = Collection::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'amount' => '10.00',
            'business_date' => '2026-09-17',
            'payment_method' => 'cash',
            'idempotency_key' => 'b1111111-1111-4111-8111-111111111111',
            'created_by' => $user->id,
            'confirmed_at' => now(),
        ]);
        $entry = LedgerEntry::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'source_type' => Collection::class,
            'source_id' => $collection->id,
            'entry_type' => 'collection',
            'signed_amount' => '-10.00',
            'balance_before' => '40.00',
            'balance_after' => '30.00',
            'business_date' => '2026-09-17',
            'description' => 'Cobro recibido',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/ledger-entries/{$entry->id}/reverse", [
            'business_date' => '2026-09-18',
            'reason' => 'Cobro duplicado',
        ])->assertCreated()->assertJsonPath('data.signed_amount', '10.00');

        $this->assertSame('40.00', $branch->fresh()->current_balance);
        $this->assertNotNull($entry->fresh()->reversed_at);
        $this->assertSame('reversed', $collection->fresh()->status);
        $this->assertDatabaseHas('ledger_entries', ['reversal_of_entry_id' => $entry->id, 'signed_amount' => '10.00']);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/ledger-entries/{$entry->id}/reverse", [
            'business_date' => '2026-09-18',
            'reason' => 'Segundo intento',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_reversal_of_money_delivery_restores_cash_box_and_company_balance(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create(['name' => 'BTM', 'cash_balance' => '1000.00']);
        $user->companies()->attach($company, ['role' => 'admin', 'status' => 'active']);
        $cashBox = \App\Models\CashBox::query()->create([
            'company_id' => $company->id,
            'name' => 'Caja Principal',
            'balance' => '1000.00',
            'is_default' => true,
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $branch = Branch::query()->create(['company_id' => $company->id, 'code' => 'B-13', 'name' => 'Trece', 'current_balance' => '-500.00']);

        // Simular entrega de dinero desde caja chica
        $delivery = \App\Models\MoneyDelivery::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'suggested_amount' => '500.00',
            'gross_amount' => '500.00',
            'commission_rate' => '0.00',
            'commission_amount' => '0.00',
            'delivered_amount' => '500.00',
            'business_date' => '2026-09-28',
            'reason' => 'Cubrir pérdida',
            'status' => 'confirmed',
            'idempotency_key' => 'c1111111-1111-4111-8111-111111111111',
            'created_by' => $user->id,
        ]);

        $entry = LedgerEntry::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'source_type' => \App\Models\MoneyDelivery::class,
            'source_id' => $delivery->id,
            'entry_type' => 'money_delivery',
            'signed_amount' => '500.00',
            'balance_before' => '-500.00',
            'balance_after' => '0.00',
            'business_date' => '2026-09-28',
            'description' => 'Dinero llevado a la banca',
            'created_by' => $user->id,
        ]);
        $branch->update(['current_balance' => '0.00']);

        // Salida de caja
        \App\Models\CashMovement::query()->create([
            'company_id' => $company->id,
            'cash_box_id' => $cashBox->id,
            'branch_id' => $branch->id,
            'movement_type' => 'branch_delivery',
            'amount' => '500.00',
            'signed_amount' => '-500.00',
            'balance_before' => '1000.00',
            'balance_after' => '500.00',
            'business_date' => '2026-09-28',
            'reason' => 'Dinero llevado a Trece',
            'source_type' => \App\Models\MoneyDelivery::class,
            'source_id' => $delivery->id,
            'created_by' => $user->id,
        ]);
        $cashBox->update(['balance' => '500.00']);
        $company->update(['cash_balance' => '500.00']);

        // Revertir el asiento contable de entrega de dinero
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/ledger-entries/{$entry->id}/reverse", [
            'business_date' => '2026-09-28',
            'reason' => 'Corrección: no salió de caja',
        ])->assertCreated();

        // 1. Balance de la banca debe volver a -500.00
        $this->assertSame('-500.00', $branch->fresh()->current_balance);

        // 2. Estado de MoneyDelivery debe ser cancelled
        $this->assertSame('cancelled', $delivery->fresh()->status);

        // 3. Saldo de la caja chica debe restaurarse a 1000.00
        $this->assertSame('1000.00', $cashBox->fresh()->balance);

        // 4. Saldo de la compañía debe restaurarse a 1000.00
        $this->assertSame('1000.00', $company->fresh()->cash_balance);

        // 5. Debe existir un movimiento de reversión en caja chica (+500.00)
        $this->assertDatabaseHas('cash_movements', [
            'company_id' => $company->id,
            'cash_box_id' => $cashBox->id,
            'signed_amount' => '500.00',
            'movement_type' => 'income',
        ]);
    }

    public function test_reversal_of_cash_collection_deducts_cash_box_and_company_balance(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create(['name' => 'BTM', 'cash_balance' => '1000.00']);
        $user->companies()->attach($company, ['role' => 'admin', 'status' => 'active']);
        $cashBox = \App\Models\CashBox::query()->create([
            'company_id' => $company->id,
            'name' => 'Caja Principal',
            'balance' => '1000.00',
            'is_default' => true,
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $branch = Branch::query()->create(['company_id' => $company->id, 'code' => 'B-14', 'name' => 'Catorce', 'current_balance' => '300.00']);

        // Cobro en efectivo
        $collection = Collection::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'amount' => '200.00',
            'business_date' => '2026-09-28',
            'payment_method' => 'cash',
            'idempotency_key' => 'd1111111-1111-4111-8111-111111111111',
            'created_by' => $user->id,
            'confirmed_at' => now(),
        ]);

        $entry = LedgerEntry::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'source_type' => Collection::class,
            'source_id' => $collection->id,
            'entry_type' => 'collection',
            'signed_amount' => '-200.00',
            'balance_before' => '300.00',
            'balance_after' => '100.00',
            'business_date' => '2026-09-28',
            'description' => 'Cobro recibido',
            'created_by' => $user->id,
        ]);
        $branch->update(['current_balance' => '100.00']);

        // Movimiento de ingreso en caja chica
        \App\Models\CashMovement::query()->create([
            'company_id' => $company->id,
            'cash_box_id' => $cashBox->id,
            'branch_id' => $branch->id,
            'movement_type' => 'income',
            'amount' => '200.00',
            'signed_amount' => '200.00',
            'balance_before' => '800.00',
            'balance_after' => '1000.00',
            'business_date' => '2026-09-28',
            'reason' => 'Cobro de banca',
            'source_type' => Collection::class,
            'source_id' => $collection->id,
            'created_by' => $user->id,
        ]);

        // Revertir el cobro
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/ledger-entries/{$entry->id}/reverse", [
            'business_date' => '2026-09-28',
            'reason' => 'Cobro errado',
        ])->assertCreated();

        // 1. Balance de la banca vuelve a 300.00
        $this->assertSame('300.00', $branch->fresh()->current_balance);

        // 2. Estado del cobro es reversed
        $this->assertSame('reversed', $collection->fresh()->status);

        // 3. Saldo de la caja chica debe bajar a 800.00 (-200.00)
        $this->assertSame('800.00', $cashBox->fresh()->balance);

        // 4. Saldo de la compañía debe bajar a 800.00 (-200.00)
        $this->assertSame('800.00', $company->fresh()->cash_balance);

        // 5. Debe existir un movimiento de reversión en caja chica (-200.00 expense)
        $this->assertDatabaseHas('cash_movements', [
            'company_id' => $company->id,
            'cash_box_id' => $cashBox->id,
            'signed_amount' => '-200.00',
            'movement_type' => 'expense',
        ]);
    }
}
