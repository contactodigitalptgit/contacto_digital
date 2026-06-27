<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Event extends Model
{
    /** @use HasFactory<\Database\Factories\EventFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'title',
        'description',
        'event_date',
        'report_starts_at',
        'report_ends_at',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_date' => 'datetime',
            'report_starts_at' => 'datetime',
            'report_ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reportImports(): HasMany
    {
        return $this->hasMany(EventReportImport::class);
    }

    public function activeReportImports(): HasMany
    {
        return $this->reportImports()->where('is_active', true)->where('status', 'completed');
    }

    public function processingReportImports(): HasMany
    {
        return $this->reportImports()->where('status', 'processing');
    }

    public function latestActiveReportImport(): HasOne
    {
        return $this->hasOne(EventReportImport::class)
            ->ofMany(
                ['imported_at' => 'max', 'id' => 'max'],
                fn ($query) => $query->where('is_active', true)->where('status', 'completed'),
            );
    }

    public function latestReportImport(): HasOne
    {
        return $this->hasOne(EventReportImport::class)->latestOfMany();
    }

    public function reportRows(): HasMany
    {
        return $this->hasMany(EventReportRow::class);
    }
}
