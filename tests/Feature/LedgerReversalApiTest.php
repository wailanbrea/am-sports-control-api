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
}
