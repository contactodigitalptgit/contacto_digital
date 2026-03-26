<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class EventReportImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_import_event_report_with_sum_strategy(): void
    {
        Storage::fake('local');

        [$admin, $event] = $this->makeAdminEventContext();

        $response = $this
            ->actingAs($admin)
            ->post(route('admin.events.reports.store', $event), [
                'import_strategy' => 'sum',
                'report_file' => $this->reportFixture(),
            ]);

        $response->assertRedirect(route('admin.events.index'));

        $import = EventReportImport::query()->firstOrFail();

        $this->assertSame('sum', $import->import_strategy);
        $this->assertTrue($import->is_active);
        $this->assertSame(100, $import->imported_rows_count);
        $this->assertSame('sample-event-report.xls', $import->original_filename);
        $this->assertGreaterThan(0, (float) ($import->summary['quantity_total'] ?? 0));
        $this->assertGreaterThan(0, (float) ($import->summary['sales_total'] ?? 0));

        Storage::disk('local')->assertExists($import->stored_path);

        $this->assertDatabaseHas('event_report_rows', [
            'event_id' => $event->id,
            'event_report_import_id' => $import->id,
            'product_code' => '730',
            'description' => 'Agua',
        ]);
    }

    public function test_replace_strategy_deactivates_previous_active_imports_and_index_uses_only_active_rows(): void
    {
        Storage::fake('local');

        [$admin, $event] = $this->makeAdminEventContext();

        $this
            ->actingAs($admin)
            ->post(route('admin.events.reports.store', $event), [
                'import_strategy' => 'sum',
                'report_file' => $this->reportFixture(),
            ])
            ->assertRedirect(route('admin.events.index'));

        $firstImport = EventReportImport::query()->firstOrFail();

        $this
            ->actingAs($admin)
            ->post(route('admin.events.reports.store', $event), [
                'import_strategy' => 'replace',
                'report_file' => $this->reportFixture(),
            ])
            ->assertRedirect(route('admin.events.index'));

        $firstImport->refresh();
        $latestImport = EventReportImport::query()->latest('id')->firstOrFail();

        $this->assertFalse($firstImport->is_active);
        $this->assertTrue($latestImport->is_active);
        $this->assertSame(2, EventReportImport::query()->count());
        $this->assertSame(1, EventReportImport::query()->where('is_active', true)->count());
        $this->assertSame(200, EventReportRow::query()->count());

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.events.index'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Events/Index')
            ->has('events', 1)
            ->where('events.0.report_summary.active_imports_count', 1)
            ->where('events.0.report_summary.active_rows_count', 100)
            ->where('events.0.report_summary.last_filename', 'sample-event-report.xls'));
    }

    /**
     * @return array{0: User, 1: Event}
     */
    private function makeAdminEventContext(): array
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $clientUser = User::factory()->create([
            'role' => 'client',
        ]);

        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente Relatorio',
            'business_name' => 'Operacao Evento',
            'address' => 'Rua do Relatorio',
            'phone' => '+351 930000001',
            'is_active' => true,
        ]);

        $event = Event::create([
            'client_id' => $client->id,
            'title' => 'Evento com Relatorio',
            'description' => 'Importacao de XLS',
            'event_date' => now()->addDays(3),
            'is_active' => true,
        ]);

        return [$admin, $event];
    }

    private function reportFixture(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/sample-event-report.xls'),
            'sample-event-report.xls',
            'application/vnd.ms-excel',
            null,
            true,
        );
    }
}
