<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDO;
use Tests\TestCase;

class SQLiteRuntimeStorageTest extends TestCase
{
    private string $businessPath;

    private string $runtimePath;

    protected function setUp(): void
    {
        parent::setUp();

        // This whole test exercises a SQLite-only workaround for SQLite's
        // single-writer limitation (see 2026_09_03_080000_isolate_sqlite_runtime_storage.php)
        // — the concern it validates does not exist once the default
        // connection is Postgres (PERF-501), so it has nothing meaningful
        // to prove there.
        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('SQLite-only: this validates a workaround for a limitation Postgres does not have.');
        }

        $this->businessPath = tempnam(sys_get_temp_dir(), 'contacto-business-test-');
        $this->runtimePath = tempnam(sys_get_temp_dir(), 'contacto-runtime-test-');
        config([
            'database.connections.sqlite.database' => $this->businessPath,
            'database.connections.sqlite.busy_timeout' => 50,
            'database.connections.sqlite_runtime.database' => $this->runtimePath,
            'session.driver' => 'database',
            'session.connection' => 'sqlite_runtime',
            'cache.default' => 'database',
            'cache.stores.database.connection' => 'sqlite_runtime',
            'cache.stores.database.lock_connection' => 'sqlite_runtime',
        ]);
        DB::purge('sqlite');
        DB::purge('sqlite_runtime');
        Cache::forgetDriver('database');
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
        (require database_path('migrations/0001_01_01_000001_create_cache_table.php'))->up();

        $this->beforeApplicationDestroyed(function (): void {
            DB::purge('sqlite');
            DB::purge('sqlite_runtime');
            foreach ([$this->businessPath, $this->runtimePath] as $path) {
                foreach (['', '-wal', '-shm'] as $suffix) {
                    if (is_file($path.$suffix)) {
                        unlink($path.$suffix);
                    }
                }
            }
        });
    }

    public function test_migration_preserves_existing_sessions_cache_and_locks(): void
    {
        DB::table('sessions')->insert([
            'id' => str_repeat('a', 40), 'user_id' => 1, 'ip_address' => null,
            'user_agent' => 'test', 'payload' => base64_encode(serialize(['test' => 'kept'])),
            'last_activity' => time(),
        ]);
        DB::table('cache')->insert(['key' => 'login-limit', 'value' => serialize(3), 'expiration' => time() + 60]);
        DB::table('cache_locks')->insert(['key' => 'lock', 'owner' => 'test-owner', 'expiration' => time() + 60]);
        $this->migration()->up();

        foreach (['sessions', 'cache', 'cache_locks'] as $table) {
            $this->assertEquals(DB::table($table)->get(), DB::connection('sqlite_runtime')->table($table)->get());
        }
    }

    public function test_http_sessions_and_cache_work_while_sales_database_has_a_writer(): void
    {
        $this->migration()->up();
        DB::statement('CREATE TABLE sales_probe (total INTEGER)');
        DB::table('sales_probe')->insert(['total' => 100]);
        Route::middleware('web')->get('/runtime-storage-probe', function (Request $request) {
            Cache::put('runtime-probe', 'ok', 60);
            $locked = Cache::lock('runtime-probe-lock', 10)->get(fn () => true);
            $request->session()->put('runtime-probe', 'ok');

            return response()->json([
                'total' => DB::table('sales_probe')->value('total'),
                'cache' => Cache::get('runtime-probe'),
                'lock' => $locked,
            ]);
        });

        $writer = new PDO('sqlite:'.$this->businessPath);
        $writer->exec('BEGIN IMMEDIATE');
        $writer->exec('UPDATE sales_probe SET total = 200');

        try {
            $this->get('/runtime-storage-probe')->assertOk()->assertExactJson([
                'total' => 100, 'cache' => 'ok', 'lock' => true,
            ]);
            $this->assertSame(1, DB::connection('sqlite_runtime')->table('sessions')->count());
            $this->assertSame(0, DB::table('sessions')->count());
        } finally {
            $writer->exec('ROLLBACK');
        }
    }

    public function test_runtime_storage_cannot_use_the_sales_database_file(): void
    {
        config(['database.connections.sqlite_runtime.database' => $this->businessPath]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('separate, pre-created SQLite file');
        $this->migration()->up();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_03_080000_isolate_sqlite_runtime_storage.php');
    }
}
