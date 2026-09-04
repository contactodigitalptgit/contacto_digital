<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Token auth for the mobile app (see docs/PLANO_DE_PERFORMANCE_SINCRONIZACAO.md
 * — app Flutter, cliente acompanhar o evento). This guard is intentionally
 * client-only: an admin token here would let mobile bypass the admin UI's
 * own authorization entirely, so login must reject anything that isn't an
 * active client account.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_client_can_login_and_receive_a_token(): void
    {
        $user = User::factory()->create(['role' => 'client', 'password' => bcrypt('secret123')]);
        Client::create([
            'user_id' => $user->id,
            'name' => 'Cliente Teste',
            'address' => 'Rua de Teste',
            'phone' => '+351 930000000',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'client' => ['id', 'name', 'business_name']]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $user = User::factory()->create(['role' => 'client', 'password' => bcrypt('secret123')]);
        Client::create([
            'user_id' => $user->id,
            'name' => 'Cliente Teste',
            'address' => 'Rua de Teste',
            'phone' => '+351 930000000',
            'is_active' => true,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'errada',
        ])->assertUnprocessable();
    }

    public function test_login_rejects_admin_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => bcrypt('secret123')]);

        $this->postJson('/api/login', [
            'email' => $admin->email,
            'password' => 'secret123',
        ])->assertUnprocessable();
    }

    public function test_login_rejects_inactive_client(): void
    {
        $user = User::factory()->create(['role' => 'client', 'password' => bcrypt('secret123')]);
        Client::create([
            'user_id' => $user->id,
            'name' => 'Cliente Inativo',
            'address' => 'Rua de Teste',
            'phone' => '+351 930000000',
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertUnprocessable();
    }

    public function test_authenticated_client_can_logout(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        Client::create([
            'user_id' => $user->id,
            'name' => 'Cliente Teste',
            'address' => 'Rua de Teste',
            'phone' => '+351 930000000',
            'is_active' => true,
        ]);
        $token = $user->createToken('mobile-app')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_deactivating_a_client_revokes_access_on_the_next_request(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $client = Client::create([
            'user_id' => $user->id,
            'name' => 'Cliente Teste',
            'address' => 'Rua de Teste',
            'phone' => '+351 930000000',
            'is_active' => true,
        ]);
        $token = $user->createToken('mobile-app')->plainTextToken;

        $client->update(['is_active' => false]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/events')
            ->assertForbidden();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
