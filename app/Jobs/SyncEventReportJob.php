<?php

namespace App\Jobs;

use App\Models\EventReportImport;
use App\Services\EventReportSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncEventReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 0;

    public function __construct(
        public readonly int $importId,
        public readonly int $eventId,
    ) {
        $this->onConnection('background');
    }

    public function handle(EventReportSyncService $syncService): void
    {
        $syncLog = EventReportImport::query()
            ->with('event.client')
            ->find($this->importId);

        if (! $syncLog || $syncLog->status !== 'processing') {
            return;
        }

        $syncService->run($syncLog);
    }
}
