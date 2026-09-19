<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventZone extends Model
{
    protected static function booted(): void
    {
        static::created(fn (EventZone $zone) => Event::query()
            ->whereKey($zone->event_id)
            ->update(['requires_explicit_zones' => true]));
    }

    protected $fillable = [
        'event_id',
        'event_zone_day_id',
        'name',
        'sort_order',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(EventZoneDay::class, 'event_zone_day_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EventZoneAssignment::class);
    }
}
