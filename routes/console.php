<?php

use App\Jobs\SyncEventReportJob;
use App\Models\EventReportImport;
use App\Services\EventReportAutoSyncService;
use App\Services\EventReportSyncService;
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
