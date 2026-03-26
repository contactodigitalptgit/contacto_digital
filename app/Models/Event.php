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
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_date' => 'datetime',
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

    public function latestActiveReportImport(): HasOne
    {
        return $this->hasOne(EventReportImport::class)
            ->ofMany(
                ['imported_at' => 'max', 'id' => 'max'],
                fn ($query) => $query->where('is_active', true)->where('status', 'completed'),
            );
    }

    public function reportRows(): HasMany
    {
        return $this->hasMany(EventReportRow::class);
    }
}
