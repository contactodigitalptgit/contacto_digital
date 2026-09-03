<?php

use App\Jobs\SyncEventReportJob;
use App\Models\EventReportImport;
use App\Services\EventReportAutoSyncService;
use App\Services\EventReportSyncService;
use App\Services\SqliteToPostgresMigrator;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('events:sync-report-import {importId}', function (int $importId) {
    $syncLog = EventReportImport::query()
        ->with('event.client')
        ->find($importId);

    if (! $syncLog || $syncLog->status !== 'processing') {
        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }

    try {
        app(EventReportSyncService::class)->run($syncLog);
    } catch (\Throwable $exception) {
        report($exception);

        $this->error($exception->getMessage());

        return \Symfony\Component\Console\Command\Command::FAILURE;
    }

    return \Symfony\Component\Console\Command\Command::SUCCESS;
})->purpose('Run an event report sync import in a detached process');

Artisan::command('events:sync-due-reports {--dry-run : Show the next due event without starting a sync}', function (
    EventReportAutoSyncService $autoSync,
    EventReportSyncService $syncService,
) {
    if (! $autoSync->enabled()) {
        $this->info('Automatic event report synchronization is disabled.');

        return Command::SUCCESS;
    }

    $syncService->markStaleProcessingImportsAsFailed();

    if (EventReportImport::query()->where('status', 'processing')->exists()) {
        $this->info('A synchronization is already in progress.');

        return Command::SUCCESS;
    }

    $event = $autoSync->nextDueEvent();

    if (! $event) {
        $this->info('No event report synchronization is due.');

        return Command::SUCCESS;
    }

    if ($this->option('dry-run')) {
        $this->info("Event #{$event->id} ({$event->title}) is due for synchronization.");

        return Command::SUCCESS;
    }

    try {
        $syncLog = $syncService->start($event);
        SyncEventReportJob::dispatch($syncLog->id, $event->id);
    } catch (\Throwable $exception) {
        report($exception);
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    $this->info("Synchronization #{$syncLog->id} started for event #{$event->id} ({$event->title}).");

    return Command::SUCCESS;
})->purpose('Start the next due automatic event report synchronization');

Schedule::command('events:sync-due-reports')
    ->everyMinute()
    ->withoutOverlapping(10);

// PERF-501 (fase 1): ferramenta de ensaio para a migracao SQLite -> Postgres
// — ver docs/PLANO_DE_PERFORMANCE_SINCRONIZACAO.md. Nao e chamada por
// nenhum agendamento; corre-se manualmente, contra uma copia dos dados,
// nunca diretamente contra producao. O destino tem de ja ter o schema
// (`php artisan migrate --database=pgsql`) antes de correr isto.
Artisan::command(
    'app:migrate-sqlite-to-postgres {--force : Skip the confirmation prompt}',
    function (SqliteToPostgresMigrator $migrator) {
        if (! $this->option('force') && ! $this->confirm(
            'Isto apaga e reescreve todas as tabelas na ligacao "pgsql" a partir da ligacao "sqlite". Continuar?',
        )) {
            $this->info('Cancelado.');

            return Command::SUCCESS;
        }

        $mismatches = [];

        $results = $migrator->migrate(function (string $table): void {
            $this->line("A migrar {$table}...");
        });

        foreach ($results as $table => $result) {
            if ($result['skipped_reason'] !== null) {
                $this->warn("  {$table}: IGNORADA — {$result['skipped_reason']}");

                continue;
            }

            $status = $result['source_count'] === $result['destination_count'] ? 'OK' : 'DIVERGE';

            if ($status === 'DIVERGE') {
                $mismatches[] = $table;
            }

            $this->line(sprintf(
                '  %s: origem=%d destino=%d copiadas=%d [%s]',
                $table,
                $result['source_count'],
                $result['destination_count'],
                $result['copied'],
                $status,
            ));

            if ($result['dropped_columns'] !== []) {
                $this->warn(sprintf(
                    '    AVISO: coluna(s) da origem sem equivalente no destino, nao copiadas: %s',
                    implode(', ', $result['dropped_columns']),
                ));
            }
        }

        $this->line('');
        $this->line('Somas de controlo:');

        foreach ($migrator->verifyControlSums() as $check => $sums) {
            $status = $sums['matches'] ? 'OK' : 'DIVERGE';

            if (! $sums['matches']) {
                $mismatches[] = $check;
            }

            $this->line(sprintf(
                '  %s: origem=%s destino=%s [%s]',
                $check,
                $sums['source_total'],
                $sums['destination_total'],
                $status,
            ));
        }

        if ($mismatches !== []) {
            $this->error('Divergencias encontradas: '.implode(', ', $mismatches));

            return Command::FAILURE;
        }

        $this->info('Migracao concluida — todas as tabelas e somas de controlo batem certo.');

        return Command::SUCCESS;
    },
)->purpose('PERF-501: copy every table from the sqlite connection into the pgsql connection and verify counts/sums match');

// PERF-302: event_report_imports is an audit trail of sync attempts, not
// a data snapshot — BUT event_report_rows.event_report_import_id and
// event_report_payment_documents.event_report_import_id are
// cascadeOnDelete() foreign keys, and PERF-101 repurposed that column to
// mean "the sync attempt that last touched this row", not "which
// snapshot owns it". A row untouched since an older cycle can still point
// at an import that is otherwise inactive/old — deleting that import row
// would silently cascade-delete CURRENT rows too. Only ever prune an
// import that no row or payment document references at all, regardless
// of age or active status; never prunes the active import for an event.
Artisan::command(
    'events:prune-report-imports {--dry-run : Only report what would be deleted}',
    function () {
        $days = max(1, (int) config('event-reports.retention.import_audit_days', 90));
        $cutoff = now()->subDays($days);

        $query = EventReportImport::query()
            ->where('is_active', false)
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('rows')
            ->whereDoesntHave('paymentDocuments');

        $count = $query->count();

        if ($count === 0) {
            $this->info("Nada a podar (nenhuma importação inativa com mais de {$days} dias).");

            return Command::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("{$count} importação(ões) inativa(s) com mais de {$days} dias seriam removidas.");

            return Command::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("{$deleted} importação(ões) inativa(s) com mais de {$days} dias removida(s).");

        return Command::SUCCESS;
    },
)->purpose('PERF-302: prune event_report_imports audit rows older than the configured retention window');

Schedule::command('events:prune-report-imports')
    ->daily();
