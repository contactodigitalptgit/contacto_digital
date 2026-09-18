<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Client extends Model
{
    /** @use HasFactory<\Database\Factories\ClientFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'business_name',
        'address',
        'phone',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function additionalEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_additional_clients')->withTimestamps();
    }

    /**
     * All events this client can access, as primary or additional client.
     *
     * @return Builder<Event>
     */
    public function visibleEvents(): Builder
    {
        return Event::query()
            ->where('client_id', $this->id)
            ->orWhereHas('additionalClients', fn ($query) => $query->where('clients.id', $this->id));
    }

    public function zonesoftMachines(): HasMany
    {
        return $this->hasMany(ClientZoneSoftMachine::class);
    }

    public function reportRows(): HasManyThrough
    {
        return $this->hasManyThrough(EventReportRow::class, Event::class);
    }
}
