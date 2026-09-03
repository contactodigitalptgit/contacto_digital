<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PERF-501 (fase 1): copies every application table from a SQLite
 * connection into a Postgres connection, table by table, verifying row
 * counts on both sides afterwards. This is the mechanism phases 2/3 will
 * run against a real copy of production data (never production directly)
 * — see docs/PLANO_DE_PERFORMANCE_SINCRONIZACAO.md (PERF-501).
 *
 * The table list itself is read from sqlite_master (not hardcoded), so it
 * keeps working as new migrations add tables instead of silently skipping
 * ones nobody remembered to add here — but the ORDER tables are migrated
 * in has to respect foreign keys (a payment document referencing a machine
 * that doesn't exist yet in the destination fails outright, and Laravel's
 * default `foreignId()->constrained()` migrations are not DEFERRABLE, so
 * Postgres enforces each constraint immediately on insert, not just at
 * commit). ORDERED_TABLES pins the known dependency order for the tables
 * that actually have foreign keys between them; anything not listed there
 * (session/cache/queue tables, or a table a future migration adds) is
 * migrated afterward, in whatever order sqlite_master returns it — safe
 * as long as it has no FK pointing at something still unmigrated, true of
 * every table added here so far.
 *
 * Assumes the destination schema already exists (`php artisan migrate`
 * already run against the Postgres connection) and that the source is not
 * being written to concurrently — this is meant to run during the
 * maintenance window a real cutover already stops workers/scheduler for
 * (see deploy/server-deploy.sh), not against a live-traffic database.
 */
class SqliteToPostgresMigrator
{
    /**
     * 'migrations' gets its own fresh rows from `php artisan migrate`
     * having already run against the destination — copying the source's
     * migration history over would just be noise (and could even disagree
     * with what actually ran against Postgres).
     *
     * @var list<string>
     */
    private const EXCLUDED_TABLES = ['migrations'];

    /**
     * Foreign-key-safe insertion order — see the class docblock.
     *
     * @var list<string>
     */
    private const ORDERED_TABLES = [
        'users',
        'clients',
        'zonesoft_applications',
        'client_zonesoft_machines',
        'events',
        'event_zonesoft_machines',
        'event_report_imports',
        'event_report_rows',
        'event_report_payment_documents',
        'event_report_row_aggregates',
        'event_report_ticket_aggregates',
    ];

    public function __construct(
        private readonly string $sourceConnection = 'sqlite',
        private readonly string $destinationConnection = 'pgsql',
        private readonly int $chunkSize = 500,
    ) {}

    /**
     * @return array<string, array{copied:int, source_count:int, destination_count:int}>
     */
    public function migrate(?callable $onTableStart = null): array
    {
        $results = [];

        foreach ($this->sourceTables() as $table) {
            if ($onTableStart !== null) {
                $onTableStart($table);
            }

            $results[$table] = $this->migrateTable($table);
        }

        return $results;
    }

    /**
     * Control sums beyond plain row counts, for the two tables where a
     * silently-wrong monetary value would matter most. Row counts alone
     * can't catch "same number of rows, wrong values" (e.g. a truncated
     * decimal column) — this can.
     *
     * @return array<string, array{source_total:string, destination_total:string, matches:bool}>
     */
    public function verifyControlSums(): array
    {
        $checks = [
            'event_report_rows' => 'total',
            'event_report_payment_documents' => 'total',
        ];

        $results = [];

        foreach ($checks as $table => $column) {
            if (! Schema::connection($this->sourceConnection)->hasTable($table)) {
                continue;
            }

            $sourceTotal = (string) DB::connection($this->sourceConnection)->table($table)->sum($column);
            $destinationTotal = (string) DB::connection($this->destinationConnection)->table($table)->sum($column);

            $results[$table.'.'.$column] = [
                'source_total' => $sourceTotal,
                'destination_total' => $destinationTotal,
                'matches' => abs((float) $sourceTotal - (float) $destinationTotal) < 0.0001,
            ];
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function sourceTables(): array
    {
        $tables = DB::connection($this->sourceConnection)
            ->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'");

        $present = collect($tables)
            ->pluck('name')
            ->reject(fn (string $name): bool => in_array($name, self::EXCLUDED_TABLES, true))
            ->values();

        $ordered = collect(self::ORDERED_TABLES)->intersect($present)->values();
        $remaining = $present->diff($ordered)->values();

        return $ordered->merge($remaining)->all();
    }

    /**
     * @return array{copied:int, source_count:int, destination_count:int}
     */
    private function migrateTable(string $table): array
    {
        $source = DB::connection($this->sourceConnection);
        $destination = DB::connection($this->destinationConnection);

        if (! Schema::connection($this->destinationConnection)->hasTable($table)) {
            throw new \RuntimeException(sprintf(
                "A tabela '%s' existe na origem (SQLite) mas nao no destino (Postgres) — corra ".
                "'php artisan migrate --database=%s' no destino antes de migrar os dados.",
                $table,
                $this->destinationConnection,
            ));
        }

        $columns = $this->sourceColumns($table);
        $booleanColumns = $this->destinationBooleanColumns($table);

        // Idempotent: safe to re-run a rehearsal without manually truncating
        // the destination first. Deliberately not wrapped in one
        // transaction spanning every table — each table's own chunk loop
        // is the unit of retry/inspection if something goes wrong midway.
        $destination->table($table)->delete();

        $copied = 0;
        $orderedQuery = $source->table($table);

        foreach ($columns as $column) {
            $orderedQuery->orderBy($column);
        }

        $orderedQuery->chunk($this->chunkSize, function (Collection $rows) use ($destination, $table, $booleanColumns, &$copied): void {
            $payload = $rows
                ->map(fn ($row) => $this->normalizeRowForPostgres((array) $row, $booleanColumns))
                ->all();

            if ($payload !== []) {
                $destination->table($table)->insert($payload);
                $copied += count($payload);
            }
        });

        return [
            'copied' => $copied,
            'source_count' => (int) $source->table($table)->count(),
            'destination_count' => (int) $destination->table($table)->count(),
        ];
    }

    /**
     * @return list<string>
     */
    private function sourceColumns(string $table): array
    {
        return Schema::connection($this->sourceConnection)->getColumnListing($table);
    }

    /**
     * SQLite stores booleans as the integers 0/1; PDO's pgsql driver does
     * not reliably coerce a bound integer into a genuine Postgres boolean
     * column, so those columns need an explicit PHP bool value on insert.
     * Everything else (including JSON-as-text and datetime strings, both
     * stored in a format Postgres accepts natively) passes through
     * unchanged.
     *
     * @return list<string>
     */
    private function destinationBooleanColumns(string $table): array
    {
        return collect(Schema::connection($this->destinationConnection)->getColumns($table))
            ->filter(fn (array $column): bool => in_array(
                strtolower((string) ($column['type_name'] ?? '')),
                ['bool', 'boolean'],
                true,
            ))
            ->pluck('name')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $booleanColumns
     * @return array<string, mixed>
     */
    private function normalizeRowForPostgres(array $row, array $booleanColumns): array
    {
        foreach ($booleanColumns as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (bool) $row[$column];
            }
        }

        return $row;
    }
}
