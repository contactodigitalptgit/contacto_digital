<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('client_zonesoft_machines', 'client_zonesoft_machine_unique')) {
            Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
                $table->dropUnique('client_zonesoft_machine_unique');
            });
        }

        if (! Schema::hasColumn('client_zonesoft_machines', 'event_id')) {
            Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
                $table->foreignId('event_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained()
                    ->cascadeOnDelete();
            });
        }

        $this->copyClientMachinesToEvents();

        if (! $this->indexExists('client_zonesoft_machines', 'client_zs_machines_event_store_unique')) {
            Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
                $table->unique(
                    ['event_id', 'zs_client_id', 'store_id'],
                    'client_zs_machines_event_store_unique',
                );
            });
        }

        if (! $this->indexExists('client_zonesoft_machines', 'client_zonesoft_machines_event_id_is_active_index')) {
            Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
                $table->index(['event_id', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('client_zonesoft_machines', 'client_zonesoft_machines_event_id_is_active_index')) {
            Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
                $table->dropIndex(['event_id', 'is_active']);
            });
        }

        if ($this->indexExists('client_zonesoft_machines', 'client_zs_machines_event_store_unique')) {
            Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
                $table->dropUnique('client_zs_machines_event_store_unique');
            });
        }

        if (Schema::hasColumn('client_zonesoft_machines', 'event_id')) {
            Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('event_id');
            });
        }

        if (! $this->indexExists('client_zonesoft_machines', 'client_zonesoft_machine_unique')) {
            Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
                $table->unique(
                    ['client_id', 'zs_client_id', 'store_id'],
                    'client_zonesoft_machine_unique',
                );
            });
        }
    }

    private function copyClientMachinesToEvents(): void
    {
        DB::table('client_zonesoft_machines')
            ->whereNull('event_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $machine): void {
                $eventIds = DB::table('events')
                    ->where('client_id', $machine->client_id)
                    ->orderBy('id')
                    ->pluck('id');

                $this->attachMachineToEvents($machine, $eventIds);
            });
    }

    /**
     * @param  Collection<int, int>  $eventIds
     */
    private function attachMachineToEvents(object $machine, Collection $eventIds): void
    {
        $firstEventId = $eventIds->shift();

        if ($firstEventId === null) {
            return;
        }

        DB::table('client_zonesoft_machines')
            ->where('id', $machine->id)
            ->update(['event_id' => $firstEventId]);

        foreach ($eventIds as $eventId) {
            $alreadyExists = DB::table('client_zonesoft_machines')
                ->where('event_id', $eventId)
                ->where('zs_client_id', $machine->zs_client_id)
                ->where('store_id', $machine->store_id)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            DB::table('client_zonesoft_machines')->insert([
                'client_id' => $machine->client_id,
                'event_id' => $eventId,
                'zonesoft_application_id' => $machine->zonesoft_application_id,
                'zs_client_id' => $machine->zs_client_id,
                'license' => $machine->license,
                'store_id' => $machine->store_id,
                'store_label' => $machine->store_label,
                'permissions' => $machine->permissions,
                'is_active' => $machine->is_active,
                'last_validated_at' => $machine->last_validated_at,
                'last_error' => $machine->last_error,
                'created_at' => $machine->created_at,
                'updated_at' => $machine->updated_at,
            ]);
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }
};
