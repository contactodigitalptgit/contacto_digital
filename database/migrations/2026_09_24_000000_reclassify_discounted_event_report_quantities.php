<?php

use App\Services\EventReportSyncService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $pairs = DB::table('event_report_rows')
            ->select('event_id', 'machine_id')
            ->distinct()
            ->orderBy('event_id')
            ->orderBy('machine_id')
            ->get();

        if ($pairs->isEmpty()) {
            return;
        }

        $syncService = app(EventReportSyncService::class);

        foreach ($pairs as $pair) {
            $machineId = $pair->machine_id === null ? null : (int) $pair->machine_id;

            // This is a local, idempotent aggregate rebuild. It does not call
            // ZoneSoft and it leaves the imported sales rows unchanged.
            DB::transaction(fn () => $syncService->refreshRowAggregates(
                (int) $pair->event_id,
                [$machineId],
            ));
        }

        // Dashboard cache versions include the active import timestamp. Touch
        // only affected events so the corrected aggregates are visible at once.
        DB::table('event_report_imports')
            ->where('is_active', true)
            ->whereIn('event_id', $pairs->pluck('event_id')->unique()->all())
            ->update(['updated_at' => now()]);
    }

    public function down(): void
    {
        // Reverting would knowingly restore the incorrect classification in
        // which partially discounted products were counted as fully sold.
    }
};
