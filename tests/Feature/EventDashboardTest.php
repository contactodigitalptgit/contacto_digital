<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportRow;
use App\Models\User;
use App\Models\ZoneSoftApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class EventDashboardTest extends TestCase
{
    use RefreshDatabase;

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
            ->where('pagination.total', $expectedCount)
            ->where('barGroups', fn ($groups): bool => collect($groups)->contains(
                fn (array $group): bool => $group['label'] === 'Bar 1'
                    && empty(array_diff($expectedMembers, $group['members'])),
            ))
            ->where('rows', fn ($rows): bool => collect($rows)->every(
                fn (array $row): bool => $row['product_code'] === '730'
                    && $this->resolveBarGroupLabel($row['store_name']) === 'Bar 1',
            )));
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
            ->where('backUrl', route('admin.events.index'))
            ->where('integration.source', 'ZoneSoft API')
            ->where('integration.configured_client_ids_count', 2)
            ->where('integration.machines_count', 2)
            ->where('summary.total_rows', 7)
            ->where('summary.bar_groups_count', 6)
            ->where('filterOptions.barGroups', fn ($groups): bool => collect($groups)->contains(
                fn (array $group): bool => $group['value'] === 'Bar 1' && $group['rows_count'] > 0,
            ))
            ->where('barGroups', fn ($groups): bool => collect($groups)->contains(
                fn (array $group): bool => $group['label'] === 'Bar 1'
                    && in_array('Bar 1 - Joao', $group['members'], true)
                    && in_array('Bar 1 Joana C', $group['members'], true),
            ))
            ->where('event.client_name', 'Cliente Dashboard'));
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
            'summary' => ['source' => 'zonesoft_api', 'machines_count' => 2],
            'imported_rows_count' => 7,
            'imported_at' => now(),
            'is_active' => true,
            'status' => 'completed',
        ]);

        $rows = [
            ['store_code' => '1', 'store_name' => 'Bar 1 - Joao', 'sale_date' => '2026-03-14', 'sale_datetime' => '2026-03-14 12:00:00', 'product_code' => '730', 'description' => 'Agua', 'quantity' => '1.0000', 'value' => '2.4336', 'discount' => '0.0000', 'total' => '2.7500'],
            ['store_code' => '1', 'store_name' => 'Bar 1 Joana C', 'sale_date' => '2026-03-14', 'sale_datetime' => '2026-03-14 12:05:00', 'product_code' => '730', 'description' => 'Agua', 'quantity' => '2.0000', 'value' => '4.8673', 'discount' => '0.0000', 'total' => '5.5000'],
            ['store_code' => '2', 'store_name' => 'Bar 2 - Ines', 'sale_date' => '2026-03-14', 'sale_datetime' => '2026-03-14 12:10:00', 'product_code' => '731', 'description' => 'Cerveja', 'quantity' => '1.0000', 'value' => '2.9200', 'discount' => '0.0000', 'total' => '3.2000'],
            ['store_code' => '3', 'store_name' => 'Bar 3 - Luis', 'sale_date' => '2026-03-14', 'sale_datetime' => '2026-03-14 12:15:00', 'product_code' => '732', 'description' => 'Sumo', 'quantity' => '1.0000', 'value' => '1.5000', 'discount' => '0.0000', 'total' => '1.8000'],
            ['store_code' => '4', 'store_name' => 'Bar 4 - Ana', 'sale_date' => '2026-03-15', 'sale_datetime' => '2026-03-15 12:00:00', 'product_code' => '733', 'description' => 'Cafe', 'quantity' => '1.0000', 'value' => '0.8000', 'discount' => '0.0000', 'total' => '1.0000'],
            ['store_code' => '5', 'store_name' => 'Bar 5 - Ines', 'sale_date' => '2026-03-15', 'sale_datetime' => '2026-03-15 12:05:00', 'product_code' => '730', 'description' => 'Agua', 'quantity' => '1.0000', 'value' => '2.4336', 'discount' => '0.0000', 'total' => '2.7500'],
            ['store_code' => null, 'store_name' => null, 'sale_date' => '2026-03-15', 'sale_datetime' => '2026-03-15 12:10:00', 'product_code' => '734', 'description' => 'Snack', 'quantity' => '1.0000', 'value' => '1.2000', 'discount' => '0.0000', 'total' => '1.5000'],
        ];

        foreach ($rows as $index => $row) {
            EventReportRow::create([
                'event_id' => $event->id,
                'event_report_import_id' => $sync->id,
                'source_sheet' => 'zonesoft:test',
                'source_row_number' => $index + 1,
                'doc_type' => 'FS',
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
