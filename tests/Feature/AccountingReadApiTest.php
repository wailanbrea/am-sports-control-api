<?php

namespace Tests\Feature;

use App\Models\Advance;
use App\Models\Branch;
use App\Models\Collection;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_company_member_can_read_only_their_accounting_collections(): void
    {
        [$user, $company, $branch] = $this->accountingContext();
        $otherCompany = Company::query()->create(['name' => 'Otra empresa']);
        $otherBranch = Branch::query()->create(['company_id' => $otherCompany->id, 'code' => 'OT-01', 'name' => 'Ajena']);
        $entry = $this->ledgerEntry($company, $branch, '10.00', '2026-09-17', 'Movimiento propio');
        $otherEntry = $this->ledgerEntry($otherCompany, $otherBranch, '20.00', '2026-09-18', 'Movimiento ajeno');
        $collection = $this->transaction(Collection::class, $company, $branch, '10.25', 'confirmed');
        $advance = $this->transaction(Advance::class, $company, $branch, '5.50', 'confirmed');
        $otherCollection = $this->transaction(Collection::class, $otherCompany, $otherBranch, '20.25', 'confirmed');
        $otherAdvance = $this->transaction(Advance::class, $otherCompany, $otherBranch, '6.50', 'confirmed');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $branch->id);
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/branches/{$branch->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $branch->id)
            ->assertJsonPath('data.collections.0.id', $collection->id)
            ->assertJsonPath('data.collections.0.amount', '10.25')
            ->assertJsonPath('data.advances.0.id', $advance->id)
            ->assertJsonPath('data.advances.0.amount', '5.50')
            ->assertJsonPath('data.ledger.0.id', $entry->id)
            ->assertJsonPath('data.ledger.0.signed_amount', '10.00');
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/branches/{$otherBranch->id}")->assertNotFound();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/ledger')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $entry->id)
            ->assertJsonMissing(['id' => $otherEntry->id]);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/collections')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $collection->id)
            ->assertJsonPath('data.0.amount', '10.25')
            ->assertJsonMissing(['id' => $otherCollection->id]);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/advances')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $advance->id)
            ->assertJsonPath('data.0.amount', '5.50')
            ->assertJsonMissing(['id' => $otherAdvance->id]);
    }

    public function test_dashboard_uses_only_active_company_accounting_data(): void
    {
        [$user, $company, $branch] = $this->accountingContext('100.00');
        $creditBranch = Branch::query()->create(['company_id' => $company->id, 'code' => 'B-02', 'name' => 'Crédito', 'current_balance' => '-25.00']);
        $otherCompany = Company::query()->create(['name' => 'Otra empresa']);
        $otherBranch = Branch::query()->create(['company_id' => $otherCompany->id, 'code' => 'OT-01', 'name' => 'Ajena', 'current_balance' => '999.00']);

        $this->transaction(Collection::class, $company, $branch, '30.00', 'confirmed');
        $this->transaction(Collection::class, $otherCompany, $otherBranch, '999.00', 'confirmed');
        $this->transaction(Advance::class, $company, $branch, '12.00', 'confirmed');
        $this->transaction(Advance::class, $company, $creditBranch, '8.00', 'reversed');
        $older = $this->ledgerEntry($company, $branch, '-30.00', '2026-09-16', 'Cobro recibido');
        $newer = $this->ledgerEntry($company, $creditBranch, '-12.00', '2026-09-17', 'Adelanto entregado');
        $this->ledgerEntry($otherCompany, $otherBranch, '-999.00', '2026-09-18', 'Movimiento ajeno');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.receivable_total', '100.00')
            ->assertJsonPath('data.branch_credit_total', '25.00')
            ->assertJsonPath('data.net_position', '75.00')
            ->assertJsonPath('data.collections_total', '30.00')
            ->assertJsonPath('data.advances_total', '12.00')
            ->assertJsonPath('data.cash_balance', '0.00')
            ->assertJsonCount(2, 'data.recent_activity')
            ->assertJsonPath('data.recent_activity.0.id', $newer->id)
            ->assertJsonPath('data.recent_activity.1.id', $older->id);
    }

    public function test_read_endpoints_require_an_active_company_membership(): void
    {
        $user = User::factory()->create();
        $company = Company::query()->create(['name' => 'Inactiva']);
        $user->companies()->attach($company, ['role' => 'collector', 'status' => 'inactive']);

        foreach (['/api/v1/branches', '/api/v1/ledger', '/api/v1/dashboard', '/api/v1/collections', '/api/v1/advances'] as $url) {
            $this->actingAs($user, 'sanctum')->getJson($url)->assertForbidden();
        }
    }

    /** @return array{User, Company, Branch} */
    private function accountingContext(string $balance = '0.00'): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create(['name' => 'BTM']);
        $user->companies()->attach($company, ['role' => 'admin', 'status' => 'active']);
        $branch = Branch::query()->create(['company_id' => $company->id, 'code' => 'B-01', 'name' => 'Uno', 'current_balance' => $balance]);

        return [$user, $company, $branch];
    }

    private function ledgerEntry(Company $company, Branch $branch, string $amount, string $date, string $description): LedgerEntry
    {
        return LedgerEntry::query()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'source_type' => 'test',
            'source_id' => 1,
            'entry_type' => 'test',
            'signed_amount' => $amount,
            'balance_before' => '0.00',
            'balance_after' => $amount,
            'business_date' => $date,
            'description' => $description,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    /** @param class-string<Collection|Advance> $model */
    private function transaction(string $model, Company $company, Branch $branch, string $amount, string $status): Collection|Advance
    {
        $data = [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'amount' => $amount,
            'business_date' => '2026-09-17',
            'status' => $status,
            'idempotency_key' => fake()->uuid(),
            'created_by' => User::factory()->create()->id,
        ];

        if ($model === Collection::class) {
            $data['payment_method'] = 'cash';
        } else {
            $data['reason'] = 'Pago anticipado';
        }

        return $model::query()->create($data);
    }
}
