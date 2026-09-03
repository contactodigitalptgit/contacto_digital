<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERF-101: event_report_rows / event_report_payment_documents stop being
 * per-import snapshots and become the durable, current state of the event.
 *
 * Adds a stable natural key so a sync cycle can upsert only the documents
 * that actually changed (delta) instead of copying the whole dataset.
 * event_report_import_id is kept on both tables (still a real column, still
 * populated) but its role changes from "which snapshot owns this row" to
 * "which sync attempt last touched this row" — audit only, no longer used
 * to decide what is visible.
 *
 * See docs/PLANO_DE_PERFORMANCE_SINCRONIZACAO.md (PERF-101) for the design
 * and docs/PADRAO_DE_IMPLEMENTACAO_SEGURA.md §12 for the backfill rules this
 * migration must respect before it is ever run against production data.
 *
 * DEPLOYMENT NOTE — this migration deletes rows (see
 * dropRowsAndPaymentDocumentsNotBelongingToTheActiveImport() below), and
 * its down() cannot restore what it deletes. Before running this against
 * production:
 *   1. Back up the production database file/dump first — non-negotiable,
 *      regardless of how safe the code looks on review.
 *   2. Run `php artisan migrate --force` and read its output. A thrown
 *      RuntimeException from verifyDeletionMatchedExpectations() means
 *      the whole migration rolled back automatically (nothing was kept
 *      half-applied) — the message names the exact event/count mismatch
 *      to investigate; do not re-run until that is understood.
 *   3. Only after migrate exits successfully, spot-check a couple of
 *      events' dashboard totals against what they showed before the
 *      deploy, then confirm a real sync cycle still runs cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_report_rows', function (Blueprint $table): void {
            $table->foreignId('machine_id')
                ->nullable()
                ->after('event_report_import_id')
                ->constrained('client_zonesoft_machines')
                ->nullOnDelete();
            $table->string('line_key')->nullable()->after('document_number');
        });

        Schema::table('event_report_rows', function (Blueprint $table): void {
            $table->unique(
                ['event_id', 'machine_id', 'doc_type', 'document_series', 'document_number', 'line_key'],
                'event_report_rows_natural_key_unique',
            );
        });

        Schema::table('event_report_payment_documents', function (Blueprint $table): void {
            $table->dropUnique('event_payment_documents_import_dedupe_unique');
        });

        Schema::table('event_report_payment_documents', function (Blueprint $table): void {
            $table->unique(
                ['event_id', 'dedupe_key'],
                'event_payment_documents_event_dedupe_unique',
            );
        });

        // Backfill: production only ever holds the Fesnima event today (see
        // 2026_08_29_180000_retain_only_fesnima_production_data.php).
        //
        // Discovered while rehearsing this migration locally against real
        // synced data (Cavado/Cavado 2): cleanupSupersededRows() is
        // best-effort and swallows its own exceptions, and locally it had
        // silently failed to run for a long stretch of sync cycles — one
        // event had 3,055 leftover rows (38% of the table) from 7 different
        // superseded import generations that were never swept, alongside
        // the single active import's copy of each row. event_report_payment_documents
        // did not show the same problem locally (its own cleanup call had
        // kept up), but the row backfill below cannot assume that holds
        // elsewhere, so it applies the same rule to both tables.
        //
        // The only backfill rule that is provably safe — guaranteed not to
        // change a single number the dashboard already shows — is: keep
        // exactly the rows/documents belonging to each event's current
        // active, completed import (that is what "current" already means
        // under the pre-PERF-101 model), delete everything else, THEN
        // resolve machine_id (rows: via the event_zonesoft_machines pivot;
        // payment documents already have it) and line_key (rows only, from
        // the raw_row payload already stored — same rule
        // EventReportSyncService::resolveLineKey() uses for new rows).
        // A processing import's rows are left alone (a migration should not
        // run while a sync is mid-flight, but this is a cheap guard against
        // it if one somehow is).
        //
        // This is idempotent (safe to run again) but has only been
        // exercised locally, not against a copy of the real Fesnima
        // dataset. Per docs/PADRAO_DE_IMPLEMENTACAO_SEGURA.md §12, it must
        // be rehearsed there before this migration ever runs in production.
        //
        // Safety net: this migration runs inside a DB transaction on
        // sqlite/pgsql (Laravel's default for drivers with transactional
        // DDL — confirm this holds for whatever driver production actually
        // uses before relying on it). verifyDeletionMatchedExpectations()
        // below THROWS if the row/payment-document count left behind for
        // any event doesn't exactly match that event's own
        // imported_rows_count / summary.payment_documents_count — numbers
        // production already trusted before this migration touched
        // anything. Throwing rolls the whole migration back; nothing is
        // left half-applied.
        $this->dropRowsAndPaymentDocumentsNotBelongingToTheActiveImport();
        $this->verifyDeletionMatchedExpectations();
        $this->backfillRowIdentity();
    }

    /**
     * Reverses the schema (columns, indexes) but NOT the delete this
     * migration's up() ran — the superseded/orphaned rows it removed are
     * gone, `down()` cannot bring them back. If up() ever needs undoing
     * after real data was affected, restore from the backup taken before
     * migrating (see the deployment note in this migration's class
     * docblock) rather than relying on this down().
     */
    public function down(): void
    {
        Schema::table('event_report_payment_documents', function (Blueprint $table): void {
            $table->dropUnique('event_payment_documents_event_dedupe_unique');
        });

        Schema::table('event_report_payment_documents', function (Blueprint $table): void {
            $table->unique(
                ['event_report_import_id', 'dedupe_key'],
                'event_payment_documents_import_dedupe_unique',
            );
        });

        Schema::table('event_report_rows', function (Blueprint $table): void {
            $table->dropUnique('event_report_rows_natural_key_unique');
        });

        Schema::table('event_report_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('machine_id');
            $table->dropColumn('line_key');
        });
    }

    private function dropRowsAndPaymentDocumentsNotBelongingToTheActiveImport(): void
    {
        $events = \Illuminate\Support\Facades\DB::table('events')->pluck('id');
        $activeImportIdByEvent = \Illuminate\Support\Facades\DB::table('event_report_imports')
            ->where('is_active', true)
            ->where('status', 'completed')
            ->pluck('id', 'event_id');

        foreach (['event_report_rows', 'event_report_payment_documents'] as $table) {
            foreach ($events as $eventId) {
                $query = \Illuminate\Support\Facades\DB::table($table)
                    ->where('event_id', $eventId)
                    // Never touch a still-processing import's rows — a
                    // migration should not run mid-sync, but this is a
                    // cheap guard in case one somehow is.
                    ->whereIn('event_report_import_id', function ($subQuery): void {
                        $subQuery->select('id')
                            ->from('event_report_imports')
                            ->where('status', '!=', 'processing');
                    });

                $activeImportId = $activeImportIdByEvent[$eventId] ?? null;

                if ($activeImportId !== null) {
                    $query->where('event_report_import_id', '!=', $activeImportId);
                }

                // Events with no active/completed import at all (e.g. a
                // sync that only ever failed) keep no rows either —
                // nothing "current" exists for them under either model.
                $query->delete();
            }
        }
    }

    /**
     * Hard stop before this migration touches machine_id/line_key at all:
     * for every event with an active, completed import, the rows/payment
     * documents left after the delete above must exactly match the counts
     * that import already claims (imported_rows_count, and
     * summary.payment_documents_count when present). Those numbers were
     * already trusted by the dashboard and the admin events list before
     * this migration ran — if they don't match now, something about this
     * production database doesn't match the assumption the delete step
     * relied on, and continuing would risk silently keeping (or removing)
     * the wrong rows. Better to abort loudly and roll back than guess.
     */
    private function verifyDeletionMatchedExpectations(): void
    {
        $activeImports = \Illuminate\Support\Facades\DB::table('event_report_imports')
            ->where('is_active', true)
            ->where('status', 'completed')
            ->get(['id', 'event_id', 'imported_rows_count', 'summary']);

        foreach ($activeImports as $import) {
            $actualRows = \Illuminate\Support\Facades\DB::table('event_report_rows')
                ->where('event_id', $import->event_id)
                ->count();

            if ($actualRows !== (int) $import->imported_rows_count) {
                throw new \RuntimeException(sprintf(
                    'PERF-101 migration safety check failed for event #%d: '.
                    'expected %d rows (event_report_imports.imported_rows_count for import #%d) '.
                    'but %d remain after removing non-active-import rows. Aborting without '.
                    'changing anything further — investigate before re-running this migration.',
                    $import->event_id,
                    (int) $import->imported_rows_count,
                    $import->id,
                    $actualRows,
                ));
            }

            $summary = is_string($import->summary) ? json_decode($import->summary, true) : null;
            $expectedPaymentDocuments = is_array($summary) && isset($summary['payment_documents_count'])
                ? (int) $summary['payment_documents_count']
                : null;

            if ($expectedPaymentDocuments === null) {
                continue;
            }

            $actualPaymentDocuments = \Illuminate\Support\Facades\DB::table('event_report_payment_documents')
                ->where('event_id', $import->event_id)
                ->count();

            if ($actualPaymentDocuments !== $expectedPaymentDocuments) {
                throw new \RuntimeException(sprintf(
                    'PERF-101 migration safety check failed for event #%d: '.
                    'expected %d payment documents (summary.payment_documents_count for import #%d) '.
                    'but %d remain after removing non-active-import documents. Aborting without '.
                    'changing anything further — investigate before re-running this migration.',
                    $import->event_id,
                    $expectedPaymentDocuments,
                    $import->id,
                    $actualPaymentDocuments,
                ));
            }
        }
    }

    private function backfillRowIdentity(): void
    {
        $machineIdByEventAndStore = $this->machineIdByEventAndStore();

        \Illuminate\Support\Facades\DB::table('event_report_rows')
            ->whereNull('machine_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($machineIdByEventAndStore): void {
                foreach ($rows as $row) {
                    $machineId = $machineIdByEventAndStore[$row->event_id.'|'.$row->store_code] ?? null;
                    $rawRow = is_string($row->raw_row) ? json_decode($row->raw_row, true) : null;
                    $providerLineId = is_array($rawRow) ? ($rawRow['id'] ?? null) : null;
                    $lineKey = $providerLineId !== null && (string) $providerLineId !== ''
                        ? 'id:'.$providerLineId
                        : 'line:'.(is_array($rawRow) ? ($rawRow['_document_line_number'] ?? '') : '');

                    \Illuminate\Support\Facades\DB::table('event_report_rows')
                        ->where('id', $row->id)
                        ->update([
                            'machine_id' => $machineId,
                            'line_key' => $lineKey,
                        ]);
                }
            });
    }

    /**
     * @return array<string, int> keyed by "event_id|store_code"
     */
    private function machineIdByEventAndStore(): array
    {
        $map = [];

        $pivotRows = \Illuminate\Support\Facades\DB::table('event_zonesoft_machines')
            ->join(
                'client_zonesoft_machines',
                'client_zonesoft_machines.id',
                '=',
                'event_zonesoft_machines.client_zonesoft_machine_id',
            )
            ->select([
                'event_zonesoft_machines.event_id',
                'client_zonesoft_machines.id as machine_id',
                'client_zonesoft_machines.store_id',
            ])
            ->get();

        foreach ($pivotRows as $pivotRow) {
            $map[$pivotRow->event_id.'|'.$pivotRow->store_id] = (int) $pivotRow->machine_id;
        }

        return $map;
    }
};
