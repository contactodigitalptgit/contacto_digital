<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_zone_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->date('operational_date');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'operational_date']);
            $table->index(['event_id', 'starts_at', 'ends_at']);
        });

        Schema::table('event_zones', function (Blueprint $table): void {
            $table->foreignId('event_zone_day_id')
                ->nullable()
                ->after('event_id')
                ->constrained('event_zone_days')
                ->restrictOnDelete();
            $table->dropUnique('event_zones_event_name_unique');
            $table->unique(['event_zone_day_id', 'name'], 'event_zones_day_name_unique');
            $table->index(['event_id', 'event_zone_day_id', 'archived_at'], 'event_zones_day_active_index');
        });

        Schema::table('event_zone_assignments', function (Blueprint $table): void {
            $table->dropUnique('event_zone_assignments_machine_start_unique');
            $table->unique(
                ['event_id', 'machine_id', 'event_zone_id', 'starts_at'],
                'event_zone_assignments_machine_zone_start_unique',
            );
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->dateTime('legacy_zone_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('event_zone_days')->exists()
            || DB::table('events')->whereNotNull('legacy_zone_ends_at')->exists()) {
            throw new RuntimeException('Existem dias operacionais ou fechos históricos. A reversão automática perderia dados.');
        }

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('legacy_zone_ends_at');
        });
        Schema::table('event_zones', function (Blueprint $table): void {
            $table->dropIndex('event_zones_day_active_index');
            $table->dropUnique('event_zones_day_name_unique');
            $table->dropConstrainedForeignId('event_zone_day_id');
            $table->unique(['event_id', 'name'], 'event_zones_event_name_unique');
        });

        Schema::table('event_zone_assignments', function (Blueprint $table): void {
            $table->dropUnique('event_zone_assignments_machine_zone_start_unique');
            $table->unique(
                ['event_id', 'machine_id', 'starts_at'],
                'event_zone_assignments_machine_start_unique',
            );
        });

        Schema::dropIfExists('event_zone_days');
    }
};
