<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\User;
use App\Models\ZoneSoftApplication;
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
                'report_ends_at' => now()->addDays(2)->toDateTimeString(),
                'show_zt_card' => false,
            ]);

        $response->assertRedirect(route('admin.events.index'));

        $this->assertDatabaseHas('events', [
            'client_id' => $client->id,
            'title' => 'Evento de Teste',
            'is_active' => true,
            'show_zt_card' => false,
        ]);
    }

    public function test_admin_can_update_event_zt_card_visibility(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $clientUser = User::factory()->create([
            'role' => 'client',
        ]);

        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente ZT',
            'business_name' => null,
            'address' => 'Rua ZT',
            'phone' => '+351 922000000',
            'is_active' => true,
        ]);

        $event = Event::create([
            'client_id' => $client->id,
            'title' => 'Evento ZT',
            'description' => 'Descricao',
            'event_date' => now()->addDay(),
            'report_ends_at' => now()->addDays(2),
            'show_zt_card' => true,
            'is_active' => true,
        ]);

        $this
            ->actingAs($admin)
            ->put(route('admin.events.update', $event), [
                'client_id' => $client->id,
                'title' => 'Evento ZT editado',
                'description' => 'Descricao',
                'event_date' => now()->addDay()->toDateTimeString(),
                'report_ends_at' => now()->addDays(2)->toDateTimeString(),
                'show_zt_card' => false,
            ])
            ->assertRedirect(route('admin.events.index'));

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'title' => 'Evento ZT editado',
            'show_zt_card' => false,
        ]);
    }

    public function test_admin_events_index_counts_event_scoped_zonesoft_machines(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $clientUser = User::factory()->create([
            'role' => 'client',
        ]);

        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente Maquinas',
            'business_name' => null,
            'address' => 'Rua Maquinas',
            'phone' => '+351 921000000',
            'is_active' => true,
        ]);

        $event = Event::create([
            'client_id' => $client->id,
            'title' => 'Evento com Maquinas',
            'event_date' => now()->addDay(),
            'report_ends_at' => now()->addDays(2),
            'is_active' => true,
        ]);

        $application = ZoneSoftApplication::create([
            'name' => 'ZoneSoft Principal',
            'base_url' => 'https://api.zonesoft.org/v3',
            'app_key' => 'app-key-123',
            'app_secret' => 'secret-123',
            'is_active' => true,
        ]);

        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'CLIENTE-ZS-1',
            'store_id' => 10,
            'store_label' => 'Loja 10',
            'is_active' => true,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.events.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Events/Index')
                ->where('events.0.id', $event->id)
                ->where('events.0.available_machine_count', 1));
    }

    public function test_admin_client_dashboard_redirects_to_latest_active_event(): void
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

        $olderEvent = Event::create([
            'client_id' => $client->id,
            'title' => 'Evento Antigo',
            'description' => 'Descricao',
            'event_date' => now()->addDay(),
            'report_ends_at' => now()->addDays(2),
            'is_active' => true,
        ]);

        $latestEvent = Event::create([
            'client_id' => $client->id,
            'title' => 'Evento Recente',
            'description' => 'Descricao',
            'event_date' => now()->addDays(3),
            'report_ends_at' => now()->addDays(4),
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.clients.dashboard', $client));

        $response->assertRedirect(route('admin.events.dashboard', $latestEvent));
        $this->assertNotSame($olderEvent->id, $latestEvent->id);
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
        $event = Event::create([
            'client_id' => $client->id,
            'title' => 'Evento Delete',
            'event_date' => now(),
            'report_ends_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $application = ZoneSoftApplication::create([
            'name' => 'Aplicação Delete',
            'app_key' => 'delete-key',
            'app_secret' => 'delete-secret',
            'is_active' => true,
        ]);
        $machine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'delete-client-id',
            'license' => 'DELETE-LICENSE',
            'store_id' => 99,
            'is_active' => true,
        ]);
        $event->zonesoftMachines()->attach($machine->id);

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
        $this->assertDatabaseMissing('events', [
            'id' => $event->id,
        ]);
        $this->assertDatabaseMissing('client_zonesoft_machines', [
            'id' => $machine->id,
        ]);
        $this->assertDatabaseMissing('event_zonesoft_machines', [
            'event_id' => $event->id,
        ]);
        $this->assertDatabaseHas('zonesoft_applications', [
            'id' => $application->id,
        ]);
    }
}
