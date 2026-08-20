<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportPaymentDocument;
use App\Models\EventReportRow;
use App\Models\User;
use App\Models\ZoneSoftApplication;
use App\Services\DashboardConfigurationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class EventDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_dashboard_exposes_the_next_automatic_sync_countdown(): void
    {
        CarbonImmutable::setTestNow('2026-03-14 12:00:00');

        try {
            [$admin, $clientUser, $event] = $this->makeDashboardContext();
            $this->seedSyncedRows($event, $admin);

            $this->actingAs($clientUser)
                ->get(route('events.dashboard', $event))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('autoSync.enabled', true)
                    ->where('autoSync.state', 'scheduled')
                    ->where('autoSync.interval_minutes', 15)
                    ->where('autoSync.next_sync_at', now()->addMinutes(15)->toISOString()));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_client_dashboard_redirects_to_latest_active_event(): void
    {
        [, $clientUser, $olderEvent] = $this->makeDashboardContext();

        $latestEvent = Event::create([
            'client_id' => $olderEvent->client_id,
            'title' => 'Evento Mais Recente',
            'description' => 'Dashboard direto',
            'event_date' => now()->addDays(8),
            'report_starts_at' => now()->addDays(8),
            'report_ends_at' => now()->addDays(9),
            'is_active' => true,
        ]);

        $this
            ->actingAs($clientUser)
            ->get(route('dashboard'))
            ->assertRedirect(route('events.dashboard', $latestEvent));
    }

    public function test_client_can_view_event_dashboard_with_filters(): void
    {
        [$admin, $clientUser, $event] = $this->makeDashboardContext();

        $this->seedSyncedRows($event, $admin);

        $expectedRows = EventReportRow::query()
            ->where('event_id', $event->id)
            ->fromActiveImports()
            ->where('product_code', '730')
            ->whereDate('sale_date', '>=', '2026-03-14')
            ->whereDate('sale_date', '<=', '2026-03-14')
            ->where('total', '>=', 2)
            ->get()
            ->filter(fn (EventReportRow $row): bool => $this->resolveBarGroupLabel($row->store_name) === 'Bar 1')
            ->values();

        $expectedCount = $expectedRows->count();
        $expectedMembers = $expectedRows
            ->pluck('store_name')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $response = $this
            ->actingAs($clientUser)
            ->get(route('events.dashboard', $event).'?bar_group=Bar%201&product=730&date_from=2026-03-14&date_to=2026-03-14&total_min=2');

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Events/Dashboard')
            ->where('event.title', 'Evento Dashboard')
            ->where('integration.source', 'ZoneSoft API')
            ->where('integration.configured_client_ids_count', 2)
            ->where('filters.bar_group', 'Bar 1')
            ->where('filters.product', '730')
            ->where('summary.bar_groups_count', 1)
            ->where('summary.filtered_rows', $expectedCount)
            ->where('summary.tickets_count', $expectedCount)
            ->where('paymentSummary.source', 'documents_headers')
            ->where('paymentSummary.cash', 2.75)
            ->where('paymentSummary.zticket', 5.5)
            ->loadDeferredProps('dashboard-details', fn (AssertableInertia $details) => $details
                ->where('zoneDevices', fn ($zones): bool => collect($zones)->contains(
                    fn (array $zone): bool => $zone['label'] === 'Bar 1'
                        && count($zone['items']) === 2,
                ))
                ->where('barGroups', fn ($groups): bool => collect($groups)->contains(
                    fn (array $group): bool => $group['label'] === 'Bar 1'
                        && empty(array_diff($expectedMembers, $group['members']))
                        && $group['tickets_count'] === $expectedCount
                        && (float) $group['average_ticket'] === 4.125,
                )))
            ->reload(fn (AssertableInertia $details) => $details
                ->where('pagination.total', $expectedCount)
                ->where('documentTypes', fn ($documentTypes): bool => collect($documentTypes)->contains(
                    fn (array $documentType): bool => in_array($documentType['label'], ['FS', 'FT'], true),
                ))
                ->where('rows', fn ($rows): bool => collect($rows)->every(
                    fn (array $row): bool => $row['product_code'] === '730'
                        && $this->resolveBarGroupLabel($row['store_name']) === 'Bar 1',
                )), ['pagination', 'documentTypes', 'rows']));
    }

    public function test_event_dashboard_exposes_client_events_for_switcher(): void
    {
        [$admin, , $event] = $this->makeDashboardContext();

        $latestEvent = Event::create([
            'client_id' => $event->client_id,
            'title' => 'Evento Seguinte',
            'description' => 'Troca de evento',
            'event_date' => now()->addDays(10),
            'report_starts_at' => now()->addDays(10),
            'report_ends_at' => now()->addDays(11),
            'is_active' => true,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('admin.events.dashboard', $event))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Events/Dashboard')
                ->has('eventOptions', 2)
                ->where('eventOptions.0.id', $latestEvent->id)
                ->where('eventOptions.0.url', route('admin.events.dashboard', $latestEvent))
                ->where('eventOptions.1.id', $event->id)
                ->where('eventOptions.1.is_current', true));
    }

    public function test_dashboard_groups_embedded_bar_names_into_operational_zones(): void
    {
        [$admin, , $event] = $this->makeDashboardContext();
        $this->seedSyncedRows($event, $admin);
        $activeImport = $event->activeReportImports()->firstOrFail();
        $stores = [
            ['Tpa 6 - Bar 1 Rodolfo - POS 1', '6', '2.0000'],
            ['Tpa 3 - Bar 2 Vitor - POS 1', '3', '3.0000'],
            ['Tpa 13 - Bar 2 Claudia - POS 1', '13', '4.0000'],
            ['Tpa 8 - Bar Vip Alison - POS 1', '8', '5.0000'],
            ['Tpa 15 - Bar Vip Simao - POS 1', '15', '6.0000'],
        ];

        foreach ($stores as $index => [$storeName, $storeCode, $total]) {
            EventReportRow::create([
                'event_id' => $event->id,
                'event_report_import_id' => $activeImport->id,
                'source_sheet' => 'zonesoft:grouping-test',
                'source_row_number' => 100 + $index,
                'store_code' => $storeCode,
                'store_name' => $storeName,
                'sale_date' => '2026-03-14',
                'sale_datetime' => '2026-03-14 14:00:00',
                'doc_type' => 'FS',
                'document_series' => 'GROUP',
                'document_number' => (string) ($index + 1),
                'value' => $total,
                'total' => $total,
                'discount' => '0.0000',
                'quantity' => '1.0000',
                'product_code' => 'GROUP',
                'description' => 'Produto de teste',
                'raw_row' => ['index' => 100 + $index],
            ]);
        }

        $this
            ->actingAs($admin)
            ->get(route('admin.events.dashboard', $event))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps('dashboard-details', fn (AssertableInertia $details) => $details
                    ->where('barGroups', fn ($groups): bool => collect($groups)->contains(
                        fn (array $group): bool => $group['label'] === 'Bar 1'
                            && in_array('Tpa 6 - Bar 1 Rodolfo - POS 1', $group['members'], true),
                    ) && collect($groups)->contains(
                        fn (array $group): bool => $group['label'] === 'Bar 2'
                            && $group['stores_count'] === 2,
                    ) && collect($groups)->contains(
                        fn (array $group): bool => $group['label'] === 'Bar Vip'
                            && $group['stores_count'] === 2,
                    ))));
    }

    public function test_client_can_not_view_dashboard_of_other_client_event(): void
    {
        [, , $event] = $this->makeDashboardContext();

        $otherClientUser = User::factory()->create([
            'role' => 'client',
        ]);

        Client::create([
            'user_id' => $otherClientUser->id,
            'name' => 'Outro Cliente',
            'business_name' => null,
            'address' => 'Rua B',
            'phone' => '+351 930000010',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($otherClientUser)
            ->get(route('events.dashboard', $event));

        $response->assertNotFound();
    }

    public function test_admin_can_preview_event_dashboard(): void
    {
        [$admin, , $event] = $this->makeDashboardContext();

        $this->seedSyncedRows($event, $admin);

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.events.dashboard', $event));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Events/Dashboard')
            ->where('previewMode', true)
            ->where('dashboardEditor.enabled', true)
            ->where('dashboardEditor.edit_url', route('admin.events.dashboard-configuration.edit', $event))
            ->where('dashboardConfiguration.preset', 'complete')
            ->where('dashboardConfiguration.customized', false)
            ->where('dashboardConfiguration.sections.0.key', 'summary')
            ->where('backUrl', route('admin.events.index'))
            ->where('integration.source', 'ZoneSoft API')
            ->where('integration.configured_client_ids_count', 2)
            ->where('integration.machines_count', 2)
            ->where('event.show_zt_card', true)
            ->where('event.processing_imports_count', 0)
            ->where('summary.total_rows', 6)
            ->where('summary.processing_imports_count', 0)
            ->where('summary.tickets_count', 6)
            ->where('summary.document_types_count', 5)
            ->where('summary.bar_groups_count', 5)
            ->where('paymentSummary.source', 'documents_headers')
            ->where('paymentSummary.multibanco', 4.2)
            ->where('paymentSummary.cash', 4.55)
            ->where('paymentSummary.zticket', 5.5)
            ->where('paymentSummary.total_without_zt', 14.25)
            ->where('paymentSummary.top_up_documents_count', 1)
            ->where('paymentSummary.top_up_loaded', 2.75)
            ->where('paymentSummary.total_with_zt', 17)
            ->where('dailySales', fn ($days): bool => count($days) === 1
                && collect($days)->sum('sales_total') === 14.25)
            ->where('dailyBreakdowns', function ($days): bool {
                $firstDay = collect($days)->firstWhere('date', '2026-03-14');

                return count($days) === 1
                    && (float) ($firstDay['sales_total'] ?? 0) === 14.25
                    && (float) ($firstDay['multibanco'] ?? 0) === 4.2
                    && (float) ($firstDay['cash'] ?? 0) === 4.55
                    && (float) ($firstDay['zticket'] ?? 0) === 5.5
                    && (int) ($firstDay['tickets_count'] ?? 0) === 5
                    && (float) ($firstDay['average_ticket'] ?? 0) === 2.85
                    && (float) ($firstDay['top_up_loaded'] ?? 0) === 2.75
                    && (float) ($firstDay['total_with_zt'] ?? 0) === 17.0;
            })
            ->where('event.client_name', 'Cliente Dashboard')
            ->loadDeferredProps('dashboard-details', fn (AssertableInertia $details) => $details
                ->where('hourlySales', function ($hours): bool {
                    $firstDay = collect($hours)->first(fn (array $hour): bool => $hour['date'] === '2026-03-14'
                        && $hour['hour'] === 12);
                    $secondDay = collect($hours)->first(fn (array $hour): bool => $hour['date'] === '2026-03-15'
                        && $hour['hour'] === 12);

                    return count($hours) === 2
                        && (float) ($firstDay['sales_total'] ?? 0) === 13.25
                        && (int) ($firstDay['tickets_count'] ?? 0) === 4
                        && (float) ($secondDay['sales_total'] ?? 0) === 2.5
                        && (int) ($secondDay['tickets_count'] ?? 0) === 2;
                })
                ->where('productBreakdowns.total', fn ($products): bool => collect($products)->contains(
                    fn (array $product): bool => $product['label'] === 'Contactless'
                        && $product['code'] === 'ZT-CARD'
                        && (float) $product['quantity_total'] === 1.0
                        && (float) $product['sales_total'] === 2.75,
                ))
                ->where('productBreakdowns.days', fn ($days): bool => count($days) === 2)
                ->where('reconciliation.comparable', true)
                ->where('reconciliation.documents_count', 5)
                ->where('reconciliation.totals.payments_total', 14.25)
                ->where('reconciliation.totals.sales_total', 15.75)
                ->where('reconciliation.totals.difference', -1.5)
                ->where('comparison.available', false)
                ->where('zoneDevices', fn ($zones): bool => collect($zones)->contains(
                    fn (array $zone): bool => $zone['label'] === 'Bar 1'
                        && count($zone['items']) === 2,
                ))
                ->where('filterOptions.barGroups', fn ($groups): bool => collect($groups)->contains(
                    fn (array $group): bool => $group['value'] === 'Bar 1' && $group['rows_count'] > 0,
                ))
                ->where('barGroups', fn ($groups): bool => collect($groups)->contains(
                    fn (array $group): bool => $group['label'] === 'Bar 1'
                        && in_array('Bar 1 - Joao', $group['members'], true)
                        && in_array('Bar 1 Joana C', $group['members'], true),
                ))));
    }

    public function test_admin_has_a_dedicated_dashboard_configuration_page(): void
    {
        [$admin, $clientUser, $event] = $this->makeDashboardContext();

        $this->actingAs($admin)
            ->get(route('admin.events.dashboard-configuration.edit', $event))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Events/DashboardConfigurationEdit')
                ->where('event.id', $event->id)
                ->where('configuration.customized', false)
                ->has('presets', 4)
                ->where('updateUrl', route('admin.events.dashboard-configuration.update', $event))
                ->where('dashboardUrl', route('admin.events.dashboard', $event)));

        $this->actingAs($clientUser)
            ->get(route('admin.events.dashboard-configuration.edit', $event))
            ->assertForbidden();
    }

    public function test_admin_can_publish_an_event_specific_dashboard_configuration_without_changing_values(): void
    {
        [$admin, $clientUser, $event] = $this->makeDashboardContext();
        $this->seedSyncedRows($event, $admin);

        $otherEvent = Event::create([
            'client_id' => $event->client_id,
            'title' => 'Evento sem personalização',
            'event_date' => now()->addDays(10),
            'report_starts_at' => now()->addDays(10),
            'report_ends_at' => now()->addDays(11),
            'show_zt_card' => true,
            'is_active' => true,
        ]);

        $configuration = app(DashboardConfigurationService::class)->defaults($event);
        $configuration['preset'] = 'custom';
        [$configuration['sections'][0], $configuration['sections'][1]] = [
            $configuration['sections'][1],
            $configuration['sections'][0],
        ];
        $configuration['sections'][1]['label'] = 'Visão executiva';
        $configuration['blocks'][3]['visible'] = false;
        $configuration['metrics'][5]['label'] = 'Cartão bancário';

        $this
            ->actingAs($admin)
            ->from(route('admin.events.dashboard', $event))
            ->patch(route('admin.events.dashboard-configuration.update', $event), [
                'configuration' => $configuration,
            ])
            ->assertRedirect(route('admin.events.dashboard', $event))
            ->assertSessionHasNoErrors();

        $event->refresh();
        $otherEvent->refresh();

        $this->assertSame('custom', $event->dashboard_configuration['preset']);
        $this->assertTrue($event->dashboard_configuration['customized']);
        $this->assertSame('products', $event->dashboard_configuration['sections'][0]['key']);
        $this->assertSame('Visão executiva', $event->dashboard_configuration['sections'][1]['label']);
        $this->assertFalse($event->dashboard_configuration['blocks'][3]['visible']);
        $this->assertSame('Cartão bancário', $event->dashboard_configuration['metrics'][5]['label']);
        $this->assertNull($otherEvent->dashboard_configuration);

        $this->actingAs($clientUser)
            ->get(route('events.dashboard', $event))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('dashboardEditor', null)
                ->where('dashboardConfiguration.customized', true)
                ->where('dashboardConfiguration.sections.0.key', 'products')
                ->where('dashboardConfiguration.sections.1.label', 'Visão executiva')
                ->where('dashboardConfiguration.blocks.3.visible', false)
                ->where('dashboardConfiguration.metrics.5.label', 'Cartão bancário')
                ->where('summary.total_sales', 15.75)
                ->where('paymentSummary.total_without_zt', 14.25)
                ->where('paymentSummary.total_with_zt', 17));
    }

    public function test_client_can_not_publish_dashboard_configuration(): void
    {
        [, $clientUser, $event] = $this->makeDashboardContext();
        $configuration = app(DashboardConfigurationService::class)->defaults($event);

        $this->actingAs($clientUser)
            ->patch(route('admin.events.dashboard-configuration.update', $event), [
                'configuration' => $configuration,
            ])
            ->assertForbidden();

        $this->assertNull($event->fresh()->dashboard_configuration);
    }

    public function test_invalid_dashboard_configuration_is_rejected_and_can_be_restored(): void
    {
        [$admin, , $event] = $this->makeDashboardContext();
        $configuration = app(DashboardConfigurationService::class)->defaults($event);
        $configuration['preset'] = 'custom';
        $configuration['sections'] = array_map(
            fn (array $section): array => [...$section, 'visible' => false],
            $configuration['sections'],
        );

        $this->actingAs($admin)
            ->from(route('admin.events.dashboard', $event))
            ->patch(route('admin.events.dashboard-configuration.update', $event), [
                'configuration' => $configuration,
            ])
            ->assertRedirect(route('admin.events.dashboard', $event))
            ->assertSessionHasErrors('configuration');

        $this->assertNull($event->fresh()->dashboard_configuration);

        $validConfiguration = app(DashboardConfigurationService::class)->defaults($event);

        $this->actingAs($admin)
            ->from(route('admin.events.dashboard', $event))
            ->patch(route('admin.events.dashboard-configuration.update', $event), [
                'configuration' => $validConfiguration,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($event->fresh()->dashboard_configuration);

        $this->actingAs($admin)
            ->from(route('admin.events.dashboard', $event))
            ->patch(route('admin.events.dashboard-configuration.update', $event), [
                'configuration' => null,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($event->fresh()->dashboard_configuration);
    }

    public function test_dashboard_keeps_hourly_sales_for_an_eleven_day_event(): void
    {
        [$admin, , $event] = $this->makeDashboardContext();
        $this->seedSyncedRows($event, $admin);
        $event->update(['report_ends_at' => '2026-03-24 23:59:59']);
        $activeImport = $event->activeReportImports()->firstOrFail();

        foreach (range(16, 24) as $day) {
            EventReportRow::create([
                'event_id' => $event->id,
                'event_report_import_id' => $activeImport->id,
                'source_sheet' => 'zonesoft:long-event-test',
                'source_row_number' => 200 + $day,
                'store_code' => '1',
                'store_name' => 'Bar 1 - Joao',
                'sale_date' => "2026-03-{$day}",
                'sale_datetime' => "2026-03-{$day} 18:00:00",
                'doc_type' => 'FS',
                'document_series' => 'LONG2026',
                'document_number' => (string) $day,
                'value' => '10.0000',
                'total' => '10.0000',
                'discount' => '0.0000',
                'quantity' => '1.0000',
                'product_code' => 'LONG',
                'description' => 'Venda evento longo',
                'raw_row' => ['day' => $day],
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.events.dashboard', $event))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->loadDeferredProps('dashboard-details', fn (AssertableInertia $details) => $details
                    ->where('hourlySales', fn ($hours): bool => collect($hours)->pluck('date')->unique()->count() === 11
                        && collect($hours)->contains(fn (array $hour): bool => $hour['date'] === '2026-03-24'
                            && $hour['hour'] === 18
                            && (float) $hour['sales_total'] === 10.0))));
    }

    public function test_dashboard_hides_zt_product_breakdowns_when_event_disables_zt_card(): void
    {
        [$admin, , $event] = $this->makeDashboardContext();

        $event->update(['show_zt_card' => false]);
        $this->seedSyncedRows($event, $admin);

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.events.dashboard', $event));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Events/Dashboard')
            ->where('event.show_zt_card', false)
            ->where('paymentSummary.top_up_loaded', 2.75)
            ->loadDeferredProps('dashboard-details', fn (AssertableInertia $details) => $details
                ->where('productBreakdowns.total', fn ($products): bool => collect($products)->doesntContain(
                    fn (array $product): bool => $product['code'] === 'ZT-CARD',
                ))
                ->where('topProducts', fn ($products): bool => collect($products)->doesntContain(
                    fn (array $product): bool => $product['code'] === 'ZT-CARD',
                ))));
    }

    public function test_dashboard_compares_with_previous_synced_event(): void
    {
        [$admin, , $event] = $this->makeDashboardContext();

        $this->seedSyncedRows($event, $admin);

        $previousEvent = Event::create([
            'client_id' => $event->client_id,
            'title' => 'Evento Anterior',
            'description' => 'Evento para comparação',
            'event_date' => now()->subMonth(),
            'report_starts_at' => '2026-02-14 00:00:00',
            'report_ends_at' => '2026-02-14 23:59:59',
            'is_active' => true,
        ]);
        $previousImport = EventReportImport::create([
            'event_id' => $previousEvent->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'previous-dashboard-sync-'.$previousEvent->id),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => [
                'source' => 'zonesoft_api',
                'machines_count' => 1,
                'payment_documents' => [[
                    'store_code' => '1',
                    'store_name' => 'Bar 1 - Anterior',
                    'sale_date' => '2026-02-14',
                    'doc_type' => 'FS',
                    'document_series' => 'A2026',
                    'document_number' => '1',
                    'payment_code' => '3',
                    'total' => '10.0000',
                ]],
            ],
            'imported_rows_count' => 1,
            'imported_at' => now()->subMonth(),
            'is_active' => true,
            'status' => 'completed',
        ]);

        EventReportRow::create([
            'event_id' => $previousEvent->id,
            'event_report_import_id' => $previousImport->id,
            'source_sheet' => 'zonesoft:test',
            'source_row_number' => 1,
            'doc_type' => 'FS',
            'document_series' => 'A2026',
            'document_number' => '1',
            'store_code' => '1',
            'store_name' => 'Bar 1 - Anterior',
            'sale_date' => '2026-02-14',
            'sale_datetime' => '2026-02-14 12:00:00',
            'product_code' => '730',
            'description' => 'Agua',
            'quantity' => '2.0000',
            'value' => '8.8496',
            'discount' => '0.0000',
            'total' => '10.0000',
            'raw_row' => ['index' => 1],
        ]);

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.events.dashboard', $event));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps('dashboard-details', fn (AssertableInertia $details) => $details
                ->where('comparison.available', true)
                ->where('comparison.current.title', 'Evento Dashboard')
                ->where('comparison.current.total_sales', 15.75)
                ->where('comparison.previous.title', 'Evento Anterior')
                ->where('comparison.previous.total_sales', 10)
                ->where('comparison.total_variation', 57.5)
                ->where('comparison.metrics', fn ($metrics): bool => collect($metrics)->contains(
                    fn (array $metric): bool => $metric['key'] === 'machines_count'
                        && (float) $metric['current'] === 2.0
                        && (float) $metric['previous'] === 1.0
                        && (float) $metric['variation'] === 100.0,
                ))
                ->where('comparison.payments', fn ($payments): bool => collect($payments)->contains(
                    fn (array $payment): bool => $payment['key'] === 'multibanco'
                        && (float) $payment['current'] === 4.2
                        && (float) $payment['previous'] === 10.0
                        && (float) $payment['variation'] === -58.0,
                ))));
    }

    public function test_admin_preview_ignores_stale_processing_imports(): void
    {
        [$admin, , $event] = $this->makeDashboardContext();

        $this->seedSyncedRows($event, $admin);

        $staleImport = EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'stale-preview-sync-'.$event->id),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['source' => 'zonesoft_api', 'machines_count' => 0],
            'imported_rows_count' => 0,
            'is_active' => false,
            'status' => 'processing',
        ]);

        EventReportImport::query()
            ->whereKey($staleImport->id)
            ->update([
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ]);

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.events.dashboard', $event));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Events/Dashboard')
            ->where('event.processing_imports_count', 0)
            ->where('summary.processing_imports_count', 0));

        $this->assertSame(1, EventReportImport::query()->where('status', 'failed')->count());
    }

    public function test_dashboard_exposes_a_safe_warning_after_a_failed_sync(): void
    {
        [$admin, $clientUser, $event] = $this->makeDashboardContext();
        $this->seedSyncedRows($event, $admin);

        EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'failed-dashboard-sync-'.$event->id),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => [
                'source' => 'zonesoft_api',
                'stage' => 'fetching',
                'machines_total' => 43,
                'machines_processed' => 9,
                'documents_processed' => 721,
                'error' => 'cURL error 6: Could not resolve host: api.zonesoft.org for CLIENT-ID-SECRET',
            ],
            'imported_rows_count' => 0,
            'is_active' => false,
            'status' => 'failed',
        ]);

        $this->actingAs($clientUser)
            ->get(route('events.dashboard', $event))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('syncStatus.status', 'failed')
                ->where('syncStatus.is_stale', true)
                ->where('syncStatus.machines_total', 43)
                ->where('syncStatus.machines_processed', 9)
                ->where('syncStatus.documents_processed', 721)
                ->where(
                    'syncStatus.message',
                    'A última tentativa não conseguiu comunicar com a ZoneSoft. Os valores apresentados são da última sincronização válida.',
                ));
    }

    public function test_dashboard_exposes_processing_progress_without_replacing_valid_values(): void
    {
        [$admin, , $event] = $this->makeDashboardContext();
        $this->seedSyncedRows($event, $admin);

        EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'processing-dashboard-sync-'.$event->id),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => [
                'source' => 'zonesoft_api',
                'stage' => 'fetching',
                'machines_total' => 43,
                'machines_processed' => 17,
                'documents_processed' => 1840,
            ],
            'imported_rows_count' => 0,
            'is_active' => false,
            'status' => 'processing',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.events.dashboard', $event))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('syncStatus.status', 'processing')
                ->where('syncStatus.is_stale', false)
                ->where('syncStatus.stage', 'fetching')
                ->where('syncStatus.machines_total', 43)
                ->where('syncStatus.machines_processed', 17)
                ->where('syncStatus.documents_processed', 1840)
                ->where('summary.total_sales', 15.75));
    }

    /**
     * @return array{0: User, 1: User, 2: Event}
     */
    private function makeDashboardContext(): array
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $clientUser = User::factory()->create([
            'role' => 'client',
        ]);

        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente Dashboard',
            'business_name' => 'Operacao Dashboard',
            'address' => 'Rua Dashboard',
            'phone' => '+351 930000002',
            'is_active' => true,
        ]);

        $event = Event::create([
            'client_id' => $client->id,
            'title' => 'Evento Dashboard',
            'description' => 'Analitico de evento',
            'event_date' => now()->addDays(4),
            'report_starts_at' => '2026-03-14 00:00:00',
            'report_ends_at' => '2026-03-15 23:59:59',
            'is_active' => true,
        ]);

        return [$admin, $clientUser, $event];
    }

    private function seedSyncedRows(Event $event, User $admin): void
    {
        $application = ZoneSoftApplication::create([
            'name' => 'ZoneSoft Principal',
            'base_url' => 'https://api.zonesoft.org/v3',
            'app_key' => 'app-key-dashboard',
            'app_secret' => 'secret-dashboard',
            'is_active' => true,
        ]);

        ClientZoneSoftMachine::create([
            'client_id' => $event->client_id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'CLIENT-ID-001',
            'license' => 'Z11JSMZIYP',
            'store_id' => 1,
            'store_label' => 'Bar 1 - Joao',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        ClientZoneSoftMachine::create([
            'client_id' => $event->client_id,
            'event_id' => $event->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'CLIENT-ID-002',
            'license' => 'Z11JSMZIYP',
            'store_id' => 2,
            'store_label' => 'Bar 2 - Ines',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => now(),
        ]);

        $sync = EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'dashboard-sync-'.$event->id),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => [
                'source' => 'zonesoft_api',
                'machines_count' => 2,
                'salesday_records' => [
                    [
                        'store_code' => '1',
                        'store_name' => 'Bar 1 - Joao',
                        'sale_date' => '2026-03-14',
                        'sale_datetime' => '2026-03-14 12:00:00',
                        'cash_register_code' => '1',
                        'is_closed' => true,
                        'fs' => '2.7500',
                        'ft' => '0.0000',
                        'tk' => '0.0000',
                        'vd' => '0.0000',
                        'enc' => '0.0000',
                        'nc' => '0.0000',
                        'rc' => '0.0000',
                        'movimento' => '2.7500',
                        'num' => '2.7500',
                        'deb' => '0.0000',
                        'crd' => '0.0000',
                        'chq' => '0.0000',
                        'cartoes' => '0.0000',
                        'etk' => '0.0000',
                    ],
                    [
                        'store_code' => '1',
                        'store_name' => 'Bar 1 - Joao',
                        'sale_date' => '2026-03-14',
                        'cash_register_code' => '2',
                        'is_closed' => true,
                        'fs' => '0.0000',
                        'ft' => '5.5000',
                        'tk' => '0.0000',
                        'vd' => '0.0000',
                        'enc' => '0.0000',
                        'nc' => '0.0000',
                        'rc' => '0.0000',
                        'movimento' => '5.5000',
                        'num' => '0.0000',
                        'deb' => '5.5000',
                        'crd' => '0.0000',
                        'chq' => '0.0000',
                        'cartoes' => '0.0000',
                        'etk' => '0.0000',
                    ],
                    [
                        'store_code' => '2',
                        'store_name' => 'Top Up 1 - POS 1',
                        'sale_date' => '2026-03-14',
                        'cash_register_code' => '1',
                        'is_closed' => false,
                        'fs' => '0.0000',
                        'ft' => '2.7500',
                        'tk' => '0.0000',
                        'vd' => '0.0000',
                        'enc' => '0.0000',
                        'nc' => '0.0000',
                        'rc' => '0.0000',
                        'movimento' => '2.7500',
                        'num' => '0.0000',
                        'deb' => '0.0000',
                        'crd' => '2.7500',
                        'chq' => '0.0000',
                        'cartoes' => '0.0000',
                        'etk' => '0.0000',
                    ],
                    [
                        'store_code' => '4',
                        'store_name' => 'Bar 4 - Ana',
                        'sale_date' => '2026-03-15',
                        'cash_register_code' => '1',
                        'is_closed' => true,
                        'fs' => '0.0000',
                        'ft' => '0.0000',
                        'tk' => '1.0000',
                        'vd' => '0.0000',
                        'enc' => '0.0000',
                        'nc' => '0.0000',
                        'rc' => '0.0000',
                        'movimento' => '1.0000',
                        'num' => '1.0000',
                        'deb' => '0.0000',
                        'crd' => '0.0000',
                        'chq' => '0.0000',
                        'cartoes' => '0.0000',
                        'etk' => '0.0000',
                    ],
                ],
                'payment_documents' => [
                    [
                        'store_code' => '1',
                        'store_name' => 'Bar 1 - Joao',
                        'sale_date' => '2026-03-14',
                        'doc_type' => 'FS',
                        'document_series' => 'A2026',
                        'document_number' => '1',
                        'payment_code' => '1',
                        'total' => '2.7500',
                    ],
                    [
                        'store_code' => '1',
                        'store_name' => 'Bar 1 Joana C',
                        'sale_date' => '2026-03-14',
                        'sale_datetime' => '2026-03-14 12:05:00',
                        'doc_type' => 'FT',
                        'document_series' => 'A2026',
                        'document_number' => '2',
                        'payment_code' => '56',
                        'total' => '5.5000',
                    ],
                    [
                        'store_code' => '2',
                        'store_name' => 'Top Up 1 - POS 1',
                        'sale_date' => '2026-03-14',
                        'sale_datetime' => '2026-03-14 12:10:00',
                        'doc_type' => 'FS',
                        'document_series' => 'A2026',
                        'document_number' => '3',
                        'payment_code' => '3',
                        'total' => '3.2000',
                    ],
                    [
                        'store_code' => '3',
                        'store_name' => 'Bar 3 - Luis',
                        'sale_date' => '2026-03-14',
                        'sale_datetime' => '2026-03-14 12:15:00',
                        'doc_type' => 'VD',
                        'document_series' => 'A2026',
                        'document_number' => '4',
                        'payment_code' => '1',
                        'total' => '1.8000',
                    ],
                    [
                        'store_code' => '4',
                        'store_name' => 'Bar 4 - Ana',
                        'sale_date' => '2026-03-14',
                        'sale_datetime' => '2026-03-15 00:05:00',
                        'doc_type' => 'TK',
                        'document_series' => 'A2026',
                        'document_number' => '5',
                        'payment_code' => '3',
                        'total' => '1.0000',
                    ],
                    [
                        'store_code' => '5',
                        'store_name' => 'BC TOP BAR 2 - POS 1',
                        'sale_date' => '2026-03-14',
                        'sale_datetime' => '2026-03-15 00:10:00',
                        'doc_type' => 'ZT',
                        'document_series' => 'A2026',
                        'document_number' => '6',
                        'payment_code' => '1',
                        'total' => '2.7500',
                    ],
                ],
            ],
            'imported_rows_count' => 7,
            'imported_at' => now(),
            'is_active' => true,
            'status' => 'completed',
        ]);

        $summary = $sync->summary;
        $paymentDocuments = $summary['payment_documents'];

        foreach ($paymentDocuments as $index => $document) {
            EventReportPaymentDocument::create([
                ...$document,
                'event_id' => $event->id,
                'event_report_import_id' => $sync->id,
                'dedupe_key' => hash('sha256', implode('|', [
                    $document['store_code'] ?? '',
                    $document['doc_type'] ?? '',
                    $document['document_series'] ?? '',
                    $document['document_number'] ?? '',
                    $document['payment_code'] ?? '',
                    $index,
                ])),
            ]);
        }

        unset($summary['payment_documents']);
        $summary['payment_documents_count'] = count($paymentDocuments);
        $sync->update(['summary' => $summary]);

        $rows = [
            ['doc_type' => 'FS', 'store_code' => '1', 'store_name' => 'Bar 1 - Joao', 'sale_date' => '2026-03-14', 'sale_datetime' => '2026-03-14 12:00:00', 'product_code' => '730', 'description' => 'Agua', 'quantity' => '1.0000', 'value' => '2.4336', 'discount' => '0.0000', 'total' => '2.7500'],
            ['doc_type' => 'FT', 'store_code' => '1', 'store_name' => 'Bar 1 Joana C', 'sale_date' => '2026-03-14', 'sale_datetime' => '2026-03-14 12:05:00', 'product_code' => '730', 'description' => 'Agua', 'quantity' => '2.0000', 'value' => '4.8673', 'discount' => '0.0000', 'total' => '5.5000'],
            ['doc_type' => 'FS', 'store_code' => '2', 'store_name' => 'Top Up 1 - POS 1', 'sale_date' => '2026-03-14', 'sale_datetime' => '2026-03-14 12:10:00', 'product_code' => '731', 'description' => 'Cerveja', 'quantity' => '1.0000', 'value' => '2.9200', 'discount' => '0.0000', 'total' => '3.2000'],
            ['doc_type' => 'VD', 'store_code' => '3', 'store_name' => 'Bar 3 - Luis', 'sale_date' => '2026-03-14', 'sale_datetime' => '2026-03-14 12:15:00', 'product_code' => '732', 'description' => 'Sumo', 'quantity' => '1.0000', 'value' => '1.5000', 'discount' => '0.0000', 'total' => '1.8000'],
            ['doc_type' => 'TK', 'store_code' => '4', 'store_name' => 'Bar 4 - Ana', 'sale_date' => '2026-03-15', 'sale_datetime' => '2026-03-15 12:00:00', 'product_code' => '733', 'description' => 'Cafe', 'quantity' => '1.0000', 'value' => '0.8000', 'discount' => '0.0000', 'total' => '1.0000'],
            ['doc_type' => 'ZT', 'store_code' => '5', 'store_name' => 'BC TOP BAR 2 - POS 1', 'sale_date' => '2026-03-15', 'sale_datetime' => '2026-03-15 12:05:00', 'product_code' => '730', 'description' => 'Agua', 'quantity' => '1.0000', 'value' => '2.4336', 'discount' => '0.0000', 'total' => '2.7500'],
            ['doc_type' => null, 'store_code' => null, 'store_name' => null, 'sale_date' => '2026-03-15', 'sale_datetime' => '2026-03-15 12:10:00', 'product_code' => '734', 'description' => 'Snack', 'quantity' => '1.0000', 'value' => '1.2000', 'discount' => '0.0000', 'total' => '1.5000'],
        ];

        foreach ($rows as $index => $row) {
            EventReportRow::create([
                'event_id' => $event->id,
                'event_report_import_id' => $sync->id,
                'source_sheet' => 'zonesoft:test',
                'source_row_number' => $index + 1,
                'doc_type' => $row['doc_type'],
                'document_series' => 'A2026',
                'document_number' => (string) ($index + 1),
                'raw_row' => ['index' => $index + 1],
                ...$row,
            ]);
        }
    }

    private function resolveBarGroupLabel(?string $storeName): string
    {
        if ($storeName === null || trim($storeName) === '') {
            return 'Sem loja';
        }

        if (preg_match('/^(bar\s+\d+)/i', $storeName, $matches) === 1) {
            return ucwords(strtolower($matches[1]));
        }

        return trim($storeName);
    }
}
