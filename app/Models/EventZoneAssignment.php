<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventZoneAssignment extends Model
{
    protected $fillable = [
        'event_id',
        'event_zone_id',
        'machine_id',
        'starts_at',
        'ends_at',
        'assigned_by_user_id',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(EventZone::class, 'event_zone_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ClientZoneSoftMachine::class, 'machine_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
