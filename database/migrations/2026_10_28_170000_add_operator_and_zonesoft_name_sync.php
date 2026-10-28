<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_zone_assignments', function (Blueprint $table): void {
            $table->string('operator_name', 50)->nullable()->after('machine_id');
        });

        // Desligado por omissão: só os eventos que o admin ativar mudam nomes na ZoneSoft sozinhos.
        Schema::table('events', function (Blueprint $table): void {
            $table->boolean('zonesoft_names_enabled')->default(false);
        });

        Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
            $table->timestamp('zonesoft_name_synced_at')->nullable()->after('last_error');
            $table->text('zonesoft_name_error')->nullable()->after('zonesoft_name_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('zonesoft_names_enabled');
        });

        Schema::table('client_zonesoft_machines', function (Blueprint $table): void {
            $table->dropColumn(['zonesoft_name_synced_at', 'zonesoft_name_error']);
        });

        Schema::table('event_zone_assignments', function (Blueprint $table): void {
            $table->dropColumn('operator_name');
        });
    }
};
