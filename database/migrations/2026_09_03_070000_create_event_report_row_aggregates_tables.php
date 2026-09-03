<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PERF-401 (fatia 1): dashboard aggregations that read event_report_rows
 * directly (summary, bar groups, top stores/products, document types,
 * hourly sales, filter option lists) stop scanning the raw sales-line
 * table and read these two much smaller, pre-aggregated tables instead.
 *
 * event_report_row_aggregates — one row per (day, hour, store, product,
 * doc_type) actually present, holding the SUMs the dashboard needs.
 * event_report_ticket_aggregates — one row per distinct document (the
 * "ticket" identity used for tickets_count = COUNT(DISTINCT document)
 * throughout the dashboard) — no sums, existence only.
 *
 * Both are refreshed by EventReportSyncService::refreshRowAggregates(),
 * inside the same publish transaction PERF-101 already uses, scoped to
 * only the machines whose rows changed this cycle (delete + re-aggregate
 * for that (event_id, machine_id) from event_report_rows — O(that
 * machine's rows), never O(dataset)).
 *
 * Deliberately NOT covered by this migration: total_min/total_max
 * filtering (needs individual-row precision incompatible with
 * pre-summing — the dashboard falls back to querying event_report_rows
 * directly when those filters are active) and anything based on
 * event_report_payment_documents (buildDailyBreakdowns/paymentSummary/
 * paymentReconciliation/comparison) — that table isn't the bottleneck
 * PERF-401 targets. See docs/PLANO_DE_PERFORMANCE_SINCRONIZACAO.md
 * (PERF-401) for the full reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_report_row_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('client_zonesoft_machines')->nullOnDelete();
            $table->date('sale_date')->nullable();
            $table->unsignedTinyInteger('sale_hour')->nullable();
            $table->string('store_code')->nullable();
            $table->string('store_name')->nullable();
            $table->string('doc_type')->nullable();
            $table->string('product_code')->nullable();
            $table->string('description')->nullable();
            $table->unsignedInteger('rows_count')->default(0);
            $table->decimal('quantity_total', 14, 4)->default(0);
            $table->decimal('value_total', 14, 4)->default(0);
            $table->decimal('discount_total', 14, 4)->default(0);
            $table->decimal('total_sum', 14, 4)->default(0);
            $table->decimal('offered_quantity_total', 14, 4)->default(0);
            $table->decimal('sold_quantity_total', 14, 4)->default(0);
            $table->timestamps();

            $table->unique([
                'event_id', 'machine_id', 'sale_date', 'sale_hour',
                'store_code', 'store_name', 'doc_type', 'product_code', 'description',
            ], 'event_report_row_aggregates_grain_unique');
            $table->index(['event_id', 'machine_id'], 'event_report_row_aggregates_event_machine_index');
        });

        Schema::create('event_report_ticket_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained('client_zonesoft_machines')->nullOnDelete();
            $table->date('sale_date')->nullable();
            $table->unsignedTinyInteger('sale_hour')->nullable();
            $table->string('store_code')->nullable();
            $table->string('store_name')->nullable();
            $table->string('doc_type')->nullable();
            $table->string('document_series')->nullable();
            $table->string('document_number')->nullable();
            $table->timestamps();

            // store_name is not part of a document's real identity (a
            // document has exactly one store_name in practice) — it's
            // carried here, and grouped on when writing, only so a bar
            // group made of several store_name/POS variants of the same
            // store_code can attribute one ticket to exactly one of them
            // and sum safely, instead of double-counting when several POS
            // labels share a store_code. See buildBarGroups()/buildZoneDevices().
            $table->unique([
                'event_id', 'machine_id', 'store_code', 'store_name', 'doc_type', 'document_series', 'document_number',
            ], 'event_report_ticket_aggregates_document_unique');
            $table->index(['event_id', 'machine_id'], 'event_report_ticket_aggregates_event_machine_index');
        });

        // Backfill: existing event_report_rows predate these tables — every
        // (event_id, machine_id) pair that already has rows needs its
        // aggregate footprint built once here, otherwise the dashboard
        // would show nothing until the next sync cycle touches each
        // machine. Same grouping EventReportSyncService::refreshRowAggregatesForMachine()
        // uses going forward, duplicated here so this migration doesn't
        // depend on application code.
        $this->backfillAggregates();
    }

    public function down(): void
    {
        Schema::dropIfExists('event_report_ticket_aggregates');
        Schema::dropIfExists('event_report_row_aggregates');
    }

    private function backfillAggregates(): void
    {
        $pairs = DB::table('event_report_rows')
            ->select('event_id', 'machine_id')
            ->distinct()
            ->get();

        if ($pairs->isEmpty()) {
            return;
        }

        $hourExpression = match (DB::connection()->getDriverName()) {
            'pgsql' => 'CAST(EXTRACT(HOUR FROM sale_datetime) AS INTEGER)',
            'mysql', 'mariadb' => 'HOUR(sale_datetime)',
            default => "CAST(strftime('%H', sale_datetime) AS INTEGER)",
        };
        // DATE() around sale_date too — see the matching comment in
        // EventReportSyncService::refreshRowAggregatesForMachine().
        $dayExpression = 'COALESCE(DATE(sale_date), DATE(sale_datetime))';
        $timestamp = now();

        foreach ($pairs as $pair) {
            $baseQuery = fn () => DB::table('event_report_rows')
                ->where('event_id', $pair->event_id)
                ->where('machine_id', $pair->machine_id);

            $rowGroups = $baseQuery()
                ->selectRaw("{$dayExpression} as agg_sale_date")
                ->selectRaw("{$hourExpression} as agg_sale_hour")
                ->addSelect(['store_code', 'store_name', 'doc_type', 'product_code', 'description'])
                ->selectRaw('COUNT(*) as rows_count')
                ->selectRaw('COALESCE(SUM(quantity), 0) as quantity_total')
                ->selectRaw('COALESCE(SUM(value), 0) as value_total')
                ->selectRaw('COALESCE(SUM(discount), 0) as discount_total')
                ->selectRaw('COALESCE(SUM(total), 0) as total_sum')
                ->selectRaw('COALESCE(SUM(CASE WHEN total = 0 THEN quantity ELSE 0 END), 0) as offered_quantity_total')
                ->selectRaw('COALESCE(SUM(CASE WHEN total != 0 THEN quantity ELSE 0 END), 0) as sold_quantity_total')
                ->groupByRaw($dayExpression)
                ->groupByRaw($hourExpression)
                ->groupBy('store_code', 'store_name', 'doc_type', 'product_code', 'description')
                ->get();

            foreach ($rowGroups->chunk(500) as $chunk) {
                DB::table('event_report_row_aggregates')->insert($chunk->map(fn (object $row): array => [
                    'event_id' => $pair->event_id,
                    'machine_id' => $pair->machine_id,
                    'sale_date' => $row->agg_sale_date,
                    'sale_hour' => $row->agg_sale_hour,
                    'store_code' => $row->store_code,
                    'store_name' => $row->store_name,
                    'doc_type' => $row->doc_type,
                    'product_code' => $row->product_code,
                    'description' => $row->description,
                    'rows_count' => (int) $row->rows_count,
                    'quantity_total' => $row->quantity_total,
                    'value_total' => $row->value_total,
                    'discount_total' => $row->discount_total,
                    'total_sum' => $row->total_sum,
                    'offered_quantity_total' => $row->offered_quantity_total,
                    'sold_quantity_total' => $row->sold_quantity_total,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all());
            }

            $ticketGroups = $baseQuery()
                ->selectRaw("{$dayExpression} as agg_sale_date")
                ->selectRaw("{$hourExpression} as agg_sale_hour")
                ->addSelect(['store_code', 'store_name', 'doc_type', 'document_series', 'document_number'])
                ->groupByRaw($dayExpression)
                ->groupByRaw($hourExpression)
                ->groupBy('store_code', 'store_name', 'doc_type', 'document_series', 'document_number')
                ->get();

            foreach ($ticketGroups->chunk(500) as $chunk) {
                DB::table('event_report_ticket_aggregates')->insert($chunk->map(fn (object $row): array => [
                    'event_id' => $pair->event_id,
                    'machine_id' => $pair->machine_id,
                    'sale_date' => $row->agg_sale_date,
                    'sale_hour' => $row->agg_sale_hour,
                    'store_code' => $row->store_code,
                    'store_name' => $row->store_name,
                    'doc_type' => $row->doc_type,
                    'document_series' => $row->document_series,
                    'document_number' => $row->document_number,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all());
            }
        }
    }
};
