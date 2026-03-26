<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventReportImport extends Model
{
    /** @use HasFactory<\Database\Factories\EventReportImportFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'uploaded_by_user_id',
        'import_strategy',
        'original_filename',
        'stored_path',
        'mime_type',
        'file_hash',
        'headers',
        'summary',
        'imported_rows_count',
        'imported_at',
        'is_active',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'summary' => 'array',
            'imported_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(EventReportRow::class);
    }
}
