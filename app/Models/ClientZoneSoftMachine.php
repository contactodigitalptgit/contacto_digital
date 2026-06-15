<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ZoneSoftApplication::class, 'zonesoft_application_id');
    }
}
