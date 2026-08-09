<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventReportPaymentDocument extends Model
{
    /** @use HasFactory<\Database\Factories\EventReportPaymentDocumentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'event_report_import_id',
        'machine_id',
        'machine_client_id',
        'store_code',
        'store_name',
        'sale_date',
        'sale_datetime',
        'doc_type',
        'document_series',
        'document_number',
        'payment_reference',
        'paid',
        'document_total',
        'payment_key',
        'payment_code',
        'payment_document_type',
        'payment_document_series',
        'payment_document_number',
        'payment_card_number',
        'total',
        'is_unallocated',
        'dedupe_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'sale_datetime' => 'datetime',
            'paid' => 'boolean',
            'document_total' => 'decimal:4',
            'total' => 'decimal:4',
            'is_unallocated' => 'boolean',
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
}
