<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('event_report_imports', function (Blueprint $table): void {
            $table->index(
                ['event_id', 'status'],
                'event_report_imports_event_id_status_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_report_imports', function (Blueprint $table): void {
            $table->dropIndex('event_report_imports_event_id_status_index');
        });
    }
};
