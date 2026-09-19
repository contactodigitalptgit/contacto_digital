<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the manual zone history intact, but return every event to the
        // original name-based grouping used by both the web and mobile APIs.
        DB::table('events')->update([
            'requires_explicit_zones' => false,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('events')
            ->whereIn('id', DB::table('event_zones')->select('event_id'))
            ->update([
                'requires_explicit_zones' => true,
                'updated_at' => now(),
            ]);
    }
};
