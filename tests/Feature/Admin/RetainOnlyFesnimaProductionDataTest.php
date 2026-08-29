<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetainOnlyFesnimaProductionDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_keeps_only_fesnima_and_admin_users(): void
    {
        $now = now();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'x', 'role' => 'admin', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Fesnima', 'email' => 'fesnima@example.com', 'password' => 'x', 'role' => 'client', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Outro', 'email' => 'outro@example.com', 'password' => 'x', 'role' => 'client', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('clients')->insert([
            ['id' => 6, 'user_id' => 2, 'name' => 'Fesnima', 'business_name' => 'Fesnima', 'address' => 'Faro', 'phone' => '1', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'user_id' => 3, 'name' => 'Outro', 'business_name' => 'Outro', 'address' => 'Porto', 'phone' => '2', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('events')->insert([
            ['id' => 8, 'client_id' => 6, 'title' => 'Festival de Marisco 2026', 'event_date' => $now, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 7, 'client_id' => 4, 'title' => 'Outro Evento', 'event_date' => $now, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('zonesoft_applications')->insert([
            ['id' => 1, 'name' => 'Fesnima App', 'app_key' => 'keep', 'app_secret' => 'keep', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Other App', 'app_key' => 'delete', 'app_secret' => 'delete', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('client_zonesoft_machines')->insert([
            ['id' => 1, 'client_id' => 6, 'zonesoft_application_id' => 1, 'zs_client_id' => 'keep', 'store_id' => 151, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'client_id' => 4, 'zonesoft_application_id' => 2, 'zs_client_id' => 'delete', 'store_id' => 150, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('event_zonesoft_machines')->insert([
            ['event_id' => 8, 'client_zonesoft_machine_id' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['event_id' => 7, 'client_zonesoft_machine_id' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $originalEnvironment = $this->app->environment();
        $this->app['env'] = 'production';

        try {
            $migration = require database_path('migrations/2026_08_29_180000_retain_only_fesnima_production_data.php');
            $migration->up();
        } finally {
            $this->app['env'] = $originalEnvironment;
        }

        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseHas('clients', ['id' => 6, 'user_id' => 2]);
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseHas('events', ['id' => 8, 'client_id' => 6]);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('users', ['id' => 1, 'role' => 'admin']);
        $this->assertDatabaseHas('users', ['id' => 2, 'role' => 'client']);
        $this->assertDatabaseCount('client_zonesoft_machines', 1);
        $this->assertDatabaseHas('client_zonesoft_machines', ['id' => 1, 'client_id' => 6]);
        $this->assertDatabaseCount('zonesoft_applications', 1);
        $this->assertDatabaseHas('zonesoft_applications', ['id' => 1]);
        $this->assertDatabaseCount('event_zonesoft_machines', 1);
        $this->assertDatabaseHas('event_zonesoft_machines', ['event_id' => 8]);
    }
}
