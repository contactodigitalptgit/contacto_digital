<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dedicated test for the safety net added to
 * database/migrations/2026_09_02_120000_add_natural_key_to_event_report_rows_and_payment_documents.php
 * after finding, while rehearsing it locally, that production data can
 * silently drift from what a migration's backfill assumes.
 * verifyDeletionMatchedExpectations() is what stands between that
 * migration and quietly leaving the wrong rows behind — this proves it
 * actually fires on a mismatch, not just that it stays quiet on data that
 * already happens to be clean (the full up()/down() cycle against real
 * data is exercised separately, manually, before every deploy — see the
 * migration's own class docblock).
 */
class NaturalKeyMigrationSafetyCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_safety_check_throws_when_row_count_does_not_match_imported_rows_count(): void
    {
        [$event] = $this->makeEventWithActiveImport(claimedRowsCount: 5, actualRowsCount: 3);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/event #'.$event->id.'.*expected 5 rows.*but 3 remain/s',
        );

        $this->invokeVerifyDeletionMatchedExpectations();
    }

    public function test_safety_check_passes_silently_when_counts_match(): void
    {
        $this->makeEventWithActiveImport(claimedRowsCount: 3, actualRowsCount: 3);

        // No exception means the assertion below is reached at all.
        $this->invokeVerifyDeletionMatchedExpectations();
        $this->assertTrue(true);
    }

    private function invokeVerifyDeletionMatchedExpectations(): void
    {
        $migration = require database_path(
            'migrations/2026_09_02_120000_add_natural_key_to_event_report_rows_and_payment_documents.php',
        );

        $method = new \ReflectionMethod($migration, 'verifyDeletionMatchedExpectations');
        $method->invoke($migration);
    }

    /**
     * @return array{0: Event}
     */
    private function makeEventWithActiveImport(int $claimedRowsCount, int $actualRowsCount): array
    {
        $clientUser = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente Migracao',
            'address' => 'Rua da Migracao',
            'phone' => '+351 930000009',
            'is_active' => true,
        ]);
        $event = Event::create([
            'client_id' => $client->id,
            'title' => 'Evento Migracao',
            'event_date' => now(),
            'report_starts_at' => now()->subDay(),
            'report_ends_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $import = EventReportImport::create([
            'event_id' => $event->id,
            'uploaded_by_user_id' => $admin->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', 'migration-safety-'.$event->id),
            'headers' => ['source' => 'zonesoft_api'],
            'summary' => ['source' => 'zonesoft_api'],
            // The count this migration's safety check trusts — deliberately
            // wrong when $claimedRowsCount !== $actualRowsCount, to prove
            // the check catches drift instead of trusting it blindly.
            'imported_rows_count' => $claimedRowsCount,
            'imported_at' => now(),
            'is_active' => true,
            'status' => 'completed',
        ]);

        for ($i = 1; $i <= $actualRowsCount; $i++) {
            EventReportRow::create([
                'event_id' => $event->id,
                'event_report_import_id' => $import->id,
                'source_sheet' => 'zonesoft:migration-safety-test',
                'source_row_number' => $i,
                'doc_type' => 'FS',
                'document_series' => 'A2026',
                'document_number' => (string) $i,
                'store_code' => '1',
                'store_name' => 'Loja 1',
                'total' => '1.0000',
                'raw_row' => ['index' => $i],
            ]);
        }

        return [$event];
    }
}
