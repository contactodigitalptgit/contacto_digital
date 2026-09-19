<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventZoneDay extends Model
{
    protected $fillable = [
        'event_id',
        'operational_date',
        'starts_at',
        'ends_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'operational_date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(EventZone::class);
    }
}
