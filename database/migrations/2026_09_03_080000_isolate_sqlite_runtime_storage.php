<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PERF-501: this whole migration is a workaround for SQLite's
        // single-writer limitation (moving sessions/cache into a separate
        // file so sync writes stop blocking HTTP session reads) — it has
        // nothing to do on a non-sqlite destination, which doesn't have
        // that limitation at all. Nothing to isolate there.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $path = config('database.connections.sqlite_runtime.database');

        if (! $path) {
            return;
        }

        if (! is_file($path)
            || realpath($path) === realpath(DB::connection()->getDatabaseName())) {
            throw new RuntimeException('Runtime storage requires a separate, pre-created SQLite file.');
        }

        $runtime = DB::connection('sqlite_runtime');
        $schema = Schema::connection('sqlite_runtime');

        $runtime->transaction(function () use ($runtime, $schema): void {
            $schema->create('sessions', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
            $schema->create('cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration')->index();
            });
            $schema->create('cache_locks', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration')->index();
            });

            // Deploy runs in maintenance with workers stopped, preserving active sessions and rate limits.
            foreach (['sessions' => 'id', 'cache' => 'key', 'cache_locks' => 'key'] as $table => $key) {
                DB::table($table)->orderBy($key)->chunk(100, function ($rows) use ($runtime, $table): void {
                    $runtime->table($table)->insert($rows->map(fn ($row) => (array) $row)->all());
                });

                if ($runtime->table($table)->count() !== DB::table($table)->count()) {
                    throw new RuntimeException("Runtime storage copy count mismatch for {$table}.");
                }
            }
        });
    }

    public function down(): void
    {
        // Retain both copies: rolling code back must not delete sessions created after deployment.
    }
};
