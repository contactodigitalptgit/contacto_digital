<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ZoneSoftApplication extends Model
{
    /** @use HasFactory<\Database\Factories\ZoneSoftApplicationFactory> */
    use HasFactory;

    protected $table = 'zonesoft_applications';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'base_url',
        'app_key',
        'app_secret',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function hasStoredSecret(): bool
    {
        return filled($this->getRawOriginal('app_secret'));
    }

    public function hasReadableSecret(): bool
    {
        if (! $this->hasStoredSecret()) {
            return false;
        }

        try {
            return filled($this->app_secret);
        } catch (DecryptException) {
            return false;
        }
    }

    public function requiresSecretReconfiguration(): bool
    {
        return $this->hasStoredSecret() && ! $this->hasReadableSecret();
    }

    public function clientMachines(): HasMany
    {
        return $this->hasMany(ClientZoneSoftMachine::class);
    }
}
