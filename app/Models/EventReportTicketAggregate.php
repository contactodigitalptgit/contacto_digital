<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PERF-401 (fatia 1): one row per distinct document ("ticket") — no sums,
 * existence only, so COUNT(*) grouped any way the dashboard needs gives
 * the same tickets_count that COUNT(DISTINCT store|doc|serie|numero) over
 * raw rows gives today. Maintained by
 * EventReportSyncService::refreshRowAggregates().
 */
class EventReportTicketAggregate extends Model
{
    protected $fillable = [
        'event_id',
        'machine_id',
        'sale_date',
        'sale_calendar_date',
        'sale_hour',
        'store_code',
        'store_name',
        'doc_type',
        'document_series',
        'document_number',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'sale_calendar_date' => 'date',
            'sale_hour' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ClientZoneSoftMachine::class, 'machine_id');
    }
}
