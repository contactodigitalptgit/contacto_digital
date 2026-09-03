<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERF-102: durable, per-machine incremental sync state.
 *
 * Before this, the incremental cursor lived inside
 * event_report_imports.summary['machine_document_cursors'] — a single JSON
 * blob written only once an ENTIRE sync cycle finished cleanly for every
 * machine (sync() throws before publishing anything if even one machine
 * fails or warns). That made the cursor all-or-nothing at the event level:
 * one flaky machine out of 200 forced every other machine back to a full
 * refetch on the next cycle, because there was nowhere to durably remember
 * "these 199 already made progress this cycle."
 *
 * client_zonesoft_machines was made global across events (see
 * 2026_08_29_170500_make_zonesoft_machines_global.php) specifically so the
 * same physical TPA can be linked to more than one event over time — a
 * lastupdate cursor is only meaningful for one (event, machine) pairing at
 * a time (a new event reusing an old TPA must not inherit a stale cursor
 * from a previous, unrelated event), so these columns live on the pivot
 * table event_zonesoft_machines, not on client_zonesoft_machines itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_zonesoft_machines', function (Blueprint $table): void {
            $table->timestamp('last_synced_at')->nullable()->after('client_zonesoft_machine_id');
            $table->string('last_document_cursor')->nullable()->after('last_synced_at');
            $table->timestamp('last_full_sync_at')->nullable()->after('last_document_cursor');
            $table->unsignedInteger('consecutive_failures')->default(0)->after('last_full_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('event_zonesoft_machines', function (Blueprint $table): void {
            $table->dropColumn([
                'last_synced_at',
                'last_document_cursor',
                'last_full_sync_at',
                'consecutive_failures',
            ]);
        });
    }
};
