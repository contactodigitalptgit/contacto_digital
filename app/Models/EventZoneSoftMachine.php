<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot for event_zonesoft_machines. PERF-102: carries this (event,
 * machine) pairing's own incremental sync progress — see the migration
 * 2026_09_03_100000_add_incremental_cursor_to_event_zonesoft_machines.php
 * for why this lives on the pivot rather than on ClientZoneSoftMachine.
 */
class EventZoneSoftMachine extends Pivot
{
    protected $table = 'event_zonesoft_machines';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'client_zonesoft_machine_id',
        'last_synced_at',
        'last_document_cursor',
        'last_full_sync_at',
        'consecutive_failures',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'last_full_sync_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }
}
