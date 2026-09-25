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
use App\Models\EventZoneDay;
use App\Models\User;
use App\Models\ZoneSoftApplication;
use App\Services\EventReportSyncService;
use App\Services\EventZoneAttributionService;
use App\Services\EventZoneManagementService;
use App\Services\EventZoneReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class EventZoneManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_plans_preserve_yesterday_and_require_a_confirmed_plan_for_today(): void
    {
        [$admin, $client, $event, $clientUser] = $this->eventContext();
        $event->update(['report_ends_at' => '2026-09-20 06:00:00']);
        $machine = $this->machine($client, $event, 191, 'Bilheteira');
        $legacy = EventZone::create(['event_id' => $event->id, 'name' => 'Bilheteira', 'sort_order' => 1]);
        $manager = app(EventZoneManagementService::class);
        $manager->moveMachine($event, $legacy, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin);
        $import = $this->import($event, $admin);
        $yesterday = EventReportRow::create([
            ...$this->saleRow($event, $import, $machine, '2026-09-19 01:00:00', '100.0000', 'yesterday'),
            'event_zone_id' => $legacy->id,
        ]);
        $manager->closeLegacyAssignments($event, CarbonImmutable::parse('2026-09-19 06:00:00'));

        $this->assertSame('2026-09-19 06:00:00', EventZoneAssignment::query()
            ->where('event_zone_id', $legacy->id)->firstOrFail()->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame($legacy->id, $yesterday->fresh()->event_zone_id);
        $this->assertSame('2026-09-19 06:00:00', $event->fresh()->legacy_zone_ends_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($legacy->fresh()->archived_at);

        $this->actingAs($admin)->post(route('admin.events.zones.days.store', $event), [
            'operational_date' => '2026-09-19',
            'starts_at' => '2026-09-19 18:00:00',
            'ends_at' => '2026-09-20 06:00:00',
        ])->assertRedirect();
        $day = EventZoneDay::query()->where('event_id', $event->id)->firstOrFail();
        $this->actingAs($admin)->post(route('admin.events.zones.store', $event), [
            'name' => 'Bilheteira',
            'day_id' => $day->id,
        ])->assertSessionHasNoErrors();
        $today = $day->zones()->firstOrFail();
        $this->assertNotSame($legacy->id, $today->id);
        $manager->moveMachine($event, $today, $machine, CarbonImmutable::parse('2026-09-19 18:00:00'), $admin);

        $attribution = app(EventZoneAttributionService::class);
        $this->assertNull($attribution->zoneIdFor($event->id, $machine->id, '2026-09-19 19:00:00'));
        $this->actingAs($admin)->post(route('admin.events.zones.days.confirm', [$event, $day]))->assertRedirect();
        $this->assertNotNull($day->fresh()->confirmed_at);
        $attribution->forget($event->id);
        $this->assertSame($legacy->id, $attribution->zoneIdFor($event->id, $machine->id, '2026-09-19 01:00:00'));
        $this->assertNull($attribution->zoneIdFor($event->id, $machine->id, '2026-09-19 12:00:00'));
        $this->assertSame($today->id, $attribution->zoneIdFor($event->id, $machine->id, '2026-09-19 19:00:00'));
        $this->assertNull($attribution->zoneIdFor($event->id, $machine->id, '2026-09-20 06:00:00'));
        $this->assertSame('Bilheteira · anterior', $attribution->labelFor($event->id, $legacy->id, null));
        $this->assertSame('Bilheteira · 19/09/2026', $attribution->labelFor($event->id, $today->id, null));
        $this->assertSame([$today->id], $attribution->zoneIdsForLabels($event->id, ['Bilheteira · 19/09/2026']));
        EventReportRow::create([
            ...$this->saleRow($event, $import, $machine, '2026-09-19 19:00:00', '50.0000', 'today'),
            'event_zone_id' => $today->id,
        ]);
        app(EventReportSyncService::class)->refreshRowAggregates($event->id, [$machine->id]);
        $this->actingAs($clientUser)->getJson("/api/events/{$event->id}/zones")
            ->assertOk()
            ->assertJsonPath('summary.zones_count', 2)
            ->assertJsonFragment(['label' => 'Bilheteira · 19/09/2026']);

        $this->actingAs($admin)->get(route('admin.events.zones.manage', ['event' => $event, 'day' => $day->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected_day_id', $day->id)
                ->has('zones', 1)
                ->where('zones.0.name', 'Bilheteira'));
    }

    public function test_day_cannot_be_confirmed_with_a_missing_tpa_or_overlapping_period(): void
    {
        CarbonImmutable::setTestNow('2026-09-19 12:00:00');
        $this->beforeApplicationDestroyed(static fn () => CarbonImmutable::setTestNow());

        [$admin, $client, $event] = $this->eventContext();
        $event->update(['report_ends_at' => '2026-09-20 06:00:00']);
        $this->machine($client, $event, 191, 'Bilheteira');
        $this->actingAs($admin)->post(route('admin.events.zones.days.store', $event), [
            'operational_date' => '2026-09-19',
            'starts_at' => '2026-09-19 18:00:00',
            'ends_at' => '2026-09-20 06:00:00',
        ])->assertRedirect();
        $day = $event->zoneDays()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.events.zones.store', $event), [
            'name' => 'Bar', 'day_id' => $day->id,
        ])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.events.zones.days.confirm', [$event, $day]))
            ->assertSessionHasErrors('day');
        $this->assertNull($day->fresh()->confirmed_at);

        $this->actingAs($admin)->post(route('admin.events.zones.days.store', $event), [
            'operational_date' => '2026-09-20',
            'starts_at' => '2026-09-20 05:00:00',
            'ends_at' => '2026-09-20 06:00:00',
        ])->assertSessionHasErrors('starts_at');
        $this->assertDatabaseCount('event_zone_days', 1);
    }

    public function test_assigning_a_tpa_to_a_draft_day_does_not_rewrite_published_sales(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $event->update(['report_ends_at' => '2026-09-20 06:00:00']);
        $machine = $this->machine($client, $event, 191, 'Bilheteira');
        $legacy = EventZone::create(['event_id' => $event->id, 'name' => 'Bilheteira', 'sort_order' => 1]);
        $manager = app(EventZoneManagementService::class);
        $manager->moveMachine($event, $legacy, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin);
        $sale = EventReportRow::create([
            ...$this->saleRow($event, $this->import($event, $admin), $machine, '2026-09-19 19:00:00', '10.0000', 'draft'),
            'event_zone_id' => $legacy->id,
        ]);
        $day = $event->zoneDays()->create([
            'operational_date' => '2026-09-19',
            'starts_at' => '2026-09-19 18:00:00',
            'ends_at' => '2026-09-20 06:00:00',
        ]);
        $zone = $day->zones()->create(['event_id' => $event->id, 'name' => 'Nova bilheteira', 'sort_order' => 1]);

        $manager->moveMachine($event, $zone, $machine, CarbonImmutable::parse('2026-09-19 18:00:00'), $admin);

        $this->assertSame($legacy->id, $sale->fresh()->event_zone_id);
        $this->assertNull($day->fresh()->confirmed_at);
    }

    public function test_historical_first_day_is_reconciled_without_changing_second_day_sales_or_totals(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-19 18:00:00', 'Europe/Lisbon'));
        try {
            [$admin, $client, $event] = $this->eventContext();
            $event->update(['report_ends_at' => '2026-09-20 06:00:00']);
            $machine = $this->machine($client, $event, 191, 'Bar antigo - Ana');
            $legacy = EventZone::create(['event_id' => $event->id, 'name' => 'Zona errada', 'sort_order' => 1]);
            $manager = app(EventZoneManagementService::class);
            $manager->moveMachine($event, $legacy, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin);
            $import = $this->import($event, $admin);
            $firstSale = EventReportRow::create([
                ...$this->saleRow($event, $import, $machine, '2026-09-19 01:00:00', '100.0000', 'first'),
                'event_zone_id' => $legacy->id,
            ]);
            $secondSale = EventReportRow::create([
                ...$this->saleRow($event, $import, $machine, '2026-09-19 16:00:00', '50.0000', 'second'),
                'sale_date' => '2026-09-19',
                'event_zone_id' => $legacy->id,
            ]);
            $payment = EventReportPaymentDocument::create([
                'event_id' => $event->id,
                'event_report_import_id' => $import->id,
                'machine_id' => $machine->id,
                'machine_client_id' => $machine->zs_client_id,
                'store_code' => (string) $machine->store_id,
                'store_name' => 'Bar antigo - Ana',
                'sale_date' => '2026-09-19',
                'sale_datetime' => '2026-09-19 01:00:00',
                'doc_type' => 'FS',
                'document_series' => 'A2026',
                'document_number' => 'first',
                'payment_key' => 'header',
                'payment_code' => '3',
                'total' => '100.0000',
                'dedupe_key' => 'first-payment',
                'event_zone_id' => $legacy->id,
            ]);
            $firstDay = $event->zoneDays()->create([
                'operational_date' => '2026-09-18',
                'starts_at' => '2026-09-18 18:00:00',
                'ends_at' => '2026-09-19 06:00:00',
            ]);
            $firstZone = $firstDay->zones()->create(['event_id' => $event->id, 'name' => 'Bar antigo', 'sort_order' => 1]);
            $manager->moveMachine($event, $firstZone, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin);
            $this->assertSame($legacy->id, $firstSale->fresh()->event_zone_id);
            $this->assertSame($legacy->id, $secondSale->fresh()->event_zone_id);
            $this->assertTrue(app(EventZoneReadinessService::class)->isReady($event->fresh()));
            $this->assertSame($legacy->id, app(EventZoneAttributionService::class)->zoneIdFor(
                $event->id, $machine->id, '2026-09-19 01:00:00',
            ));

            $this->actingAs($admin)->post(route('admin.events.zones.days.confirm', [$event, $firstDay]))
                ->assertSessionHasNoErrors();
            $this->assertSame($firstZone->id, $firstSale->fresh()->event_zone_id);
            $this->assertSame($firstZone->id, $payment->fresh()->event_zone_id);
            $this->assertSame($legacy->id, $secondSale->fresh()->event_zone_id);
            $this->assertTrue(app(EventZoneReadinessService::class)->isReady($event->fresh()));

            $secondDay = $event->zoneDays()->create([
                'operational_date' => '2026-09-19',
                'starts_at' => '2026-09-19 15:30:00',
                'ends_at' => '2026-09-20 06:00:00',
            ]);
            $secondZone = $secondDay->zones()->create(['event_id' => $event->id, 'name' => 'Bar novo', 'sort_order' => 1]);
            $manager->moveMachine($event, $secondZone, $machine, CarbonImmutable::parse('2026-09-19 15:30:00'), $admin);
            $this->assertSame($legacy->id, $secondSale->fresh()->event_zone_id);
            $this->actingAs($admin)->post(route('admin.events.zones.days.confirm', [$event, $secondDay]))
                ->assertSessionHasNoErrors();
            $this->assertSame($secondZone->id, $secondSale->fresh()->event_zone_id);
            $this->assertSame(150.0, (float) EventReportRow::query()->where('event_id', $event->id)->sum('total'));

            $manager->closeLegacyAssignments($event, CarbonImmutable::parse('2026-09-19 06:00:00'));
            $this->assertNotNull($legacy->fresh()->archived_at);
            $attribution = app(EventZoneAttributionService::class);
            $attribution->forget($event->id);
            $this->assertNull($attribution->zoneIdFor($event->id, $machine->id, '2026-09-19 10:00:00'));
            $this->assertSame($firstZone->id, $firstSale->fresh()->event_zone_id);
            $this->assertSame($firstZone->id, $payment->fresh()->event_zone_id);
            $this->assertSame($secondZone->id, $secondSale->fresh()->event_zone_id);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_sync_is_blocked_when_a_new_operational_day_is_not_confirmed(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $event->update(['report_ends_at' => '2026-09-20 06:00:00']);
        $machine = $this->machine($client, $event, 191, 'Bilheteira');
        $day = $event->zoneDays()->create([
            'operational_date' => '2026-09-19',
            'starts_at' => '2026-09-19 18:00:00',
            'ends_at' => '2026-09-20 06:00:00',
        ]);
        $zone = $day->zones()->create(['event_id' => $event->id, 'name' => 'Bar de hoje', 'sort_order' => 1]);
        app(EventZoneManagementService::class)->moveMachine(
            $event, $zone, $machine, CarbonImmutable::parse('2026-09-19 18:00:00'), $admin,
        );

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-19 20:00:00', 'Europe/Lisbon'));
        try {
            $readiness = app(EventZoneReadinessService::class);
            $this->assertFalse($readiness->isReady($event->fresh()));
            $this->actingAs($admin)->post(route('admin.events.zones.days.confirm', [$event, $day]))
                ->assertSessionHasNoErrors();
            $this->assertTrue($readiness->isReady($event->fresh()));
            $this->machine($client, $event, 192, 'Outro TPA');
            $this->assertFalse($readiness->isReady($event->fresh()));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_legacy_close_refuses_to_change_a_period_with_later_sales(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $event->update(['report_ends_at' => '2026-09-20 06:00:00']);
        $machine = $this->machine($client, $event, 191, 'Bilheteira');
        $legacy = EventZone::create(['event_id' => $event->id, 'name' => 'Bilheteira', 'sort_order' => 1]);
        $manager = app(EventZoneManagementService::class);
        $manager->moveMachine($event, $legacy, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin);
        EventReportRow::create([
            ...$this->saleRow($event, $this->import($event, $admin), $machine, '2026-09-19 07:00:00', '10.0000', 'late'),
            'event_zone_id' => $legacy->id,
        ]);

        try {
            $manager->closeLegacyAssignments($event, CarbonImmutable::parse('2026-09-19 06:00:00'));
            $this->fail('O fecho não pode deslocar vendas posteriores para fora do histórico.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('vendas posteriores', $exception->validator->errors()->first('ends_at'));
        }
        $this->assertNull($event->fresh()->legacy_zone_ends_at);
        $this->assertNull(EventZoneAssignment::query()->where('event_zone_id', $legacy->id)->firstOrFail()->ends_at);
    }

    public function test_new_events_use_automatic_name_based_zones_from_creation(): void
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

        $this->assertFalse(Event::query()->where('title', 'Novo evento')->firstOrFail()->requires_explicit_zones);
    }

    public function test_automatic_mode_ignores_preserved_manual_zones_and_does_not_block_sync(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $machine = $this->machine($client, $event, 191, 'Bar REDBULL - Raquel C');
        $manualZone = EventZone::create([
            'event_id' => $event->id,
            'name' => 'Zona manual antiga',
            'sort_order' => 1,
        ]);
        $event->update(['requires_explicit_zones' => false]);

        $attribution = app(EventZoneAttributionService::class);

        $this->assertSame(
            'Bar REDBULL',
            $attribution->labelFor($event->id, $manualZone->id, 'Bar REDBULL - Raquel C - POS 1'),
        );
        $this->assertNull($attribution->zoneIdFor($event->id, $machine->id, '2026-09-18 20:00:00'));
        $this->assertTrue(app(EventZoneReadinessService::class)->isReady($event->fresh()));

        $import = $this->import($event, $admin);
        EventReportRow::create([
            ...$this->saleRow($event, $import, $machine, '2026-09-18 20:00:00', '75.0000', 'automatic'),
            'store_name' => 'Bar REDBULL - Raquel C - POS 1',
            'event_zone_id' => $manualZone->id,
        ]);
        app(EventReportSyncService::class)->refreshRowAggregates($event->id, [$machine->id]);
        $zoneQuery = http_build_query([
            'bar_groups' => ['Bar REDBULL'],
            'date_from' => '2026-09-18',
            'date_to' => '2026-09-18',
        ]);
        $this->actingAs($admin)
            ->get(route('admin.events.zones', $event).'?'.$zoneQuery)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.total_sales', 75)
                ->where('summary.bar_groups_count', 1)
                ->loadDeferredProps('dashboard-operational', fn (AssertableInertia $details) => $details
                    ->where('barGroups.0.label', 'Bar REDBULL')
                    ->where('barGroups.0.sales_total', 75)));

        $this->assertSame('processing', app(EventReportSyncService::class)->start($event->fresh(), $admin)->status);
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
        $barThreeQuery = http_build_query([
            'bar_groups' => ['Bar 3'],
            'date_from' => '2026-09-18',
            'date_to' => '2026-09-18',
        ]);
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

    public function test_new_tpa_label_does_not_rewrite_sales_from_a_confirmed_day(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $event->update(['report_ends_at' => '2026-09-20 06:00:00']);
        $machine = $this->machine($client, $event, 193, 'Bar antigo');
        $legacy = EventZone::create(['event_id' => $event->id, 'name' => 'Zona antiga', 'sort_order' => 1]);
        $manager = app(EventZoneManagementService::class);
        $manager->moveMachine($event, $legacy, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin);
        $day = $event->zoneDays()->create([
            'operational_date' => '2026-09-18',
            'starts_at' => '2026-09-18 18:00:00',
            'ends_at' => '2026-09-19 06:00:00',
            'confirmed_at' => now(),
        ]);
        $zone = $day->zones()->create(['event_id' => $event->id, 'name' => 'Bar de ontem', 'sort_order' => 1]);
        $manager->moveMachine($event, $zone, $machine, CarbonImmutable::parse('2026-09-18 18:00:00'), $admin);
        $import = $this->import($event, $admin);
        $yesterday = EventReportRow::create([
            ...$this->saleRow($event, $import, $machine, '2026-09-19 01:00:00', '100.0000', 'yesterday'),
            'store_name' => 'Bar antigo - POS 1',
            'event_zone_id' => $zone->id,
        ]);
        $today = EventReportRow::create([
            ...$this->saleRow($event, $import, $machine, '2026-09-19 16:00:00', '50.0000', 'today'),
            'store_name' => 'Bar antigo - POS 1',
            'event_zone_id' => $legacy->id,
        ]);

        $machine->update(['store_label' => 'Bar novo']);
        $manager->synchronizeMachineLabel($machine->fresh(), 'Bar antigo');

        $this->assertSame('Bar antigo - POS 1', $yesterday->fresh()->store_name);
        $this->assertSame('Bar novo - POS 1', $today->fresh()->store_name);
        $this->assertSame($zone->id, $yesterday->fresh()->event_zone_id);
        $this->assertSame($legacy->id, $today->fresh()->event_zone_id);
    }

    public function test_company_placeholder_is_repaired_only_when_the_current_store_matches_its_historical_zone(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        $somersby = EventZone::create(['event_id' => $event->id, 'name' => 'Bar Somersby', 'sort_order' => 1]);
        $matching = $this->machine($client, $event, 221, 'Bar Somersby - Pedro A');
        $mismatched = $this->machine($client, $event, 218, 'Bar VIP Ciroc - Tiago S');
        $import = $this->import($event, $admin);

        $matchingRow = EventReportRow::create([
            ...$this->saleRow($event, $import, $matching, '2026-09-19 16:00:00', '3.0000', '1'),
            'store_name' => 'Pausas Animadas - Lda - POS 1',
            'event_zone_id' => $somersby->id,
        ]);
        $mismatchedRow = EventReportRow::create([
            ...$this->saleRow($event, $import, $mismatched, '2026-09-18 20:00:00', '97.5000', '2'),
            'store_name' => 'Pausas Animadas - Lda - POS 1',
            'event_zone_id' => $somersby->id,
        ]);
        $payment = EventReportPaymentDocument::create([
            'event_id' => $event->id,
            'event_report_import_id' => $import->id,
            'machine_id' => $matching->id,
            'event_zone_id' => $somersby->id,
            'machine_client_id' => $matching->zs_client_id,
            'store_code' => (string) $matching->store_id,
            'store_name' => 'Pausas Animadas - Lda - POS 1',
            'sale_date' => '2026-09-19',
            'sale_datetime' => '2026-09-19 16:00:00',
            'doc_type' => 'FS',
            'document_series' => 'A2026',
            'document_number' => '1',
            'payment_key' => 'header',
            'payment_code' => '3',
            'total' => '3.0000',
            'dedupe_key' => 'company-placeholder-repair',
        ]);
        app(EventReportSyncService::class)->refreshRowAggregates($event->id, [$matching->id, $mismatched->id]);

        $migration = require database_path('migrations/2026_09_20_003000_repair_company_store_labels.php');
        $migration->up();

        $this->assertSame('Bar Somersby - Pedro A - POS 1', $matchingRow->fresh()->store_name);
        $this->assertSame('Bar Somersby - Pedro A - POS 1', $payment->fresh()->store_name);
        $this->assertSame('Pausas Animadas - Lda - POS 1', $mismatchedRow->fresh()->store_name);
        $this->assertDatabaseHas('event_report_row_aggregates', [
            'event_id' => $event->id,
            'machine_id' => $matching->id,
            'store_name' => 'Bar Somersby - Pedro A - POS 1',
        ]);
        $this->assertDatabaseHas('event_report_ticket_aggregates', [
            'event_id' => $event->id,
            'machine_id' => $matching->id,
            'store_name' => 'Bar Somersby - Pedro A - POS 1',
        ]);
        $this->assertDatabaseMissing('event_report_row_aggregates', [
            'event_id' => $event->id,
            'machine_id' => $matching->id,
            'store_name' => 'Pausas Animadas - Lda - POS 1',
        ]);
    }

    public function test_bloom_day_two_company_placeholders_are_repaired_from_the_preserved_store_plan(): void
    {
        [$admin, $client, $event] = $this->eventContext();
        DB::table('events')->where('id', $event->id)->update(['id' => 13]);
        $event = Event::query()->findOrFail(13);
        $import = $this->import($event, $admin);
        $ciroc = $this->machine($client, $event, 218, 'A current label that must not be used');
        $somersbyBeatriz = $this->machine($client, $event, 219, 'Another current label that must not be used');
        $somersbyPedro = $this->machine($client, $event, 221, 'A third current label that must not be used');
        $somersbyRodrigo = $this->machine($client, $event, 223, 'A fourth current label that must not be used');

        $cirocRow = EventReportRow::create([
            ...$this->saleRow($event, $import, $ciroc, '2026-09-19 15:30:00', '97.5000', '1'),
            'store_name' => 'Pausas Animadas - Lda - POS 1',
        ]);
        $beatrizRow = EventReportRow::create([
            ...$this->saleRow($event, $import, $somersbyBeatriz, '2026-09-20 01:00:00', '56.5000', '2'),
            'store_name' => 'Pausas Animadas - Lda - POS 1',
        ]);
        $pedroRow = EventReportRow::create([
            ...$this->saleRow($event, $import, $somersbyPedro, '2026-09-20 01:05:00', '3.0000', '5'),
            'store_name' => 'Pausas Animadas - Lda - POS 1',
        ]);
        $rodrigoRow = EventReportRow::create([
            ...$this->saleRow($event, $import, $somersbyRodrigo, '2026-09-20 01:10:00', '15.0000', '6'),
            'store_name' => 'Pausas Animadas - Lda - POS 1',
        ]);
        $firstDayRow = EventReportRow::create([
            ...$this->saleRow($event, $import, $ciroc, '2026-09-19 06:00:00', '10.0000', '3'),
            'store_name' => 'Pausas Animadas - Lda - POS 1',
        ]);
        $unmappedRow = EventReportRow::create([
            ...$this->saleRow($event, $import, $ciroc, '2026-09-19 16:00:00', '10.0000', '4'),
            'store_code' => '999',
            'store_name' => 'Pausas Animadas - Lda - POS 1',
        ]);
        $payment = EventReportPaymentDocument::create([
            'event_id' => $event->id,
            'event_report_import_id' => $import->id,
            'machine_id' => $somersbyBeatriz->id,
            'machine_client_id' => $somersbyBeatriz->zs_client_id,
            'store_code' => '219',
            'store_name' => 'Pausas Animadas - Lda - POS 1',
            'sale_date' => '2026-09-20',
            'sale_datetime' => '2026-09-20 01:00:00',
            'doc_type' => 'FS',
            'document_series' => 'A2026',
            'document_number' => '2',
            'payment_key' => 'header',
            'payment_code' => '3',
            'total' => '56.5000',
            'dedupe_key' => 'bloom-day-two-company-placeholder',
        ]);

        app(EventReportSyncService::class)->refreshRowAggregates($event->id, [
            $ciroc->id,
            $somersbyBeatriz->id,
            $somersbyPedro->id,
            $somersbyRodrigo->id,
        ]);

        $migration = require database_path('migrations/2026_09_20_010000_repair_bloom_day_two_store_labels.php');
        $migration->up();

        $this->assertSame('Bar VIP Ciroc - Tiago S - POS 1', $cirocRow->fresh()->store_name);
        $this->assertSame('Bar Somersby - Beatriz S - POS 1', $beatrizRow->fresh()->store_name);
        $this->assertSame('Bar Somersby - Pedro A - POS 1', $pedroRow->fresh()->store_name);
        $this->assertSame('Bar Somersby - Rodrigo P - POS 1', $rodrigoRow->fresh()->store_name);
        $this->assertSame('Bar Somersby - Beatriz S - POS 1', $payment->fresh()->store_name);
        $this->assertSame('Pausas Animadas - Lda - POS 1', $firstDayRow->fresh()->store_name);
        $this->assertSame('Pausas Animadas - Lda - POS 1', $unmappedRow->fresh()->store_name);
        $this->assertDatabaseHas('event_report_row_aggregates', [
            'event_id' => $event->id,
            'machine_id' => $ciroc->id,
            'store_name' => 'Bar VIP Ciroc - Tiago S - POS 1',
        ]);
        $this->assertDatabaseMissing('event_report_row_aggregates', [
            'event_id' => $event->id,
            'machine_id' => $ciroc->id,
            'store_name' => 'Pausas Animadas - Lda - POS 1',
            'store_code' => '218',
            'sale_calendar_date' => '2026-09-19',
            'sale_hour' => 15,
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
            'requires_explicit_zones' => true,
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
