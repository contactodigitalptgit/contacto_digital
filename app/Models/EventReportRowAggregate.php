<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PERF-401 (fatia 1): one row per (day, hour, store, product, doc_type)
 * actually present for an event — maintained by
 * EventReportSyncService::refreshRowAggregates(), never written directly
 * by the dashboard. See the migration for the full grain/unique key.
 */
class EventReportRowAggregate extends Model
{
    protected $fillable = [
        'event_id',
        'machine_id',
        'sale_date',
        'sale_hour',
        'store_code',
        'store_name',
        'doc_type',
        'product_code',
        'description',
        'rows_count',
        'quantity_total',
        'value_total',
        'discount_total',
        'total_sum',
        'offered_quantity_total',
        'sold_quantity_total',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'sale_hour' => 'integer',
            'rows_count' => 'integer',
            'quantity_total' => 'decimal:4',
            'value_total' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'total_sum' => 'decimal:4',
            'offered_quantity_total' => 'decimal:4',
            'sold_quantity_total' => 'decimal:4',
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
