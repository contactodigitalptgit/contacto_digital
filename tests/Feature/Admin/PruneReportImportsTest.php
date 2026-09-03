<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PERF-302: event_report_imports.id is a cascadeOnDelete() foreign key on
 * both event_report_rows and event_report_payment_documents, and PERF-101
 * repurposed that column to mean "the sync attempt that last touched this
 * row" rather than "which snapshot owns it" — a currently-live row can
 * point at an import that is otherwise old and inactive. These tests exist
 * specifically to prove events:prune-report-imports can never cascade-
 * delete a row or payment document that is still live.
 */
class PruneReportImportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_only_old_inactive_imports_with_no_referencing_rows(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);

        $oldUnreferenced = $this->makeImport($event, $admin, active: false, createdAt: now()->subDays(200));
        $recentInactive = $this->makeImport($event, $admin, active: false, createdAt: now()->subDays(5));
        $activeImport = $this->makeImport($event, $admin, active: true, createdAt: now()->subDays(300));

        $this->artisan('events:prune-report-imports')->assertExitCode(0);

        $this->assertDatabaseMissing('event_report_imports', ['id' => $oldUnreferenced->id]);
        $this->assertDatabaseHas('event_report_imports', ['id' => $recentInactive->id]);
        $this->assertDatabaseHas('event_report_imports', ['id' => $activeImport->id]);
    }

    /**
     * The critical safety case: an old, inactive import that a CURRENT row
     * still points at (because that row hasn't been touched by a more
     * recent sync cycle) must never be pruned — deleting it would cascade
     * and silently destroy that row.
     */
    public function test_never_prunes_an_old_inactive_import_still_referenced_by_a_live_row(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);

        $oldButReferenced = $this->makeImport($event, $admin, active: false, createdAt: now()->subDays(200));

        $row = EventReportRow::create([
            'event_id' => $event->id,
            'event_report_import_id' => $oldButReferenced->id,
            'source_sheet' => 'zonesoft:test',
            'source_row_number' => 1,
            'store_code' => '1',
            'store_name' => 'Loja 1',
            'doc_type' => 'FS',
            'document_series' => 'A2026',
            'document_number' => '1',
            'total' => '10.0000',
            'raw_row' => ['id' => 1],
        ]);

        $this->artisan('events:prune-report-imports')->assertExitCode(0);

        $this->assertDatabaseHas('event_report_imports', ['id' => $oldButReferenced->id]);
        $this->assertDatabaseHas('event_report_rows', ['id' => $row->id]);
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        [$admin, $client] = $this->makeAdminClientContext();
        $event = $this->makeEvent($client);

        $oldUnreferenced = $this->makeImport($event, $admin, active: false, createdAt: now()->subDays(200));

        $this->artisan('events:prune-report-imports', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseHas('event_report_imports', ['id' => $oldUnreferenced->id]);
    }

    private function makeImport(Event $event, User $admin, bool $active, Carbon $createdAt): EventReportImport
    {
        $import = EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'prune-test-'.$event->id.'-'.$createdAt->timestamp.'-'.random_int(1, 999999)),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['source' => 'zonesoft_api'],
            'imported_rows_count' => 0,
            'imported_at' => $createdAt,
            'is_active' => $active,
            'status' => 'completed',
        ]);
        $import->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $import;
    }

    /**
     * @return array{0: User, 1: Client}
     */
    private function makeAdminClientContext(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $clientUser = User::factory()->create(['role' => 'client']);
        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente Prune',
            'address' => 'Rua de Teste',
            'phone' => '+351 930000000',
            'is_active' => true,
        ]);

        return [$admin, $client];
    }

    private function makeEvent(Client $client): Event
    {
        return Event::create([
            'client_id' => $client->id,
            'title' => 'Evento Prune',
            'event_date' => '2026-06-20 12:00:00',
            'report_starts_at' => '2026-06-20 00:00:00',
            'report_ends_at' => '2026-06-20 23:59:59',
            'is_active' => true,
        ]);
    }
}
