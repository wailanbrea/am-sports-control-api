<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WeeklyLossAbsorptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_absorb_negative_loss_and_leave_branch_at_zero(): void
    {
        [$user, $company, $cashBox] = $this->setupCompanyWithCashBox('5000.00');

        // Banca en negativo -$350.00
        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'B-07',
            'name' => 'Banca 07',
            'current_balance' => '-350.00',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branches/{$branch->id}/absorb-loss", [
                'cash_box_id' => $cashBox->id,
                'deduct_cash_box' => true,
                'business_date' => '2026-10-01',
                'reason' => 'Pérdida semana anterior asumida por consorcio',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount_absorbed', '350.00')
            ->assertJsonPath('data.balance_before', '-350.00')
            ->assertJsonPath('data.balance_after', '0.00');

        // Balance de la banca debe ser exactamente 0.00
        $this->assertEquals('0.00', (string) $branch->fresh()->current_balance);

        // Caja chica debe haberse reducido en 350 (5000 - 350 = 4650)
        $this->assertEquals('4650.00', (string) $cashBox->fresh()->balance);
        $this->assertEquals('4650.00', (string) $company->fresh()->cash_balance);

        // Debe existir el asiento en el libro mayor
        $this->assertDatabaseHas('ledger_entries', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'entry_type' => 'weekly_loss_absorbed',
            'signed_amount' => '350.00',
            'balance_after' => '0.00',
        ]);

        // Debe existir el movimiento de salida de caja
        $this->assertDatabaseHas('cash_movements', [
            'company_id' => $company->id,
            'cash_box_id' => $cashBox->id,
            'movement_type' => 'loss_absorption',
            'amount' => '350.00',
            'signed_amount' => '-350.00',
        ]);
    }

    public function test_money_delivery_does_not_increase_rifero_debt_when_branch_is_positive(): void
    {
        [$user, $company, $cashBox] = $this->setupCompanyWithCashBox('10000.00');

        // Banca 13 con deuda previa legítima de $6,000.00
        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'B-13',
            'name' => 'Banca 13',
            'current_balance' => '6000.00',
            'status' => 'active',
        ]);

        // Dueño lleva $1,780.00 para cubrir palés ganadores
        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/money-deliveries', [
                'branch_id' => $branch->id,
                'amount' => '1780.00',
                'business_date' => '2026-09-29',
                'reason' => 'Cubrir palés sacados lunes y martes',
            ]);

        $response->assertStatus(201);

        // La deuda del rifero NO debe subir a $7,780.00; debe permanecer en $6,000.00
        $this->assertEquals('6000.00', (string) $branch->fresh()->current_balance);

        // Caja chica sí disminuye en $1,780.00 (10000 - 1780 = 8220)
        $this->assertEquals('8220.00', (string) $company->fresh()->cash_balance);

        // Se registra como prize_fund_delivery sin incrementar deuda (signed_amount 0.00)
        $this->assertDatabaseHas('ledger_entries', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'entry_type' => 'prize_fund_delivery',
            'signed_amount' => '0.00',
            'balance_after' => '6000.00',
        ]);
    }

    public function test_weekly_settlement_with_loss_absorbs_deficit_and_resets_week_to_zero(): void
    {
        [$user, $company, $cashBox] = $this->setupCompanyWithCashBox('10000.00');

        // Banca 13 con saldo 6000.00
        $branch = Branch::query()->create([
            'company_id' => $company->id,
            'code' => 'B-13',
            'name' => 'Banca 13',
            'current_balance' => '6000.00',
            'status' => 'active',
        ]);

        // Cierre semanal: Ventas 1,000, Premios 1,780 -> Pérdida neta de 780
        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/weekly-settlements', [
                'branch_id' => $branch->id,
                'week_start' => '2026-09-21',
                'week_end' => '2026-09-27',
                'sales_amount' => '1000.00',
                'prizes_amount' => '1780.00',
                'commission_rate' => '10.00',
                'cash_delivered_amount' => '0.00',
                'absorb_loss' => true,
                'notes' => 'Pérdida en palés asumida',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.weekly_balance', '0.00')
            ->assertJsonPath('data.loss_absorbed_amount', '780.00')
            ->assertJsonPath('data.settlement_type', 'loss_absorbed');

        // La deuda del rifero sigue en 6,000.00; NO aumenta por la pérdida de la semana
        $this->assertEquals('6000.00', (string) $branch->fresh()->current_balance);

        // Se registra el asiento de pérdida asumida en el libro mayor
        $this->assertDatabaseHas('ledger_entries', [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'entry_type' => 'weekly_loss_absorbed',
            'signed_amount' => '780.00',
        ]);
    }

    private function setupCompanyWithCashBox(string $balance = '5000.00'): array
    {
        $user = User::factory()->create();
        $company = Company::query()->create(['name' => 'BTM Contabilidad', 'cash_balance' => $balance]);
        $user->companies()->attach($company, ['role' => 'admin', 'status' => 'active']);

        $cashBox = CashBox::query()->create([
            'company_id' => $company->id,
            'name' => 'Caja Principal',
            'balance' => $balance,
            'is_default' => true,
            'status' => 'active',
        ]);

        return [$user, $company, $cashBox];
    }
}
