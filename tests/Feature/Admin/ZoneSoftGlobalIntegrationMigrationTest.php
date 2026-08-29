<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Event;
use App\Models\User;
use App\Models\ZoneSoftApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZoneSoftGlobalIntegrationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_consolidates_event_duplicates_and_preserves_history(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $client = Client::create([
            'user_id' => $user->id,
            'name' => 'Cliente Recorrente',
            'address' => 'Rua do Evento',
            'phone' => '+351 910000000',
            'is_active' => true,
        ]);
        $firstEvent = Event::create([
            'client_id' => $client->id,
            'title' => 'Evento 2026',
            'event_date' => now(),
            'report_ends_at' => now()->addDay(),
            'is_active' => true,
        ]);
        $secondEvent = Event::create([
            'client_id' => $client->id,
            'title' => 'Evento 2027',
            'event_date' => now()->addYear(),
            'report_ends_at' => now()->addYear()->addDay(),
            'is_active' => true,
        ]);
        $application = ZoneSoftApplication::create([
            'name' => 'Portal Contactodigital',
            'base_url' => 'https://api.zonesoft.org/v3',
            'app_key' => 'test-key',
            'app_secret' => 'test-secret',
            'is_active' => true,
        ]);
        $migration = require database_path(
            'migrations/2026_08_29_170500_make_zonesoft_machines_global.php',
        );
        $migration->down();

        $firstMachineId = DB::table('client_zonesoft_machines')->insertGetId([
            'client_id' => $client->id,
            'event_id' => $firstEvent->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'SHARED-CLIENT-ID',
            'license' => 'SHARED-LICENSE',
            'store_id' => 151,
            'store_label' => 'Nome antigo',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $secondMachineId = DB::table('client_zonesoft_machines')->insertGetId([
            'client_id' => $client->id,
            'event_id' => $secondEvent->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'SHARED-CLIENT-ID',
            'license' => 'SHARED-LICENSE',
            'store_id' => 151,
            'store_label' => 'Nome atualizado',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $importId = DB::table('event_report_imports')->insertGetId([
            'event_id' => $secondEvent->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'file_hash' => hash('sha256', 'migration-test'),
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('event_report_payment_documents')->insert([
            'event_id' => $secondEvent->id,
            'event_report_import_id' => $importId,
            'machine_id' => $secondMachineId,
            'dedupe_key' => 'migration-test-payment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertDatabaseCount('client_zonesoft_machines', 1);
        $this->assertDatabaseHas('client_zonesoft_machines', [
            'id' => $firstMachineId,
            'store_label' => 'Nome atualizado',
        ]);
        $this->assertDatabaseHas('event_zonesoft_machines', [
            'event_id' => $firstEvent->id,
            'client_zonesoft_machine_id' => $firstMachineId,
        ]);
        $this->assertDatabaseHas('event_zonesoft_machines', [
            'event_id' => $secondEvent->id,
            'client_zonesoft_machine_id' => $firstMachineId,
        ]);
        $this->assertDatabaseHas('event_report_payment_documents', [
            'event_report_import_id' => $importId,
            'machine_id' => $firstMachineId,
        ]);
    }
}
