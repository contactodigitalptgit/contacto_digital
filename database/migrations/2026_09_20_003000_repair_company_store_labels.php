<?php

use App\Services\EventReportSyncService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<int, array<int, true>> */
    private array $affectedMachines = [];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->repairTable('event_report_rows');
            $this->repairTable('event_report_payment_documents');

            foreach ($this->affectedMachines as $eventId => $machineIds) {
                app(EventReportSyncService::class)->refreshRowAggregates(
                    $eventId,
                    array_map('intval', array_keys($machineIds)),
                );

                DB::table('events')->where('id', $eventId)->update(['updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // The previous value was a company name, not a valid store name.
        // Restoring it would knowingly reintroduce incorrect report data.
    }

    private function repairTable(string $table): void
    {
        $rows = DB::table($table)
            ->whereNotNull('machine_id')
            ->whereNotNull('event_zone_id')
            ->whereRaw('LOWER(store_name) LIKE ?', ['pausas animadas - lda%'])
            ->get(['id', 'event_id', 'machine_id', 'event_zone_id', 'store_name']);

        if ($rows->isEmpty()) {
            return;
        }

        $machineLabels = DB::table('client_zonesoft_machines')
            ->whereIn('id', $rows->pluck('machine_id')->unique())
            ->pluck('store_label', 'id');
        $zoneLabels = DB::table('event_zones')
            ->whereIn('id', $rows->pluck('event_zone_id')->unique())
            ->pluck('name', 'id');

        foreach ($rows as $row) {
            $machineLabel = trim((string) ($machineLabels[$row->machine_id] ?? ''));
            $zoneLabel = trim((string) ($zoneLabels[$row->event_zone_id] ?? ''));

            if (! $this->machineLabelMatchesZone($machineLabel, $zoneLabel)) {
                continue;
            }

            $suffix = preg_replace(
                '/^pausas animadas\s*-\s*lda/iu',
                '',
                (string) $row->store_name,
            );

            DB::table($table)->where('id', $row->id)->update([
                'store_name' => $machineLabel.$suffix,
                'updated_at' => now(),
            ]);

            $this->affectedMachines[(int) $row->event_id][(int) $row->machine_id] = true;
        }
    }

    private function machineLabelMatchesZone(string $machineLabel, string $zoneLabel): bool
    {
        if ($machineLabel === '' || $zoneLabel === '') {
            return false;
        }

        $machine = mb_strtolower($machineLabel);
        $zone = mb_strtolower($zoneLabel);

        return $machine === $zone || str_starts_with($machine, $zone.' - ');
    }
};
