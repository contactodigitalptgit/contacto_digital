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
        $this->dropRowsAndPaymentDocumentsNotBelongingToTheActiveImport();
        $this->backfillRowIdentity();
    }

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
