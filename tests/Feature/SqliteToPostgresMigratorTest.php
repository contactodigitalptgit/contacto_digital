<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Event;
use App\Models\User;
use App\Services\SqliteToPostgresMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PERF-501: found rehearsing a real sync against production after the
 * cutover — every serial/bigserial sequence on a migrated table was left
 * wherever it was BEFORE the migration (inserting explicit id values does
 * not advance a Postgres sequence, only nextval() does). The very next
 * Eloquent ::create() on any migrated table asked Postgres for an id that
 * already existed. Requires a real local Postgres connection (see
 * docs/PLANO_DE_PERFORMANCE_SINCRONIZACAO.md, PERF-501) — skips itself
 * when one isn't reachable, exactly like SQLiteRuntimeStorageTest does
 * for its own SQLite-only concern.
 */
class SqliteToPostgresMigratorTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_PGSQL_DATABASE = 'contacto_digital_migrator_test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.pgsql.host' => env('TEST_PGSQL_HOST', '127.0.0.1'),
            'database.connections.pgsql.port' => env('TEST_PGSQL_PORT', 5432),
            'database.connections.pgsql.database' => self::TEST_PGSQL_DATABASE,
            'database.connections.pgsql.username' => env('TEST_PGSQL_USERNAME', get_current_user()),
            'database.connections.pgsql.password' => env('TEST_PGSQL_PASSWORD', ''),
        ]);
        DB::purge('pgsql');

        try {
            DB::connection('pgsql')->select('select 1');
        } catch (\Throwable) {
            $this->markTestSkipped('Requires a local Postgres connection (see PERF-501 in the plan).');
        }

        Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'pgsql']);

        $this->beforeApplicationDestroyed(function (): void {
            DB::connection('pgsql')->statement('drop schema public cascade');
            DB::connection('pgsql')->statement('create schema public');
            DB::purge('pgsql');
        });
    }

    public function test_migrate_advances_postgres_sequences_past_the_copied_ids(): void
    {
        $clientUser = User::create([
            'name' => 'Cliente',
            'email' => 'client-seq@example.test',
            'password' => bcrypt('x'),
            'role' => 'client',
        ]);
        // Simulate a table whose rows already carry high explicit ids —
        // exactly the shape a real production copy has (auto-increment
        // ids accumulated over months, not starting near 1).
        DB::table('users')->insert([
            'id' => 500,
            'name' => 'Utilizador antigo',
            'email' => 'old-seq@example.test',
            'password' => bcrypt('x'),
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Cliente Sequencia',
            'address' => 'Rua de Teste',
            'phone' => '+351 930000000',
            'is_active' => true,
        ]);
        Event::create([
            'client_id' => $client->id,
            'title' => 'Evento Sequencia',
            'event_date' => '2026-06-20 12:00:00',
            'report_starts_at' => '2026-06-20 00:00:00',
            'report_ends_at' => '2026-06-20 23:59:59',
            'is_active' => true,
        ]);

        $migrator = new SqliteToPostgresMigrator('sqlite', 'pgsql');
        $migrator->migrate();

        $maxId = DB::connection('pgsql')->table('users')->max('id');
        $this->assertSame(500, $maxId);

        // The real regression: creating a new row through Eloquent right
        // after a migration must not collide with an id the migration
        // already copied in explicitly.
        $newUser = new User;
        $newUser->setConnection('pgsql');
        $newUser->forceFill([
            'name' => 'Novo Utilizador',
            'email' => 'new-seq@example.test',
            'password' => bcrypt('x'),
            'role' => 'client',
        ])->save();

        $this->assertGreaterThan($maxId, $newUser->id);
        $this->assertSame(3, DB::connection('pgsql')->table('users')->count());
    }
}
