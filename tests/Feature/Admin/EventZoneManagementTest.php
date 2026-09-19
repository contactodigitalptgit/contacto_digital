<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportPaymentDocument;
use App\Models\EventReportRow;
use App\Models\EventZone;
use App\Models\EventZoneAssignment;
use App\Models\User;
use App\Models\ZoneSoftApplication;
use App\Services\EventReportSyncService;
use App\Services\EventZoneManagementService;
use App\Services\EventZoneReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class EventZoneManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_events_require_explicit_zones_from_creation(): void
    {
        [$admin, $client] = $this->eventContext();

        $this->actingAs($admin)
            ->post(route('admin.events.store'), [
                'client_id' => $client->id,
                'title' => 'Novo evento',
                'event_date' => '2026-10-01 18:00:00',
                'report_starts_at' => '2026-10-01 18:00:00',
                'report_ends_at' => '2026-10-02 04:00:00',
            ])
            ->assertRedirect(route('admin.events.index'));

        $this->assertTrue(Event::query()->where('title', 'Novo evento')->firstOrFail()->requires_explicit_zones);
    }

    public function test_new_tpa_stays_pending_and_blocks_sync_until_it_is_assigned(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $first = $this->machine($client, $event, 1, 'Bar 1 - Ana');
        $second = $this->machine($client, $event, 2, 'Bar 1 - Rui');

        $this->actingAs($admin)
            ->put(route('admin.events.tpas.sync', $event), ['machine_ids' => [$first->id, $second->id]])
            ->assertRedirect(route('admin.events.tpas.manage', $event));
        $this->assertDatabaseCount('event_zones', 0);
        $this->assertDatabaseCount('event_zone_assignments', 0);

        $zone = EventZone::create(['event_id' => $event->id, 'name' => 'Bar principal', 'sort_order' => 1]);
        $this->actingAs($admin)
            ->post(route('admin.events.zones.machines.assign', [$event, $zone]), [
                'machine_ids' => [$first->id],
                'effective_at' => '2026-09-18 18:00:00',
            ])
            ->assertRedirect(route('admin.events.zones.manage', $event));

        $this->assertSame(
            [$second->id],
            app(EventZoneReadinessService::class)->unassignedMachines($event->fresh())->pluck('id')->all(),
        );
        $this->actingAs($admin)
            ->get(route('admin.events.tpas.manage', $event))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('event.requires_explicit_zones', true)
                ->where('unassigned_machine_ids', [$second->id]));
        try {
            app(EventReportSyncService::class)->start($event->fresh(), $admin);
            $this->fail('A sincronização não pode começar com um TPA sem zona.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('TPAs sem zona', $exception->validator->errors()->first('integration'));
        }
        $this->assertDatabaseCount('event_report_imports', 0);

        $this->actingAs($admin)
            ->post(route('admin.events.zones.machines.assign', [$event, $zone]), [
                'machine_ids' => [$second->id],
                'effective_at' => '2026-09-18 18:00:00',
            ])
            ->assertRedirect(route('admin.events.zones.manage', $event));
        $this->assertDatabaseCount('event_zones', 1);
        $this->assertSame('processing', app(EventReportSyncService::class)->start($event->fresh(), $admin)->status);
    }

    public function test_admin_creates_zones_and_assigns_multiple_tpas_explicitly(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $first = $this->machine($client, $event, 1, 'Tpa 1 - Bar 1 Ana - POS 1');
        $second = $this->machine($client, $event, 2, 'Tpa 2 - Bar 1 Rui - POS 1');
        $third = $this->machine($client, $event, 3, 'Tpa 3 - Bar 3 - POS 1');

        $this->actingAs($admin)->get(route('admin.events.zones.manage', $event))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('zones', 0)
                ->has('unassigned_machines', 3));
        $this->assertDatabaseCount('event_zones', 0);

        foreach (['Bar 1', 'Bar 3'] as $name) {
            $this->actingAs($admin)
                ->post(route('admin.events.zones.store', $event), ['name' => $name])
                ->assertRedirect(route('admin.events.zones.manage', $event));
        }

        $barOne = EventZone::query()->where('event_id', $event->id)->where('name', 'Bar 1')->firstOrFail();
        $barThree = EventZone::query()->where('event_id', $event->id)->where('name', 'Bar 3')->firstOrFail();
        $this->actingAs($admin)
            ->post(route('admin.events.zones.machines.assign', [$event, $barOne]), [
                'machine_ids' => [$first->id, $second->id],
                'effective_at' => '2026-09-18 18:00:00',
            ])
            ->assertRedirect(route('admin.events.zones.manage', $event));
        $this->actingAs($admin)
            ->post(route('admin.events.zones.machines.assign', [$event, $barThree]), [
                'machine_ids' => [$third->id],
                'effective_at' => '2026-09-18 18:00:00',
            ])
            ->assertRedirect(route('admin.events.zones.manage', $event));

        $this->assertDatabaseHas('event_zones', ['event_id' => $event->id, 'name' => 'Bar 1']);
        $this->assertDatabaseHas('event_zones', ['event_id' => $event->id, 'name' => 'Bar 3']);
        $this->assertTrue($event->fresh()->requires_explicit_zones);
        $this->assertDatabaseCount('event_zones', 2);
        $this->assertDatabaseCount('event_zone_assignments', 3);

        $this->assertSame(
            [$first->id, $second->id],
            EventZoneAssignment::query()
                ->where('event_zone_id', $barOne->id)
                ->orderBy('machine_id')
                ->pluck('machine_id')
                ->all(),
        );
        $this->actingAs($admin)
            ->get(route('admin.events.zones.manage', $event))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Events/ManageZones')
                ->has('zones', 2)
                ->has('unassigned_machines', 0));
    }

    public function test_zone_move_splits_one_tpa_sales_by_effective_time_without_mobile_changes(): void
    {
        [$admin, $client, $event, $clientUser] = $this->eventContext();
        $machine = $this->machine($client, $event, 7, 'Tpa 7 - Bar 1 Ana - POS 1');
        $import = $this->import($event, $admin);

        EventReportRow::create($this->saleRow($event, $import, $machine, '2026-09-18 19:00:00', '300.0000', '1'));
        EventReportRow::create($this->saleRow($event, $import, $machine, '2026-09-18 22:00:00', '400000.0000', '2'));

        $manager = app(EventZoneManagementService::class);
        $barOne = EventZone::create([
            'event_id' => $event->id,
            'name' => 'Bar 1',
            'sort_order' => 1,
        ]);
        $barThree = EventZone::create([
            'event_id' => $event->id,
            'name' => 'Bar 3',
            'sort_order' => 2,
        ]);

        $manager->moveMachine($event, $barOne, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin);

        $manager->moveMachine(
            $event,
            $barThree,
            $machine,
            CarbonImmutable::parse('2026-09-18 21:30:00'),
            $admin,
        );

        $this->assertDatabaseHas('event_report_rows', [
            'document_number' => '1',
            'event_zone_id' => $barOne->id,
        ]);
        $this->assertDatabaseHas('event_report_rows', [
            'document_number' => '2',
            'event_zone_id' => $barThree->id,
        ]);

        $token = $clientUser->createToken('mobile-app')->plainTextToken;
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/events/{$event->id}/zones")
            ->assertOk()
            ->assertJsonPath('summary.total_sales', 400300)
            ->assertJsonCount(2, 'items');

        $zones = collect($response->json('items'))->keyBy('label');
        $this->assertEqualsWithDelta(300, $zones['Bar 1']['total_sales'], 0.0001);
        $this->assertEqualsWithDelta(400000, $zones['Bar 3']['total_sales'], 0.0001);
        $barThreeQuery = http_build_query(['bar_groups' => ['Bar 3']]);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/events/{$event->id}/zones?{$barThreeQuery}")
            ->assertOk()
            ->assertJsonPath('summary.total_sales', 400000)
            ->assertJsonPath('summary.devices_count', 1)
            ->assertJsonCount(1, 'items');
        $this->actingAs($clientUser)
            ->get(route('events.zones', $event).'?'.$barThreeQuery)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.total_sales', 400000)
                ->where('summary.bar_groups_count', 1)
                ->loadDeferredProps('dashboard-operational', fn (AssertableInertia $details) => $details
                    ->where('barGroups.0.label', 'Bar 3')
                    ->where('barGroups.0.sales_total', 400000)));
        $this->assertDatabaseHas('event_zone_assignments', [
            'event_id' => $event->id,
            'machine_id' => $machine->id,
            'event_zone_id' => $barOne->id,
            'ends_at' => '2026-09-18 21:30:00',
        ]);
        $this->assertDatabaseHas('event_zone_assignments', [
            'event_id' => $event->id,
            'machine_id' => $machine->id,
            'event_zone_id' => $barThree->id,
            'starts_at' => '2026-09-18 21:30:00',
            'ends_at' => null,
        ]);
        $this->actingAs($admin)
            ->get(route('admin.events.zones.manage', $event))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('history.0.zone', 'Bar 3')
                ->where('history.0.sales_total', 400000)
                ->where('history.1.zone', 'Bar 1')
                ->where('history.1.sales_total', 300));
    }

    public function test_corrected_machine_name_updates_report_data_without_changing_manually_assigned_zone(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $machine = $this->machine($client, $event, 193, 'Pausas Animadas - Lda');
        $import = $this->import($event, $admin);
        $row = EventReportRow::create([
            ...$this->saleRow($event, $import, $machine, '2026-09-18 19:00:00', '300.0000', '1'),
            'store_name' => 'Pausas Animadas - Lda - POS 1',
        ]);
        $payment = EventReportPaymentDocument::create([
            'event_id' => $event->id,
            'event_report_import_id' => $import->id,
            'machine_id' => $machine->id,
            'machine_client_id' => $machine->zs_client_id,
            'store_code' => (string) $machine->store_id,
            'store_name' => 'Pausas Animadas - Lda - POS 1',
            'sale_date' => '2026-09-18',
            'sale_datetime' => '2026-09-18 19:00:00',
            'doc_type' => 'FS',
            'document_series' => 'A2026',
            'document_number' => '1',
            'payment_key' => 'header',
            'payment_code' => '3',
            'total' => '300.0000',
            'dedupe_key' => 'label-correction',
        ]);
        $manager = app(EventZoneManagementService::class);
        $zone = EventZone::create([
            'event_id' => $event->id,
            'name' => 'Estacionamento',
            'sort_order' => 1,
        ]);
        $manager->moveMachine($event, $zone, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin);

        $machine->update(['store_label' => 'Estacionamento - Park 1']);
        $manager->synchronizeMachineLabel($machine->fresh(), 'Pausas Animadas - Lda');

        $this->assertSame('Estacionamento - Park 1 - POS 1', $row->fresh()->store_name);
        $this->assertSame('Estacionamento - Park 1 - POS 1', $payment->fresh()->store_name);
        $this->assertSame($zone->id, $row->fresh()->event_zone_id);
        $this->assertSame($zone->id, $payment->fresh()->event_zone_id);
        $this->assertDatabaseCount('event_zones', 1);
        $this->assertDatabaseHas('event_report_row_aggregates', [
            'event_id' => $event->id,
            'machine_id' => $machine->id,
            'event_zone_id' => $zone->id,
            'store_name' => 'Estacionamento - Park 1 - POS 1',
        ]);
    }

    public function test_clients_cannot_access_zone_management(): void
    {
        [, , $event, $clientUser] = $this->eventContext();

        $this->actingAs($clientUser)
            ->get(route('admin.events.zones.manage', $event))
            ->assertForbidden();
    }

    public function test_admin_can_manage_empty_zones_but_cannot_archive_a_zone_with_assigned_tpas(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $machine = $this->machine($client, $event, 1, 'Bar 1 - TPA 1');
        $barOne = EventZone::create([
            'event_id' => $event->id,
            'name' => 'Bar 1',
            'sort_order' => 1,
        ]);
        app(EventZoneManagementService::class)->moveMachine(
            $event, $barOne, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin,
        );

        $this->actingAs($admin)
            ->delete(route('admin.events.zones.destroy', [$event, $barOne]))
            ->assertSessionHasErrors('zone');
        $this->assertNull($barOne->fresh()->archived_at);

        $this->actingAs($admin)
            ->post(route('admin.events.zones.store', $event), ['name' => 'Bar Exterior'])
            ->assertRedirect(route('admin.events.zones.manage', $event));
        $exterior = EventZone::query()->where('event_id', $event->id)->where('name', 'Bar Exterior')->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('admin.events.zones.update', [$event, $exterior]), ['name' => 'Bar Jardim'])
            ->assertRedirect(route('admin.events.zones.manage', $event));
        $this->assertDatabaseHas('event_zones', ['id' => $exterior->id, 'name' => 'Bar Jardim']);

        $this->actingAs($admin)
            ->delete(route('admin.events.zones.destroy', [$event, $exterior]))
            ->assertRedirect(route('admin.events.zones.manage', $event));
        $this->assertNotNull($exterior->fresh()->archived_at);
    }

    /** @return array{0:User,1:Client,2:Event,3:User} */
    private function eventContext(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $clientUser = User::factory()->create(['role' => 'client']);
        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente Zonas',
            'address' => 'Lisboa',
            'phone' => '+351 930000000',
            'is_active' => true,
        ]);
        $event = Event::create([
            'client_id' => $client->id,
            'title' => 'Evento com zonas',
            'event_date' => '2026-09-18 18:00:00',
            'report_starts_at' => '2026-09-18 18:00:00',
            'report_ends_at' => '2026-09-19 04:00:00',
            'is_active' => true,
        ]);

        return [$admin, $client, $event, $clientUser];
    }

    private function machine(Client $client, Event $event, int $storeId, string $label): ClientZoneSoftMachine
    {
        $application = ZoneSoftApplication::query()->first() ?? ZoneSoftApplication::create([
            'name' => 'ZoneSoft',
            'base_url' => 'https://api.zonesoft.org/v3',
            'app_key' => 'test-key',
            'app_secret' => 'test-secret',
            'is_active' => true,
        ]);
        $machine = ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'CLIENT-'.$storeId,
            'store_id' => $storeId,
            'store_label' => $label,
            'is_active' => true,
        ]);
        $event->zonesoftMachines()->syncWithoutDetaching([$machine->id]);

        return $machine;
    }

    private function import(Event $event, User $user): EventReportImport
    {
        return EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $user->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'zone-history-'.$event->id),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['machines_count' => 1],
            'imported_rows_count' => 2,
            'imported_at' => now(),
            'is_active' => true,
            'status' => 'completed',
        ]);
    }

    /** @return array<string, mixed> */
    private function saleRow(
        Event $event,
        EventReportImport $import,
        ClientZoneSoftMachine $machine,
        string $soldAt,
        string $total,
        string $documentNumber,
    ): array {
        return [
            'event_id' => $event->id,
            'event_report_import_id' => $import->id,
            'machine_id' => $machine->id,
            'source_sheet' => 'zonesoft:test',
            'source_row_number' => (int) $documentNumber,
            'store_code' => (string) $machine->store_id,
            'store_name' => $machine->store_label,
            'sale_date' => '2026-09-18',
            'sale_datetime' => $soldAt,
            'doc_type' => 'FS',
            'document_series' => 'A2026',
            'document_number' => $documentNumber,
            'line_key' => 'line:'.$documentNumber,
            'value' => $total,
            'total' => $total,
            'discount' => '0.0000',
            'quantity' => '1.0000',
            'product_code' => 'P1',
            'description' => 'Produto',
        ];
    }
}
