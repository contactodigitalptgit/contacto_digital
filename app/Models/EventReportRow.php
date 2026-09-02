<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventReportRow extends Model
{
    /** @use HasFactory<\Database\Factories\EventReportRowFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'event_report_import_id',
        'machine_id',
        'source_sheet',
        'source_row_number',
        'store_code',
        'store_name',
        'sale_date',
        'sale_datetime',
        'doc_type',
        'document_series',
        'document_number',
        'line_key',
        'value',
        'total',
        'discount',
        'quantity',
        'product_code',
        'description',
        'raw_row',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'sale_datetime' => 'datetime',
            'value' => 'decimal:4',
            'total' => 'decimal:4',
            'discount' => 'decimal:4',
            'quantity' => 'decimal:4',
            'raw_row' => 'array',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function reportImport(): BelongsTo
    {
        return $this->belongsTo(EventReportImport::class, 'event_report_import_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ClientZoneSoftMachine::class, 'machine_id');
    }

    /**
     * No-op as of PERF-101. event_report_rows is now the durable, current
     * state of the event (written by upsert on a natural key, not copied
     * per import), so every row for the event is already "current" — there
     * is no separate snapshot to filter by anymore. Kept as a scope (rather
     * than removed) so the ~10 call sites in EventDashboardController don't
     * need to change; event_id is already the only filter that matters.
     */
    public function scopeFromActiveImports(Builder $query): void
    {
        //
    }
}
