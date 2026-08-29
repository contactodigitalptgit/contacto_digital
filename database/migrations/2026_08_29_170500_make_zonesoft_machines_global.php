<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $eventLinks = $this->consolidateMachinesAndCollectEventLinks();
        $paymentMachineLinks = Schema::hasTable('event_report_payment_documents')
            ? DB::table('event_report_payment_documents')
                ->whereNotNull('machine_id')
                ->pluck('machine_id', 'id')
                ->all()
            : [];

        Schema::disableForeignKeyConstraints();

        try {
            Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
                if ($this->indexExists('client_zonesoft_machines', 'client_zs_machines_event_store_unique')) {
                    $table->dropUnique('client_zs_machines_event_store_unique');
                }

                if ($this->indexExists('client_zonesoft_machines', 'client_zonesoft_machines_event_id_is_active_index')) {
                    $table->dropIndex('client_zonesoft_machines_event_id_is_active_index');
                }
            });

            if (Schema::hasColumn('client_zonesoft_machines', 'event_id')) {
                Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
                    $table->dropConstrainedForeignId('event_id');
                });
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
            $table->unique(
                ['client_id', 'zonesoft_application_id', 'zs_client_id', 'store_id'],
                'client_zs_machines_global_unique',
            );
        });

        Schema::create('event_zonesoft_machines', function (Blueprint $table): void {
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_zonesoft_machine_id')
                ->constrained('client_zonesoft_machines')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->primary(
                ['event_id', 'client_zonesoft_machine_id'],
                'event_zonesoft_machines_primary',
            );
        });

        if ($eventLinks !== []) {
            DB::table('event_zonesoft_machines')->insertOrIgnore($eventLinks);
        }

        foreach ($paymentMachineLinks as $documentId => $machineId) {
            DB::table('event_report_payment_documents')
                ->where('id', $documentId)
                ->update(['machine_id' => $machineId]);
        }
    }

    public function down(): void
    {
        $eventLinks = DB::table('event_zonesoft_machines')
            ->orderBy('event_id')
            ->get()
            ->groupBy('client_zonesoft_machine_id')
            ->map(fn ($links) => $links->pluck('event_id'));

        Schema::disableForeignKeyConstraints();

        try {
            Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
                if ($this->indexExists('client_zonesoft_machines', 'client_zs_machines_global_unique')) {
                    $table->dropUnique('client_zs_machines_global_unique');
                }

                $table->foreignId('event_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained()
                    ->cascadeOnDelete();
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        DB::table('client_zonesoft_machines')
            ->orderBy('id')
            ->get()
            ->each(function (object $machine) use ($eventLinks): void {
                $eventIds = collect($eventLinks->get($machine->id, []));

                $firstEventId = $eventIds->shift();

                if ($firstEventId !== null) {
                    DB::table('client_zonesoft_machines')
                        ->where('id', $machine->id)
                        ->update(['event_id' => $firstEventId]);
                }

                foreach ($eventIds as $eventId) {
                    $copy = (array) $machine;
                    unset($copy['id']);
                    $copy['event_id'] = $eventId;

                    DB::table('client_zonesoft_machines')->insert($copy);
                }
            });

        Schema::dropIfExists('event_zonesoft_machines');

        Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
            $table->unique(
                ['event_id', 'zs_client_id', 'store_id'],
                'client_zs_machines_event_store_unique',
            );
            $table->index(['event_id', 'is_active']);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function consolidateMachinesAndCollectEventLinks(): array
    {
        $canonicalIds = [];
        $canonicalUpdatedAt = [];
        $eventLinks = [];
        $timestamp = now();

        DB::table('client_zonesoft_machines')
            ->orderBy('id')
            ->get()
            ->each(function (object $machine) use (&$canonicalIds, &$canonicalUpdatedAt, &$eventLinks, $timestamp): void {
                $key = implode('|', [
                    $machine->client_id,
                    $machine->zonesoft_application_id,
                    $machine->zs_client_id,
                    $machine->store_id,
                ]);
                $canonicalId = $canonicalIds[$key] ?? $machine->id;
                $canonicalIds[$key] = $canonicalId;

                if (! isset($canonicalUpdatedAt[$key])) {
                    $canonicalUpdatedAt[$key] = $machine->updated_at;
                }

                if ($machine->event_id !== null) {
                    $eventLinks[$machine->event_id.'|'.$canonicalId] = [
                        'event_id' => $machine->event_id,
                        'client_zonesoft_machine_id' => $canonicalId,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                if ($canonicalId === $machine->id) {
                    return;
                }

                if ((string) $machine->updated_at > (string) $canonicalUpdatedAt[$key]) {
                    DB::table('client_zonesoft_machines')
                        ->where('id', $canonicalId)
                        ->update([
                            'license' => $machine->license,
                            'store_label' => $machine->store_label,
                            'permissions' => $machine->permissions,
                            'is_active' => $machine->is_active,
                            'last_validated_at' => $machine->last_validated_at,
                            'last_error' => $machine->last_error,
                            'updated_at' => $machine->updated_at,
                        ]);
                    $canonicalUpdatedAt[$key] = $machine->updated_at;
                }

                if (Schema::hasTable('event_report_payment_documents')) {
                    DB::table('event_report_payment_documents')
                        ->where('machine_id', $machine->id)
                        ->update(['machine_id' => $canonicalId]);
                }

                DB::table('client_zonesoft_machines')
                    ->where('id', $machine->id)
                    ->delete();
            });

        return array_values($eventLinks);
    }

    private function indexExists(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }
};
