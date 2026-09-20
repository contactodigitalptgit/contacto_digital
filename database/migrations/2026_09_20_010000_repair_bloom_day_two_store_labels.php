<?php

use App\Services\EventReportSyncService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const EVENT_ID = 13;

    private const DAY_TWO_STARTS_AT = '2026-09-19 15:30:00';

    /** @var array<string, string> */
    private const HISTORICAL_STORE_LABELS = [
        '218' => 'Bar VIP Ciroc - Tiago S',
        '219' => 'Bar Somersby - Beatriz S',
        '221' => 'Bar Somersby - Pedro A',
        '223' => 'Bar Somersby - Rodrigo P',
    ];

    /** @var array<int, true> */
    private array $affectedMachines = [];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->repairTable('event_report_rows');
            $this->repairTable('event_report_payment_documents');

            if ($this->affectedMachines === []) {
                return;
            }

            app(EventReportSyncService::class)->refreshRowAggregates(
                self::EVENT_ID,
                array_map('intval', array_keys($this->affectedMachines)),
            );

            DB::table('events')
                ->where('id', self::EVENT_ID)
                ->update(['updated_at' => now()]);
        });
    }

    public function down(): void
    {
        // The previous value was the company's name, not a valid store name.
        // Restoring it would knowingly reintroduce incorrect report data.
    }

    private function repairTable(string $table): void
    {
        $rows = DB::table($table)
            ->where('event_id', self::EVENT_ID)
            ->where('sale_datetime', '>=', self::DAY_TWO_STARTS_AT)
            ->whereIn('store_code', array_keys(self::HISTORICAL_STORE_LABELS))
            ->whereRaw('LOWER(store_name) LIKE ?', ['pausas animadas - lda%'])
            ->get(['id', 'machine_id', 'store_code', 'store_name']);

        foreach ($rows as $row) {
            $storeLabel = self::HISTORICAL_STORE_LABELS[(string) $row->store_code];
            $suffix = preg_replace(
                '/^pausas animadas\s*-\s*lda/iu',
                '',
                (string) $row->store_name,
            );

            DB::table($table)->where('id', $row->id)->update([
                'store_name' => $storeLabel.$suffix,
                'updated_at' => now(),
            ]);

            if ($row->machine_id !== null) {
                $this->affectedMachines[(int) $row->machine_id] = true;
            }
        }
    }
};
