<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_and_create_collectors_in_active_company(): void
    {
        $admin = User::factory()->create(['name' => 'Admin User', 'email' => 'admin@test.com']);
        $company = Company::query()->create(['name' => 'Consorcio Test']);
        $admin->companies()->attach($company->id, ['role' => 'admin', 'status' => 'active']);

        $token = $admin->createToken('test')->plainTextToken;

        // Create collector
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/collectors', [
                'name' => 'Juan Cobrador',
                'email' => 'juan@cobrador.com',
                'password' => 'secret123',
                'role' => 'collector',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Juan Cobrador')
            ->assertJsonPath('data.email', 'juan@cobrador.com')
            ->assertJsonPath('data.role', 'collector');

        // List collectors
        $listResponse = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/collectors');

        $listResponse->assertOk()
            ->assertJsonCount(2, 'data'); // Admin and Juan

        // Update collector
        $juanId = $response->json('data.id');
        $updateResponse = $this->withHeader('Authorization', "Bearer $token")
            ->putJson("/api/v1/collectors/{$juanId}", [
                'name' => 'Juan Cobrador Actualizado',
                'status' => 'inactive',
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.name', 'Juan Cobrador Actualizado')
            ->assertJsonPath('data.status', 'inactive');
    }
}
