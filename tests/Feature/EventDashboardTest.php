<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Event;
use App\Models\EventReportRow;
use App\Models\User;
use App\Services\EventReportImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class EventDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_view_event_dashboard_with_filters(): void
    {
        Storage::fake('local');

        [$admin, $clientUser, $event] = $this->makeDashboardContext();

        $this->importSampleReport($event, $admin);

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
        Storage::fake('local');

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
        Storage::fake('local');

        [$admin, , $event] = $this->makeDashboardContext();

        $this->importSampleReport($event, $admin);

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.events.dashboard', $event));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Events/Dashboard')
            ->where('previewMode', true)
            ->where('backUrl', route('admin.events.index'))
            ->where('summary.total_rows', 100)
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
            'is_active' => true,
        ]);

        return [$admin, $clientUser, $event];
    }

    private function importSampleReport(Event $event, User $admin): void
    {
        app(EventReportImportService::class)->import(
            $event,
            new UploadedFile(
                base_path('tests/Fixtures/sample-event-report.xls'),
                'sample-event-report.xls',
                'application/vnd.ms-excel',
                null,
                true,
            ),
            'sum',
            $admin,
        );
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
