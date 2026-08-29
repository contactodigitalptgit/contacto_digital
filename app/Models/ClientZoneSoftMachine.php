<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ClientZoneSoftMachine extends Model
{
    /** @use HasFactory<\Database\Factories\ClientZoneSoftMachineFactory> */
    use HasFactory;

    protected $table = 'client_zonesoft_machines';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'event_id',
        'zonesoft_application_id',
        'zs_client_id',
        'license',
        'store_id',
        'store_label',
        'permissions',
        'is_active',
        'last_validated_at',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_validated_at' => 'datetime',
        ];
    }

    private ?int $pendingEventId = null;

    protected static function booted(): void
    {
        static::created(function (ClientZoneSoftMachine $machine): void {
            if ($machine->pendingEventId !== null) {
                $machine->events()->syncWithoutDetaching([$machine->pendingEventId]);
            }
        });
    }

    public function setEventIdAttribute(mixed $eventId): void
    {
        $this->pendingEventId = filled($eventId) ? (int) $eventId : null;
    }

    public function getEventIdAttribute(): ?int
    {
        if ($this->pendingEventId !== null) {
            return $this->pendingEventId;
        }

        return $this->events()->value('events.id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ZoneSoftApplication::class, 'zonesoft_application_id');
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(
            Event::class,
            'event_zonesoft_machines',
            'client_zonesoft_machine_id',
            'event_id',
        )->withTimestamps();
    }
}
