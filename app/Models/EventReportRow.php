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
        'source_sheet',
        'source_row_number',
        'store_code',
        'store_name',
        'sale_date',
        'sale_datetime',
        'doc_type',
        'document_series',
        'document_number',
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

    public function scopeFromActiveImports(Builder $query): void
    {
        $query->whereHas(
            'reportImport',
            fn (Builder $reportImportQuery) => $reportImportQuery
                ->where('is_active', true)
                ->where('status', 'completed'),
        );
    }
}
