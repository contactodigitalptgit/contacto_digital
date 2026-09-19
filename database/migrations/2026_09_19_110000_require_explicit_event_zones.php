<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->boolean('requires_explicit_zones')->default(false);
        });

        // Preserve legacy events without zones, but protect events whose zones
        // have already been configured (including their historical assignments).
        DB::table('events')
            ->whereIn('id', DB::table('event_zones')->select('event_id'))
            ->update(['requires_explicit_zones' => true]);
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('requires_explicit_zones');
        });
    }
};
