<?php

namespace Tests\Feature\Admin;

use App\Jobs\PushZoneSoftStoreNamesJob;
use App\Models\Client;
use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventZone;
use App\Models\EventZoneAssignment;
use App\Models\User;
use App\Models\ZoneSoftApplication;
use App\Services\EventZoneManagementService;
use App\Services\ZoneSoft\ZoneSoftStoreNameService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ZoneSoftStoreNameTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_moving_a_tpa_stores_the_operator_and_queues_the_store_name(): void
    {
        Queue::fake();
        [$admin, $client, $event] = $this->eventContext();
        $machine = $this->machine($client, $event, 103, 'Loja 103');
        $zone = EventZone::create(['event_id' => $event->id, 'name' => 'Bar 1', 'sort_order' => 1]);

        $this->actingAs($admin)->post(route('admin.events.zones.machines.move', [$event, $zone, $machine]), [
            'effective_at' => '2026-09-18 18:00',
            'operator_name' => 'Ana Caldas',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Ana Caldas', EventZoneAssignment::query()->firstOrFail()->operator_name);
        Queue::assertPushed(
            PushZoneSoftStoreNamesJob::class,
            fn (PushZoneSoftStoreNamesJob $job): bool => $job->eventId === $event->id && $job->machineIds === [$machine->id],
        );
    }

    public function test_name_longer_than_the_zonesoft_limit_is_rejected(): void
    {
        Queue::fake();
        [$admin, $client, $event] = $this->eventContext();
        $machine = $this->machine($client, $event, 103, 'Loja 103');
        $zone = EventZone::create(['event_id' => $event->id, 'name' => 'Bar Principal do Recinto Norte', 'sort_order' => 1]);

        $this->actingAs($admin)->post(route('admin.events.zones.machines.move', [$event, $zone, $machine]), [
            'effective_at' => '2026-09-18 18:00',
            'operator_name' => 'Maria Margarida Fernandes',
        ])->assertSessionHasErrors('operator_name');

        $this->assertSame(0, EventZoneAssignment::query()->count());
    }

    public function test_operator_change_in_the_same_zone_splits_the_assignment(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $machine = $this->machine($client, $event, 103, 'Loja 103');
        $zone = EventZone::create(['event_id' => $event->id, 'name' => 'Bar 1', 'sort_order' => 1]);
        $manager = app(EventZoneManagementService::class);

        $manager->moveMachine($event, $zone, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin, 'Ana Caldas');
        $manager->moveMachine($event, $zone, $machine, CarbonImmutable::parse('2026-09-18 22:00:00'), $admin, 'Kleber Silva');
        $manager->moveMachine($event, $zone, $machine, CarbonImmutable::parse('2026-09-18 23:00:00'), $admin, 'Kleber Silva');

        $assignments = EventZoneAssignment::query()->orderBy('starts_at')->get();
        $this->assertCount(2, $assignments);
        $this->assertSame(['Ana Caldas', 'Kleber Silva'], $assignments->pluck('operator_name')->all());
        $this->assertSame('2026-09-18 22:00:00', $assignments[0]->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_push_sends_zone_and_operator_and_keeps_the_portal_label(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $machine = $this->machine($client, $event, 103, 'Loja 103');
        $zone = EventZone::create(['event_id' => $event->id, 'name' => 'Bar 1', 'sort_order' => 1]);
        app(EventZoneManagementService::class)
            ->moveMachine($event, $zone, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin, 'Ana Caldas');
        Http::fake([
            'https://api.zonesoft.org/v3/stores/saveInstances' => Http::response([
                'Response' => ['StatusCode' => 201, 'StatusMessage' => 'Created', 'Content' => []],
            ], 201),
        ]);

        $result = app(ZoneSoftStoreNameService::class)->push($event, CarbonImmutable::parse('2026-09-18 20:00:00'));

        $this->assertSame(['pushed' => 1, 'unchanged' => 0, 'failed' => 0], $result);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.zonesoft.org/v3/stores/saveInstances'
            && $request->data() === ['store' => [['codigo' => 103, 'descricao' => 'Bar 1 - Ana Caldas']]]);
        $machine->refresh();
        $this->assertSame('Bar 1 - Ana Caldas', $machine->store_label);
        $this->assertNotNull($machine->zonesoft_name_synced_at);
        $this->assertNull($machine->zonesoft_name_error);

        $again = app(ZoneSoftStoreNameService::class)->push($event, CarbonImmutable::parse('2026-09-18 21:00:00'));
        $this->assertSame(['pushed' => 0, 'unchanged' => 1, 'failed' => 0], $again);
        Http::assertSentCount(1);
    }

    public function test_failed_push_keeps_the_name_and_records_the_error(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $machine = $this->machine($client, $event, 103, 'Loja 103');
        $zone = EventZone::create(['event_id' => $event->id, 'name' => 'Bar 1', 'sort_order' => 1]);
        app(EventZoneManagementService::class)
            ->moveMachine($event, $zone, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin);
        Http::fake([
            '*' => Http::response(['Response' => ['StatusCode' => 403, 'StatusMessage' => 'Sem permissão']], 403),
        ]);

        $result = app(ZoneSoftStoreNameService::class)->push($event, CarbonImmutable::parse('2026-09-18 20:00:00'));

        $this->assertSame(1, $result['failed']);
        $machine->refresh();
        $this->assertSame('Loja 103', $machine->store_label);
        $this->assertStringContainsString('Sem permissão', (string) $machine->zonesoft_name_error);
    }

    public function test_manual_push_of_a_past_event_sends_the_last_names(): void
    {
        CarbonImmutable::setTestNow('2026-10-28 12:00:00');
        [$admin, $client, $event] = $this->eventContext();
        $machine = $this->machine($client, $event, 192, 'Bilheteira');
        $zone = EventZone::create(['event_id' => $event->id, 'name' => 'Bilheteira', 'sort_order' => 1]);
        app(EventZoneManagementService::class)
            ->moveMachine($event, $zone, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin);
        Http::fake(['*' => Http::response(['Response' => ['StatusCode' => 201, 'Content' => []]], 201)]);

        (new PushZoneSoftStoreNamesJob($event->id))->handle(app(ZoneSoftStoreNameService::class));
        Http::assertNothingSent();

        (new PushZoneSoftStoreNamesJob($event->id, null, null, true))->handle(app(ZoneSoftStoreNameService::class));
        Http::assertSent(fn (HttpRequest $request): bool => $request->data() === ['store' => [['codigo' => 192, 'descricao' => 'Bilheteira']]]);
        $this->assertNotNull($machine->fresh()->zonesoft_name_synced_at);
    }

    public function test_automatic_push_is_off_until_the_event_enables_it(): void
    {
        CarbonImmutable::setTestNow('2026-09-18 20:00:00');
        [$admin, $client, $event] = $this->eventContext();
        $event->update(['zonesoft_names_enabled' => false]);
        $machine = $this->machine($client, $event, 103, 'Bar 1 - Ana Caldas');
        $zone = EventZone::create(['event_id' => $event->id, 'name' => 'Bar 1', 'sort_order' => 1]);
        app(EventZoneManagementService::class)
            ->moveMachine($event, $zone, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin);
        Http::fake(['*' => Http::response(['Response' => ['StatusCode' => 201, 'Content' => []]], 201)]);

        (new PushZoneSoftStoreNamesJob($event->id))->handle(app(ZoneSoftStoreNameService::class));
        Http::assertNothingSent();

        Queue::fake();
        $this->actingAs($admin)
            ->patch(route('admin.events.zones.zonesoft-names.toggle', $event), ['enabled' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($event->fresh()->zonesoft_names_enabled);
        Queue::assertPushed(PushZoneSoftStoreNamesJob::class);

        (new PushZoneSoftStoreNamesJob($event->id))->handle(app(ZoneSoftStoreNameService::class));
        Http::assertSent(fn (HttpRequest $request): bool => $request->data() === ['store' => [['codigo' => 103, 'descricao' => 'Bar 1']]]);
    }

    public function test_admin_can_send_a_single_tpa_name(): void
    {
        Queue::fake();
        [$admin, $client, $event] = $this->eventContext();
        $machine = $this->machine($client, $event, 192, 'Bilheteira');

        $this->actingAs($admin)
            ->post(route('admin.events.zones.zonesoft-names.push', $event), ['machine_id' => $machine->id])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(
            PushZoneSoftStoreNamesJob::class,
            fn (PushZoneSoftStoreNamesJob $job): bool => $job->machineIds === [$machine->id] && $job->force,
        );
    }

    public function test_automatic_push_only_runs_while_the_event_is_running_or_about_to_start(): void
    {
        [, , $event] = $this->eventContext();
        $storeNames = app(ZoneSoftStoreNameService::class);

        $this->assertNull($storeNames->automaticReferenceAt($event, CarbonImmutable::parse('2026-09-10 12:00:00')));
        $this->assertSame(
            '2026-09-18 18:00:00',
            $storeNames->automaticReferenceAt($event, CarbonImmutable::parse('2026-09-18 10:00:00'))?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-09-18 20:00:00',
            $storeNames->automaticReferenceAt($event, CarbonImmutable::parse('2026-09-18 20:00:00'))?->format('Y-m-d H:i:s'),
        );
        $this->assertNull($storeNames->automaticReferenceAt($event, CarbonImmutable::parse('2026-09-20 12:00:00')));
    }

    public function test_excel_import_previews_and_applies_zones_operators_and_times(): void
    {
        Queue::fake();
        [$admin, $client, $event] = $this->eventContext();
        $first = $this->machine($client, $event, 103, 'Loja 103');
        $second = $this->machine($client, $event, 109, 'Loja 109');
        EventZone::create(['event_id' => $event->id, 'name' => 'Bar 1', 'sort_order' => 1]);
        $csv = UploadedFile::fake()->createWithContent('zonas.csv', implode("\n", [
            'Store ID,Zona,Operador,Início',
            '103,Bar 1,Ana Caldas,',
            '109,Bar 2,Bernardo Rodrigues,2026-09-18 21:00',
            '999,Bar 3,Ninguém,',
        ]));

        $preview = $this->actingAs($admin)
            ->post(route('admin.events.zones.import.preview', $event), ['file' => $csv])
            ->assertOk()
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.valid', 2)
            ->assertJsonPath('summary.new_zones', 1)
            ->assertJsonPath('rows.0.zonesoft_name', 'Bar 1 - Ana Caldas')
            ->assertJsonPath('rows.0.starts_at', '2026-09-18T18:00')
            ->assertJsonPath('rows.2.errors.0', 'Este Store ID não está nos TPAs do evento.')
            ->json();

        $this->actingAs($admin)->post(route('admin.events.zones.import.store', $event), [
            'rows' => collect($preview['rows'])->map(fn (array $row): array => [
                'store_id' => $row['store_id'],
                'zone' => $row['zone'],
                'operator' => $row['operator'],
                'starts_at' => $row['starts_at'],
            ])->all(),
        ])->assertSessionHasNoErrors();

        $assignments = EventZoneAssignment::query()->with('zone')->get()->keyBy('machine_id');
        $this->assertSame('Bar 1', $assignments[$first->id]->zone->name);
        $this->assertSame('Ana Caldas', $assignments[$first->id]->operator_name);
        $this->assertSame('Bar 2', $assignments[$second->id]->zone->name);
        $this->assertSame('2026-09-18 21:00:00', $assignments[$second->id]->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, EventZone::query()->where('event_id', $event->id)->count());
        Queue::assertPushed(PushZoneSoftStoreNamesJob::class);
    }

    public function test_import_reads_the_zonesoft_full_name_format(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $this->machine($client, $event, 103, 'Loja 103');
        $csv = UploadedFile::fake()->createWithContent('zonas.csv', "Loja\n103 - Bar 1 - Ana Caldas\n");

        $this->actingAs($admin)
            ->post(route('admin.events.zones.import.preview', $event), ['file' => $csv])
            ->assertOk()
            ->assertJsonPath('rows.0.store_id', 103)
            ->assertJsonPath('rows.0.zone', 'Bar 1')
            ->assertJsonPath('rows.0.operator', 'Ana Caldas')
            ->assertJsonPath('summary.valid', 1);
    }

    public function test_xlsx_import_accepts_time_only_cells(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $this->machine($client, $event, 105, 'Loja 105');
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['Store ID', 'Zona', 'Operador', 'Início'], [105, 'Redbull', 'Henrique Soares', null]]);
        $sheet->setCellValue('D2', 19 / 24);
        $sheet->getStyle('D2')->getNumberFormat()->setFormatCode('hh:mm');
        $path = tempnam(sys_get_temp_dir(), 'zonas').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $this->actingAs($admin)
            ->post(route('admin.events.zones.import.preview', $event), [
                'file' => new UploadedFile($path, 'zonas.xlsx', null, null, true),
            ])
            ->assertOk()
            ->assertJsonPath('rows.0.zonesoft_name', 'Redbull - Henrique Soares')
            ->assertJsonPath('rows.0.starts_at', '2026-09-18T19:00')
            ->assertJsonPath('rows.0.errors', []);
    }

    /** @return array{0: User, 1: Client, 2: Event} */
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
            'requires_explicit_zones' => true,
            'zonesoft_names_enabled' => true,
            'is_active' => true,
        ]);

        return [$admin, $client, $event];
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
}
