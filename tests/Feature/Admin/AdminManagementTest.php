<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_client_with_credentials(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $response = $this
            ->actingAs($admin)
            ->post(route('admin.clients.store'), [
                'name' => 'Cliente Exemplo',
                'business_name' => 'Loja Exemplo',
                'address' => 'Rua Principal, 123',
                'phone' => '+351 910000000',
                'email' => 'cliente@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertRedirect(route('admin.clients.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'cliente@example.com',
            'role' => 'client',
        ]);

        $this->assertDatabaseHas('clients', [
            'name' => 'Cliente Exemplo',
            'business_name' => 'Loja Exemplo',
            'phone' => '+351 910000000',
            'is_active' => true,
        ]);
    }

    public function test_client_can_not_access_admin_clients_crud(): void
    {
        $clientUser = User::factory()->create([
            'role' => 'client',
        ]);

        $response = $this
            ->actingAs($clientUser)
            ->get(route('admin.clients.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_create_events_for_clients(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $clientUser = User::factory()->create([
            'role' => 'client',
        ]);

        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente Evento',
            'business_name' => null,
            'address' => 'Rua do Evento',
            'phone' => '+351 920000000',
        ]);

        $response = $this
            ->actingAs($admin)
            ->post(route('admin.events.store'), [
                'client_id' => $client->id,
                'title' => 'Evento de Teste',
                'description' => 'Descricao',
                'event_date' => now()->addDay()->toDateTimeString(),
            ]);

        $response->assertRedirect(route('admin.events.index'));

        $this->assertDatabaseHas('events', [
            'client_id' => $client->id,
            'title' => 'Evento de Teste',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_view_client_dashboard_preview(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $clientUser = User::factory()->create([
            'role' => 'client',
        ]);

        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente Preview',
            'business_name' => 'Empresa Preview',
            'address' => 'Rua Preview',
            'phone' => '+351 925000000',
            'is_active' => true,
        ]);

        Event::create([
            'client_id' => $client->id,
            'title' => 'Evento Preview',
            'description' => 'Descricao',
            'event_date' => now()->addDays(2),
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.clients.dashboard', $client));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('type', 'client')
            ->where('previewMode', true)
            ->where('client.name', 'Cliente Preview')
            ->where('events.0.title', 'Evento Preview'));
    }

    public function test_admin_can_toggle_event_status_without_deleting_data(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $clientUser = User::factory()->create([
            'role' => 'client',
        ]);

        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente Evento Status',
            'business_name' => null,
            'address' => 'Rua Evento Status',
            'phone' => '+351 960000000',
            'is_active' => true,
        ]);

        $event = Event::create([
            'client_id' => $client->id,
            'title' => 'Evento Ativo',
            'description' => 'Descricao',
            'event_date' => now()->addDay(),
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($admin)
            ->patch(route('admin.events.toggle-status', $event), [
                'is_active' => false,
            ]);

        $response->assertRedirect(route('admin.events.index'));

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'is_active' => false,
        ]);
    }

    public function test_admin_can_toggle_client_status_without_deleting_data(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $clientUser = User::factory()->create([
            'role' => 'client',
        ]);

        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente Status',
            'business_name' => null,
            'address' => 'Rua Status',
            'phone' => '+351 930000000',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($admin)
            ->patch(route('admin.clients.toggle-status', $client), [
                'is_active' => false,
            ]);

        $response->assertRedirect(route('admin.clients.index'));

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $clientUser->id,
            'email' => $clientUser->email,
        ]);
    }

    public function test_admin_can_delete_client_records(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $clientUser = User::factory()->create([
            'role' => 'client',
        ]);

        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente Delete',
            'business_name' => null,
            'address' => 'Rua Delete',
            'phone' => '+351 950000000',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($admin)
            ->delete(route('admin.clients.destroy', $client));

        $response->assertRedirect(route('admin.clients.index'));

        $this->assertDatabaseMissing('clients', [
            'id' => $client->id,
        ]);

        $this->assertDatabaseMissing('users', [
            'id' => $clientUser->id,
        ]);
    }
}
