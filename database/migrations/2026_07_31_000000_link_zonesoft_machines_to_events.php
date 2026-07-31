<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
            $table->dropUnique('client_zonesoft_machine_unique');
            $table->foreignId('event_id')
                ->nullable()
                ->after('client_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unique(
                ['event_id', 'zs_client_id', 'store_id'],
                'client_zs_machines_event_store_unique',
            );
            $table->index(['event_id', 'is_active']);
        });

        DB::table('client_zonesoft_machines')
            ->orderBy('id')
            ->get()
            ->each(function (object $machine): void {
                $eventIds = DB::table('events')
                    ->where('client_id', $machine->client_id)
                    ->orderBy('id')
                    ->pluck('id');

                $firstEventId = $eventIds->shift();

                if ($firstEventId === null) {
                    return;
                }

                DB::table('client_zonesoft_machines')
                    ->where('id', $machine->id)
                    ->update(['event_id' => $firstEventId]);

                foreach ($eventIds as $eventId) {
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
            });
    }

    public function down(): void
    {
        DB::table('client_zonesoft_machines')
            ->whereNotNull('event_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (object $machine): string => implode('|', [
                $machine->client_id,
                $machine->zs_client_id,
                $machine->store_id,
            ]))
            ->each(function ($machines): void {
                $machines->skip(1)->each(
                    fn (object $machine) => DB::table('client_zonesoft_machines')
                        ->where('id', $machine->id)
                        ->delete(),
                );
            });

        Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
            $table->dropUnique('client_zs_machines_event_store_unique');
            $table->dropIndex(['event_id', 'is_active']);
            $table->dropConstrainedForeignId('event_id');
            $table->unique(
                ['client_id', 'zs_client_id', 'store_id'],
                'client_zonesoft_machine_unique',
            );
        });
    }
};
