<?php

namespace App\Services;

use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportPaymentDocument;
use App\Models\EventReportRow;
use App\Models\EventReportRowAggregate;
use App\Models\EventReportTicketAggregate;
use App\Models\User;
use App\Services\ZoneSoft\ZoneSoftApiClient;
use App\Services\ZoneSoft\ZoneSoftApiException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventReportSyncService
{
    private const GLOBAL_SYNC_START_LOCK = 'event-report:global-sync-start';

    private const DOCUMENT_SYNC_CONCURRENCY = 2;

    private const DOCUMENT_REQUEST_BATCH_SIZE = 50;

    private const REPORT_TIMEZONE = 'Europe/Lisbon';

    /**
     * PERF-402: public so EventDashboardController can exclude stale
     * processing imports from its counts/display without writing anything
     * — the scheduler (routes/console.php, events:sync-due-reports, every
     * minute) is the only place that actually flips them to 'failed'.
     */
    public const STALE_PROCESSING_TIMEOUT_MINUTES = 30;

    private const RATE_LIMIT_SERIAL_RETRY_ROUNDS = 2;

    private const RATE_LIMIT_SERIAL_RETRY_ROUND_PAUSE_MICROSECONDS = 2000000;

    private const FINALIZATION_TRANSACTION_ATTEMPTS = 5;

    private const STAGING_INSERT_BATCH_SIZE = 500;

    public function __construct(
        private readonly ZoneSoftApiClient $apiClient,
    ) {}

    public function sync(Event $event, ?User $uploadedBy = null): EventReportImport
    {
        $syncLog = $this->start($event, $uploadedBy);

        return $this->run($syncLog);
    }

    public function start(Event $event, ?User $uploadedBy = null): EventReportImport
    {
        $syncLog = Cache::lock(self::GLOBAL_SYNC_START_LOCK, 30)->get(
            fn (): EventReportImport => DB::transaction(function () use ($event, $uploadedBy): EventReportImport {
                $lockedEvent = Event::query()
                    ->with('client')
                    ->lockForUpdate()
                    ->findOrFail($event->id);

                $this->markStaleProcessingImportsAsFailed();

                if (EventReportImport::query()->where('status', 'processing')->exists()) {
                    throw ValidationException::withMessages([
                        'integration' => 'Ja existe uma sincronizacao em andamento. Aguarde a conclusao antes de iniciar outra.',
                    ]);
                }

                $machines = $this->resolveMachines($lockedEvent);

                return $this->createSyncLog($lockedEvent, $machines, $uploadedBy);
            }),
        );

        if (! $syncLog instanceof EventReportImport) {
            throw ValidationException::withMessages([
                'integration' => 'Ja existe uma sincronizacao em andamento. Aguarde a conclusao antes de iniciar outra.',
            ]);
        }

        return $syncLog;
    }

    public function markStaleProcessingImportsAsFailed(?Event $event = null): int
    {
        $cutoff = now()->subMinutes(self::STALE_PROCESSING_TIMEOUT_MINUTES);

        $query = EventReportImport::query()
            ->where('status', 'processing')
            ->where('updated_at', '<=', $cutoff);

        if ($event) {
            $query->where('event_id', $event->id);
        }

        $staleImports = $query->get();

        foreach ($staleImports as $staleImport) {
            $staleImport->update([
                'status' => 'failed',
                'summary' => [
                    ...(is_array($staleImport->summary) ? $staleImport->summary : []),
                    'error' => 'A sincronizacao anterior foi marcada como falhada por falta de conclusao.',
                ],
            ]);
        }

        return $staleImports->count();
    }

    /**
     * @param  array<string, mixed>|null  $summary
     */
    public function fail(
        EventReportImport $syncLog,
        string $message,
        ?array $summary = null,
    ): void {
        // PERF-101: event_report_rows/event_report_payment_documents are only
        // ever written inside the finalization transaction in run(), so a
        // failed sync (by definition, one that never reached that point) has
        // nothing to clean up here anymore.
        $this->markSyncAsFailed($syncLog, $message, $summary);
    }

    public function run(EventReportImport $syncLog): EventReportImport
    {
        $runStartedAt = microtime(true);
        $failureSummary = null;
        $this->prepareLongRunningSync();
        $syncLog->loadMissing('event.client');

        $event = $syncLog->event;

        if (! $event) {
            throw new \RuntimeException('O evento associado a esta sincronizacao nao foi encontrado.');
        }

        $this->ensureSyncIsProcessing($syncLog);
        $syncLog->touch();

        try {
            $machines = $this->resolveMachines($event);
            $fetchStartedAt = microtime(true);
            $machineSync = $this->fetchRows($event, $machines, $syncLog);
            $fetchDurationMs = (int) round((microtime(true) - $fetchStartedAt) * 1000);

            $successfulMachinesCount = $machineSync['successful_machines_count'];
            $failedMachines = $machineSync['failed_machines'];
            $machineWarnings = $machineSync['machine_warnings'];

            if ($successfulMachinesCount === 0) {
                throw ValidationException::withMessages([
                    'integration' => $this->buildMachineFailureMessage($failedMachines),
                ]);
            }

            // Nothing has touched event_report_rows/event_report_payment_documents
            // yet at this point (PERF-101: the fetch phase only accumulates the
            // delta in memory). This is only a diagnostic snapshot in case we
            // never reach publication below.
            $failureSummary = $this->buildPreliminarySummary(
                $machineSync,
                $successfulMachinesCount,
                $failedMachines,
                $machineWarnings,
            );

            if ($failedMachines !== [] || $machineWarnings !== []) {
                $message = $this->buildIncompleteSyncMessage($failedMachines, $machineWarnings);

                throw ValidationException::withMessages([
                    'integration' => $message,
                ]);
            }

            $timestamp = now();

            $completedSync = DB::transaction(function () use (
                $event,
                $syncLog,
                $machineSync,
                $successfulMachinesCount,
                $timestamp,
                $runStartedAt,
                $fetchDurationMs,
            ): EventReportImport {
                $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->id);
                $lockedSyncLog = EventReportImport::query()->lockForUpdate()->findOrFail($syncLog->id);

                if ($lockedSyncLog->status !== 'processing') {
                    return $lockedSyncLog;
                }

                $newerSyncExists = $lockedEvent->reportImports()
                    ->whereKeyNot($lockedSyncLog->id)
                    ->where('id', '>', $lockedSyncLog->id)
                    ->whereIn('status', ['processing', 'completed'])
                    ->exists();

                if ($newerSyncExists) {
                    $lockedSyncLog->update([
                        'status' => 'failed',
                        'is_active' => false,
                        'summary' => [
                            ...($lockedSyncLog->summary ?? []),
                            'error' => 'A sincronizacao foi substituida por uma execucao mais recente.',
                        ],
                    ]);

                    return $lockedSyncLog->fresh();
                }

                // This is the only place event_report_rows/event_report_payment_documents
                // are ever written. It only touches the delta this cycle actually
                // fetched (bounded by what changed, not the whole dataset), and it
                // runs inside this short transaction so a crash or a superseding
                // sync before this point leaves the previously published data
                // completely untouched.
                $this->upsertRows($event, $lockedSyncLog, $machineSync['pending_rows'], $timestamp);
                $this->upsertPaymentDocuments($event, $lockedSyncLog, $machineSync['pending_payment_documents'], $timestamp);
                $this->reconcileFetchedDocuments(
                    $event->id,
                    $machineSync['pending_reconciliations'],
                    $machineSync['pending_rows'],
                    $machineSync['pending_payment_documents'],
                    $machineSync['document_fetch_mode'] === 'full',
                );

                // PERF-401 (fatia 1): keep the dashboard's pre-aggregated
                // tables in step with the delta this cycle just published —
                // scoped to only the machines actually touched, so this is
                // O(that machine's rows), never O(dataset).
                $this->refreshRowAggregates(
                    $event->id,
                    $this->touchedMachineIds($machineSync['pending_rows'], $machineSync['pending_reconciliations']),
                );

                $summary = $this->buildSummaryFromCurrentState(
                    $event->id,
                    $successfulMachinesCount,
                    [],
                    [],
                    count($machineSync['pending_rows']),
                    $machineSync['metrics'],
                );
                $summary['performance'] = [
                    'machine_duration_ms' => $summary['performance']['machine_duration_ms'] ?? 0,
                    'slowest_machines' => $summary['performance']['slowest_machines'] ?? [],
                    'fetch_duration_ms' => $fetchDurationMs,
                    'total_duration_ms' => (int) round((microtime(true) - $runStartedAt) * 1000),
                ];
                $summary['historical_data_complete'] = true;
                $summary['sync_range'] = $machineSync['sync_range'];
                $summary['fetch_range'] = $machineSync['fetch_range'];
                $summary['document_cursor_version'] = 1;
                $summary['document_fetch_mode'] = $machineSync['document_fetch_mode'];
                $summary['machine_document_cursors'] = $machineSync['machine_document_cursors'];
                $summary['last_full_document_sync_at'] = $machineSync['last_full_document_sync_at'];

                $lockedEvent->reportImports()
                    ->where('is_active', true)
                    ->update(['is_active' => false]);

                $lockedSyncLog->update([
                    'summary' => $summary,
                    'imported_rows_count' => (int) ($summary['rows_count'] ?? 0),
                    'imported_at' => $timestamp,
                    'is_active' => true,
                    'status' => 'completed',
                ]);

                return $lockedSyncLog->fresh();
            }, self::FINALIZATION_TRANSACTION_ATTEMPTS);

            if ($completedSync->status !== 'completed') {
                throw new \RuntimeException(
                    $completedSync->summary['error']
                        ?? 'A sincronizacao deixou de estar ativa antes da publicacao.',
                );
            }

            return $completedSync;
        } catch (\Throwable $exception) {
            $this->fail(
                $syncLog,
                $this->resolveExceptionMessage($exception),
                $failureSummary,
            );

            throw $exception;
        }
    }

    /**
     * @return Collection<int, ClientZoneSoftMachine>
     */
    private function resolveMachines(Event $event): Collection
    {
        $machines = $event->zonesoftMachines()
            ->with('application')
            ->where('is_active', true)
            ->get();

        if ($machines->isEmpty()) {
            throw ValidationException::withMessages([
                'integration' => 'Este evento ainda nao possui Client IDs ativos atribuidos para sincronizar.',
            ]);
        }

        $application = $machines->pluck('application')->filter()->first();

        if (! $application || ! $application->is_active) {
            throw ValidationException::withMessages([
                'integration' => 'A aplicacao ZoneSoft nao esta configurada ou esta inativa.',
            ]);
        }

        return $machines;
    }

    /**
     * @param  Collection<int, ClientZoneSoftMachine>  $machines
     */
    private function createSyncLog(Event $event, Collection $machines, ?User $uploadedBy): EventReportImport
    {
        $startedAt = now();

        return $event->reportImports()->create([
            'uploaded_by_user_id' => $uploadedBy?->id,
            'import_strategy' => 'replace',
            'original_filename' => 'zonesoft-api',
            'stored_path' => 'zonesoft://sync',
            'mime_type' => 'application/json',
            'file_hash' => hash('sha256', implode('|', [$event->id, $startedAt->toISOString(), $machines->count()])),
            'headers' => [
                'source' => 'zonesoft_api',
                'machines' => $machines->map(fn (ClientZoneSoftMachine $machine): array => [
                    'id' => $machine->id,
                    'zs_client_id' => $machine->zs_client_id,
                    'store_id' => $machine->store_id,
                    'store_label' => $machine->store_label,
                ])->values()->all(),
            ],
            'summary' => [
                'source' => 'zonesoft_api',
                'machines_count' => 0,
                'machines_total' => $machines->count(),
                'machines_processed' => 0,
                'documents_processed' => 0,
                'api_requests_count' => 0,
                'stage' => 'queued',
            ],
            'imported_rows_count' => 0,
            'is_active' => false,
            'status' => 'processing',
        ]);
    }

    /**
     * Fetches the delta from every machine and accumulates it in memory —
     * PERF-101: nothing is written to event_report_rows/event_report_payment_documents
     * here. The caller (run()) writes the accumulated delta in a single short
     * transaction only once every required machine has succeeded, so a
     * partial failure never touches the durable, already-published data.
     *
     * @param  Collection<int, ClientZoneSoftMachine>  $machines
     * @return array{
     *     successful_machines_count:int,
     *     failed_machines:list<array{machine_id:int,zs_client_id:string,store_id:int,message:string}>,
     *     machine_warnings:list<array{machine_id:int,zs_client_id:string,store_id:int,message:string}>,
     *     pending_rows:list<array<string, mixed>>,
     *     pending_payment_documents:list<array<string, mixed>>,
     *     pending_reconciliations:list<array{machine_id:int,document_keys:list<array<string,string>>}>,
     *     sync_range:array{start:string,end:string},
     *     fetch_range:array{start:string,end:string},
     *     document_fetch_mode:string,
     *     machine_document_cursors:array<string, array<string, mixed>>,
     *     last_full_document_sync_at:string,
     *     metrics:array{documents_count:int,api_requests_count:int,machine_duration_ms:int,machine_timings:list<array<string, int|string|null>>}
     * }
     */
    private function fetchRows(
        Event $event,
        Collection $machines,
        EventReportImport $syncLog,
    ): array {
        $syncRange = $this->resolveSyncRange($event);
        $documentSyncStartedAt = CarbonImmutable::now(self::REPORT_TIMEZONE);
        $syncMode = $this->resolveSyncMode($event, $machines, $syncRange);
        $successfulMachinesCount = 0;
        $failedMachines = [];
        $machineWarnings = [];
        $machineDocumentCursors = [];
        $metrics = [
            'documents_count' => 0,
            'api_requests_count' => 0,
            'machine_duration_ms' => 0,
            'machine_timings' => [],
        ];
        $statusTimestamp = now();
        $machinesById = $machines->keyBy('id');
        $documentsCount = 0;
        $apiRequestsCount = 0;
        $totalRowsCount = 0;
        $totalMachines = $machines->count();
        $rangePayload = [
            'start' => $syncMode['fetch_range']['start']->toIso8601String(),
            'end' => $syncMode['fetch_range']['end']->toIso8601String(),
            'sync_import_id' => $syncLog->id,
            'machine_document_cursors' => $syncMode['machine_document_cursors'],
            'machine_sync_concurrency' => $syncMode['document_fetch_mode'] === 'incremental'
                ? (int) config('event-reports.zonesoft.incremental_machine_sync_concurrency', 4)
                : (int) config('event-reports.zonesoft.full_machine_sync_concurrency', 10),
        ];

        $machineIds = $machines->modelKeys();
        $results = $this->fetchMachineResults($machines, $rangePayload);

        /** @var list<array<string, mixed>> $pendingRows */
        $pendingRows = [];
        /** @var list<array<string, mixed>> $pendingPaymentDocuments */
        $pendingPaymentDocuments = [];
        /** @var list<array{machine_id:int,document_keys:list<array<string,string>>}> $pendingReconciliations */
        $pendingReconciliations = [];

        foreach ($machineIds as $machineId) {
            $machine = $machinesById->get($machineId);

            if (! $machine instanceof ClientZoneSoftMachine) {
                continue;
            }

            $result = $results[$machineId] ?? $this->missingMachineResult();
            unset($results[$machineId]);

            $result = $this->retryRateLimitedMachineResult(
                $machineId,
                $result,
                $rangePayload,
            );
            $resultMetrics = is_array($result['metrics'] ?? null) ? $result['metrics'] : [];
            $metrics['documents_count'] += (int) ($resultMetrics['documents_count'] ?? 0);
            $metrics['api_requests_count'] += (int) ($resultMetrics['api_requests_count'] ?? 0);
            $metrics['machine_duration_ms'] += (int) ($resultMetrics['duration_ms'] ?? 0);
            $metrics['machine_timings'][] = [
                'machine_id' => $machine->id,
                'store_id' => $machine->store_id,
                'store_label' => $machine->store_label,
                'duration_ms' => (int) ($resultMetrics['duration_ms'] ?? 0),
                'documents_count' => (int) ($resultMetrics['documents_count'] ?? 0),
                'api_requests_count' => (int) ($resultMetrics['api_requests_count'] ?? 0),
            ];
            $documentsCount += (int) ($resultMetrics['documents_count'] ?? 0);
            $apiRequestsCount += (int) ($resultMetrics['api_requests_count'] ?? 0);

            if (is_string($result['failure_message'] ?? null) && $result['failure_message'] !== '') {
                $failedMachines[] = $this->persistMachineFailure(
                    $machine,
                    $result['failure_message'],
                    $statusTimestamp,
                );
                unset($result);

                continue;
            }

            $documentKeys = is_array($result['document_keys'] ?? null)
                ? array_values(array_filter($result['document_keys'], 'is_array'))
                : [];

            if ($documentKeys !== []) {
                $pendingReconciliations[] = [
                    'machine_id' => $machine->id,
                    'document_keys' => $documentKeys,
                ];
            }

            $rowDedupe = [];

            foreach ($result['rows'] ?? [] as $normalizedRow) {
                $dedupeKey = $this->buildRowDedupeKey($machine, $normalizedRow);

                if (isset($rowDedupe[$dedupeKey])) {
                    continue;
                }

                $rowDedupe[$dedupeKey] = true;
                $normalizedRow['machine_id'] = $machine->id;
                $normalizedRow['line_key'] = $this->resolveLineKey($normalizedRow);
                $pendingRows[] = $normalizedRow;
                $totalRowsCount++;
            }

            unset($rowDedupe);

            $paymentDocumentDedupe = [];

            foreach ($result['payment_documents'] ?? [] as $paymentDocument) {
                $dedupeKey = $this->buildPaymentDocumentKey($machine, $paymentDocument);

                if (isset($paymentDocumentDedupe[$dedupeKey])) {
                    continue;
                }

                $paymentDocumentDedupe[$dedupeKey] = true;
                $pendingPaymentDocuments[] = $paymentDocument;
            }

            unset($paymentDocumentDedupe);

            $successfulMachinesCount++;
            $documentCursor = is_array($result['document_cursor'] ?? null)
                ? $result['document_cursor']
                : null;

            if ($documentCursor !== null) {
                $machineDocumentCursors[(string) $machine->id] = $documentCursor;
            }
            $warningMessage = $result['warning_message'] ?? null;
            $this->persistMachineStatus($machine, $warningMessage, $statusTimestamp);
            // PERF-101 no longer stages rows per machine, so this is the only
            // heartbeat during a (possibly long) fetch across many machines —
            // keeps markStaleProcessingImportsAsFailed() from reaping a sync
            // that is still actively making progress.
            $this->touchSyncHeartbeat($syncLog->id);

            if (is_string($warningMessage) && $warningMessage !== '') {
                $machineWarnings[] = [
                    'machine_id' => $machine->id,
                    'zs_client_id' => $machine->zs_client_id,
                    'store_id' => $machine->store_id,
                    'store_label' => $machine->store_label,
                    'message' => $warningMessage,
                ];
            }

            unset($result);
        }

        $this->updateSyncProgress($syncLog->id, [
            'stage' => 'fetching',
            'machines_total' => $totalMachines,
            'machines_processed' => count($machineIds),
            'documents_processed' => $documentsCount,
            'api_requests_count' => $apiRequestsCount,
            'rows_count' => $totalRowsCount,
        ]);
        gc_collect_cycles();

        return [
            'successful_machines_count' => $successfulMachinesCount,
            'failed_machines' => $failedMachines,
            'machine_warnings' => $machineWarnings,
            'pending_rows' => $pendingRows,
            'pending_payment_documents' => $pendingPaymentDocuments,
            'pending_reconciliations' => $pendingReconciliations,
            'sync_range' => [
                'start' => $syncRange['start']->toIso8601String(),
                'end' => $syncRange['end']->toIso8601String(),
            ],
            'fetch_range' => [
                'start' => $syncMode['fetch_range']['start']->toIso8601String(),
                'end' => $syncMode['fetch_range']['end']->toIso8601String(),
            ],
            'document_fetch_mode' => $syncMode['document_fetch_mode'],
            'machine_document_cursors' => $machineDocumentCursors,
            'last_full_document_sync_at' => $syncMode['document_fetch_mode'] === 'full'
                ? $documentSyncStartedAt->toIso8601String()
                : $syncMode['last_full_document_sync_at'],
            'metrics' => $metrics,
        ];
    }

    /**
     * Decides incremental vs full and resolves per-machine cursors — PERF-101
     * removed the part of this decision that used to copy the previous
     * snapshot's rows/payment documents into staging (resolveReusableHistoricalData()).
     * Nothing needs to be copied anymore: unchanged rows already sit in
     * event_report_rows and are simply left alone by this cycle.
     *
     * @param  Collection<int, ClientZoneSoftMachine>  $machines
     * @param  array{start:CarbonImmutable,end:CarbonImmutable}  $syncRange
     * @return array{
     *     fetch_range:array{start:CarbonImmutable,end:CarbonImmutable},
     *     document_fetch_mode:string,
     *     machine_document_cursors:array<string, array<string, mixed>>,
     *     last_full_document_sync_at:string|null
     * }
     */
    private function resolveSyncMode(
        Event $event,
        Collection $machines,
        array $syncRange,
    ): array {
        $fullMode = [
            'fetch_range' => $syncRange,
            'document_fetch_mode' => 'full',
            'machine_document_cursors' => [],
            'last_full_document_sync_at' => null,
        ];

        if (! (bool) config('event-reports.zonesoft.complete_documents', true)) {
            return $fullMode;
        }

        $activeImport = $event->reportImports()
            ->where('is_active', true)
            ->where('status', 'completed')
            ->latest('imported_at')
            ->first();

        if (! $activeImport) {
            return $fullMode;
        }

        $summary = is_array($activeImport->summary) ? $activeImport->summary : [];

        if (($summary['failed_machines'] ?? []) !== [] || ($summary['machine_warnings'] ?? []) !== []) {
            return $fullMode;
        }

        if (($summary['historical_data_complete'] ?? false) !== true) {
            return $fullMode;
        }

        if ((int) ($summary['document_cursor_version'] ?? 0) !== 1) {
            return $fullMode;
        }

        $lastFullSyncValue = $summary['last_full_document_sync_at'] ?? null;

        try {
            $lastFullSync = is_string($lastFullSyncValue) && trim($lastFullSyncValue) !== ''
                ? CarbonImmutable::parse($lastFullSyncValue)
                : null;
        } catch (\Throwable) {
            $lastFullSync = null;
        }

        $fullRefreshHours = max(
            1,
            min(168, (int) config('event-reports.zonesoft.incremental_full_refresh_hours', 24)),
        );

        if ($lastFullSync === null || $lastFullSync->addHours($fullRefreshHours)->lte(now())) {
            return $fullMode;
        }

        $currentMachineIds = $machines->modelKeys();
        sort($currentMachineIds);
        $snapshotMachineIds = collect($activeImport->headers['machines'] ?? [])
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($currentMachineIds !== $snapshotMachineIds) {
            return $fullMode;
        }

        $machineDocumentCursors = $this->resolveMachineDocumentCursors($summary, $machines);

        if ($machineDocumentCursors === null) {
            return $fullMode;
        }

        return [
            'fetch_range' => $syncRange,
            'document_fetch_mode' => 'incremental',
            'machine_document_cursors' => $machineDocumentCursors,
            'last_full_document_sync_at' => $lastFullSync->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  Collection<int, ClientZoneSoftMachine>  $machines
     * @return array<string, array<string, mixed>>|null
     */
    private function resolveMachineDocumentCursors(array $summary, Collection $machines): ?array
    {
        $storedCursors = $summary['machine_document_cursors'] ?? null;

        if (! is_array($storedCursors)) {
            return null;
        }

        $resolved = [];

        foreach ($machines as $machine) {
            $cursor = $storedCursors[(string) $machine->id] ?? null;
            $cursorValue = is_array($cursor) ? ($cursor['cursor'] ?? null) : null;

            if (! is_string($cursorValue) || trim($cursorValue) === '') {
                return null;
            }

            try {
                $parsedCursor = CarbonImmutable::parse($cursorValue);
            } catch (\Throwable) {
                return null;
            }

            if (isset($cursor['zs_client_id']) && $cursor['zs_client_id'] !== $machine->zs_client_id) {
                return null;
            }

            if (isset($cursor['store_id']) && (int) $cursor['store_id'] !== (int) $machine->store_id) {
                return null;
            }

            $resolved[(string) $machine->id] = [
                'machine_id' => $machine->id,
                'zs_client_id' => $machine->zs_client_id,
                'store_id' => $machine->store_id,
                'cursor' => $parsedCursor->toIso8601String(),
            ];
        }

        return $resolved;
    }

    private function machineSyncConcurrency(?int $configuredConcurrency = null): int
    {
        return max(1, min(
            20,
            $configuredConcurrency
                ?? (int) config('event-reports.zonesoft.machine_sync_concurrency', 4),
        ));
    }

    /**
     * PERF-201: replaces one-OS-process-per-machine dispatch entirely. Phase
     * A paginates documents/getInstances (or getDocumentsHeaders) for every
     * machine concurrently, in this same process, via Http::pool() — no
     * Laravel boot per machine. Phase B turns each machine's fetched
     * documents into the same result shape syncMachinePayload() already
     * produces, reusing its business logic unchanged
     * (buildMachineResultFromDocuments()).
     *
     * @param  Collection<int, ClientZoneSoftMachine>  $machines
     * @param  array{start:string,end:string,sync_import_id:int,machine_document_cursors:array<string, array<string, mixed>>,machine_sync_concurrency?:int}  $rangePayload
     * @return array<int, array<string, mixed>>
     */
    private function fetchMachineResults(Collection $machines, array $rangePayload): array
    {
        $syncRange = [
            'start' => CarbonImmutable::parse($rangePayload['start']),
            'end' => CarbonImmutable::parse($rangePayload['end']),
        ];
        $usesCompleteDocuments = (bool) config('event-reports.zonesoft.complete_documents', true);
        $concurrency = $this->machineSyncConcurrency(
            isset($rangePayload['machine_sync_concurrency'])
                ? (int) $rangePayload['machine_sync_concurrency']
                : null,
        );
        $requestCursor = CarbonImmutable::now(self::REPORT_TIMEZONE);
        $startedAt = microtime(true);

        $lastUpdatedAfterByMachine = [];

        foreach ($machines as $machine) {
            $lastUpdatedAfterByMachine[$machine->id] = $this->resolveMachineLastUpdatedAfter(
                $machine->id,
                $rangePayload,
            );
        }

        $fetches = $this->fetchDocumentsAcrossMachines(
            $machines,
            $syncRange,
            $usesCompleteDocuments,
            $lastUpdatedAfterByMachine,
            $concurrency,
        );

        $results = [];

        foreach ($machines as $machine) {
            if (! $machine->application) {
                $results[$machine->id] = [
                    'failure_message' => 'A aplicacao ZoneSoft desta maquina nao esta disponivel.',
                    'warning_message' => null,
                    'rows' => [],
                    'payment_documents' => [],
                    'document_keys' => [],
                    'document_cursor' => null,
                    'should_retry_serially' => false,
                    'metrics' => $this->machineMetrics($startedAt),
                ];

                continue;
            }

            $fetch = $fetches[$machine->id] ?? ['documents' => [], 'request_count' => 0, 'error' => null];

            if ($fetch['error'] instanceof ZoneSoftApiException) {
                $exception = $fetch['error'];
                $results[$machine->id] = [
                    'failure_message' => $exception->getMessage(),
                    'warning_message' => null,
                    'rows' => [],
                    'payment_documents' => [],
                    'document_keys' => [],
                    'document_cursor' => null,
                    'should_retry_serially' => $exception->isRateLimited(),
                    'metrics' => $this->machineMetrics($startedAt, 0, max(1, $fetch['request_count'])),
                ];

                continue;
            }

            $results[$machine->id] = $this->buildMachineResultFromDocuments(
                $machine,
                $fetch['documents'],
                $fetch['request_count'],
                $syncRange,
                $usesCompleteDocuments,
                $requestCursor,
                $lastUpdatedAfterByMachine[$machine->id] ?? null,
                $startedAt,
            );
        }

        return $results;
    }

    /**
     * Paginates documents/getInstances (or getDocumentsHeaders) for every
     * still-pending machine concurrently, in rounds: each round pools one
     * "next page" request per machine that still has more pages (bounded by
     * $concurrency via Http::pool()'s own in-flight window — see
     * PendingRequest::pool()), then advances each machine's offset from the
     * round's results. A machine drops out of the round once it gets back a
     * short page (< 250, same page size fetchDocuments() already used) or
     * an error. Mirrors fetchDocuments()'s single-machine pagination
     * exactly, just fanned out across machines instead of across processes.
     *
     * @param  Collection<int, ClientZoneSoftMachine>  $machines
     * @param  array{start:CarbonImmutable,end:CarbonImmutable}  $syncRange
     * @param  array<int, CarbonImmutable|null>  $lastUpdatedAfterByMachine
     * @return array<int, array{documents:list<array<string, mixed>>,request_count:int,error:?ZoneSoftApiException}>
     */
    private function fetchDocumentsAcrossMachines(
        Collection $machines,
        array $syncRange,
        bool $completeDocuments,
        array $lastUpdatedAfterByMachine,
        int $concurrency,
    ): array {
        $limit = 250;
        $states = [];

        foreach ($machines as $machine) {
            if (! $machine->application) {
                continue;
            }

            $states[$machine->id] = [
                'machine' => $machine,
                'offset' => 0,
                'documents' => [],
                'request_count' => 0,
                'done' => false,
                'error' => null,
            ];
        }

        while (true) {
            $pendingMachineIds = array_keys(array_filter(
                $states,
                fn (array $state): bool => ! $state['done'] && $state['error'] === null,
            ));

            if ($pendingMachineIds === []) {
                break;
            }

            $requests = [];

            foreach ($pendingMachineIds as $machineId) {
                $machine = $states[$machineId]['machine'];
                $lastUpdatedAfter = $lastUpdatedAfterByMachine[$machineId] ?? null;

                $requests[$machineId] = [
                    'application' => $machine->application,
                    'zsClientId' => $machine->zs_client_id,
                    'interface' => 'documents',
                    'action' => $completeDocuments ? 'getInstances' : 'getDocumentsHeaders',
                    'entityName' => 'document',
                    'payload' => [
                        'condition' => $this->buildDocumentCondition($machine, $syncRange, $lastUpdatedAfter),
                        'order' => 'data ASC, numero ASC',
                        'limit' => $limit,
                        'offset' => $states[$machineId]['offset'],
                    ],
                    'requestTimeoutSeconds' => $lastUpdatedAfter
                        ? (int) config('event-reports.zonesoft.incremental_request_timeout_seconds', 10)
                        : (int) config('event-reports.zonesoft.full_request_timeout_seconds', 30),
                    // fetchDocuments() never retried a 401 on this endpoint
                    // (unlike postMany()'s sales-fetch fallback) — preserve
                    // that: an invalid/expired client-id should fail fast,
                    // not spend ~1.5s retrying, especially at 200-machine
                    // scale where several machines can share that state.
                    'retryUnauthorized' => false,
                ];
            }

            $responses = $this->apiClient->postManyAcrossRequests($requests, $concurrency);

            foreach ($pendingMachineIds as $machineId) {
                $states[$machineId]['request_count']++;
                $response = $responses[$machineId] ?? null;

                if ($response instanceof ZoneSoftApiException) {
                    $states[$machineId]['error'] = $response;

                    continue;
                }

                $batch = is_array($response['document'] ?? null)
                    ? array_values(array_filter($response['document'], 'is_array'))
                    : [];

                $states[$machineId]['documents'] = [...$states[$machineId]['documents'], ...$batch];
                $states[$machineId]['offset'] += count($batch);

                if (count($batch) < $limit) {
                    $states[$machineId]['done'] = true;
                }
            }
        }

        $resolved = [];

        foreach ($states as $machineId => $state) {
            $resolved[$machineId] = [
                'documents' => $state['documents'],
                'request_count' => $state['request_count'],
                'error' => $state['error'],
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array{start:string,end:string,sync_import_id?:int,machine_document_cursors?:array<string, array<string, mixed>>}  $rangePayload
     * @return array<string, mixed>
     */
    private function retryRateLimitedMachineResult(
        int $machineId,
        array $result,
        array $rangePayload,
    ): array {
        for ($round = 0; $round < self::RATE_LIMIT_SERIAL_RETRY_ROUNDS; $round++) {
            if (($result['should_retry_serially'] ?? false) !== true) {
                break;
            }

            $this->pauseBeforeRateLimitRetryRound(self::RATE_LIMIT_SERIAL_RETRY_ROUND_PAUSE_MICROSECONDS);
            $result = $this->syncMachinePayload($machineId, $rangePayload);
            $this->touchSyncHeartbeat((int) ($rangePayload['sync_import_id'] ?? 0));
        }

        return $result;
    }

    private function pauseBeforeRateLimitRetryRound(int $microseconds): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        usleep($microseconds);
    }

    /**
     * @return array<string, mixed>
     */
    private function missingMachineResult(): array
    {
        return [
            'failure_message' => 'Nao foi possivel concluir a sincronizacao desta maquina.',
            'warning_message' => null,
            'rows' => [],
            'payment_documents' => [],
            'document_keys' => [],
            'document_cursor' => null,
            'should_retry_serially' => false,
            'metrics' => [],
        ];
    }

    /**
     * @param  array{start:string,end:string,sync_import_id?:int}  $rangePayload
     * @return array{
     *     failure_message:string|null,
     *     warning_message:string|null,
     *     rows:list<array<string, mixed>>,
     *     payment_documents:list<array<string, mixed>>,
     *     document_keys:list<array<string, string>>,
     *     document_cursor:array<string, mixed>|null,
     *     should_retry_serially:bool,
     *     metrics:array<string, int>
     * }
     */
    public function syncMachinePayload(int $machineId, array $rangePayload): array
    {
        $startedAt = microtime(true);
        $machine = ClientZoneSoftMachine::query()
            ->with('application')
            ->find($machineId);

        if (! $machine) {
            return [
                'failure_message' => 'A maquina configurada para esta sincronizacao ja nao existe.',
                'warning_message' => null,
                'rows' => [],
                'payment_documents' => [],
                'document_keys' => [],
                'document_cursor' => null,
                'should_retry_serially' => false,
                'metrics' => $this->machineMetrics($startedAt),
            ];
        }

        if (! $machine->application) {
            return [
                'failure_message' => 'A aplicacao ZoneSoft desta maquina nao esta disponivel.',
                'warning_message' => null,
                'rows' => [],
                'payment_documents' => [],
                'document_keys' => [],
                'document_cursor' => null,
                'should_retry_serially' => false,
                'metrics' => $this->machineMetrics($startedAt),
            ];
        }

        $syncRange = [
            'start' => CarbonImmutable::parse($rangePayload['start']),
            'end' => CarbonImmutable::parse($rangePayload['end']),
        ];
        $requestCursor = CarbonImmutable::now(self::REPORT_TIMEZONE);
        $lastUpdatedAfter = $this->resolveMachineLastUpdatedAfter($machineId, $rangePayload);
        $usesCompleteDocuments = (bool) config('event-reports.zonesoft.complete_documents', true);
        $documentRequestCount = 0;

        try {
            $documentFetch = $this->fetchDocuments(
                $machine,
                $syncRange,
                $usesCompleteDocuments,
                $lastUpdatedAfter,
            );
        } catch (ZoneSoftApiException $exception) {
            return [
                'failure_message' => $exception->getMessage(),
                'warning_message' => null,
                'rows' => [],
                'payment_documents' => [],
                'document_keys' => [],
                'document_cursor' => null,
                'should_retry_serially' => $exception->isRateLimited(),
                'metrics' => $this->machineMetrics($startedAt, 0, max(1, $documentRequestCount)),
            ];
        }

        return $this->buildMachineResultFromDocuments(
            $machine,
            $documentFetch['documents'],
            $documentFetch['request_count'],
            $syncRange,
            $usesCompleteDocuments,
            $requestCursor,
            $lastUpdatedAfter,
            $startedAt,
        );
    }

    /**
     * Turns a machine's already-fetched, unfiltered document list into the
     * standard per-machine result shape — vendas embedded vs.
     * fetchSalesForDocuments(), row/payment normalization, per-document
     * warnings, should_retry_serially, document_cursor. Shared by
     * syncMachinePayload() (single machine, blocking — used for the serial
     * rate-limit retry) and fetchMachineResults() (PERF-201's pooled,
     * multi-machine path) so this business logic exists exactly once.
     *
     * @param  list<array<string, mixed>>  $documents  raw, not yet filtered for cancelled documents
     * @param  array{start:CarbonImmutable,end:CarbonImmutable}  $syncRange
     * @return array<string, mixed>
     */
    private function buildMachineResultFromDocuments(
        ClientZoneSoftMachine $machine,
        array $documents,
        int $documentRequestCount,
        array $syncRange,
        bool $usesCompleteDocuments,
        CarbonImmutable $requestCursor,
        ?CarbonImmutable $lastUpdatedAfter,
        float $startedAt,
    ): array {
        $documentKeys = $this->buildDocumentIdentities($machine, $documents);
        $documents = array_values(array_filter(
            $documents,
            fn (array $document): bool => ! $this->isCancelledDocument($document),
        ));

        $rows = [];
        $documentWarnings = [];
        $paymentDocuments = [];
        $shouldRetrySerially = false;
        $salesResults = [];

        if ($usesCompleteDocuments) {
            foreach ($documents as $documentIndex => $document) {
                if (! array_key_exists('vendas', $document) || ! is_array($document['vendas'])) {
                    $salesResults[$documentIndex] = new ZoneSoftApiException(
                        'A ZoneSoft devolveu um documento completo sem a lista de vendas.',
                    );

                    continue;
                }

                $salesResults[$documentIndex] = ['sale' => $document['vendas']];
            }
        } else {
            foreach (array_chunk($documents, self::DOCUMENT_REQUEST_BATCH_SIZE, true) as $documentBatch) {
                $salesResults += $this->fetchSalesForDocuments($machine, $documentBatch);
                $documentRequestCount += count($documentBatch);
            }
        }

        foreach ($documents as $documentIndex => $document) {
            $salesResult = $salesResults[$documentIndex] ?? [];

            if ($salesResult instanceof ZoneSoftApiException) {
                $exception = $salesResult;
                $shouldRetrySerially = $shouldRetrySerially || $exception->isRateLimited();
                $documentWarnings[] = $this->buildDocumentWarningMessage(
                    $document,
                    $exception->getMessage(),
                );

                continue;
            }

            $sales = is_array($salesResult['sale'] ?? null)
                ? array_values(array_filter($salesResult['sale'], 'is_array'))
                : [];
            $documentRows = [];

            foreach ($sales as $saleIndex => $sale) {
                $normalizedRow = $this->normalizeSaleRow($machine, $sale, $saleIndex + 1);

                if (! $this->rowMatchesSyncRange($normalizedRow, $syncRange)) {
                    continue;
                }

                $rows[] = $normalizedRow;
                $documentRows[] = $normalizedRow;
            }

            if ($documentRows !== []) {
                $paymentDocuments = [
                    ...$paymentDocuments,
                    ...$this->normalizePaymentDocuments(
                        $machine,
                        $document,
                        $documentRows[0],
                    ),
                ];
            }
        }

        return [
            'failure_message' => null,
            'warning_message' => $this->summarizeMachineWarnings($documentWarnings),
            'rows' => $rows,
            'payment_documents' => $paymentDocuments,
            'document_keys' => $documentKeys,
            'document_cursor' => [
                'machine_id' => $machine->id,
                'zs_client_id' => $machine->zs_client_id,
                'store_id' => $machine->store_id,
                'cursor' => $requestCursor->toIso8601String(),
                'requested_after' => $lastUpdatedAfter?->toIso8601String(),
            ],
            'should_retry_serially' => $shouldRetrySerially,
            'metrics' => $this->machineMetrics(
                $startedAt,
                count($documents),
                $documentRequestCount,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $rangePayload
     */
    private function resolveMachineLastUpdatedAfter(
        int $machineId,
        array $rangePayload,
    ): ?CarbonImmutable {
        $machineCursors = $rangePayload['machine_document_cursors'] ?? null;
        $machineCursor = is_array($machineCursors)
            ? ($machineCursors[(string) $machineId] ?? null)
            : null;
        $cursorValue = is_array($machineCursor) ? ($machineCursor['cursor'] ?? null) : null;

        if (! is_string($cursorValue) || trim($cursorValue) === '') {
            return null;
        }

        try {
            $cursor = CarbonImmutable::parse($cursorValue)->setTimezone(self::REPORT_TIMEZONE);
        } catch (\Throwable) {
            return null;
        }

        $overlapMinutes = max(
            1,
            min(120, (int) config('event-reports.zonesoft.incremental_overlap_minutes', 15)),
        );

        return $cursor->subMinutes($overlapMinutes);
    }

    /**
     * @param  array{start:CarbonImmutable,end:CarbonImmutable}  $syncRange
     * @return array{documents:list<array<string, mixed>>,request_count:int}
     */
    private function fetchDocuments(
        ClientZoneSoftMachine $machine,
        array $syncRange,
        bool $completeDocuments,
        ?CarbonImmutable $lastUpdatedAfter = null,
    ): array {
        $documents = [];
        $offset = 0;
        $limit = 250;
        $requestCount = 0;
        $requestTimeoutSeconds = $lastUpdatedAfter
            ? (int) config('event-reports.zonesoft.incremental_request_timeout_seconds', 10)
            : (int) config('event-reports.zonesoft.full_request_timeout_seconds', 30);
        $requestRetryAttempts = $lastUpdatedAfter
            ? (int) config('event-reports.zonesoft.incremental_request_retry_attempts', 1)
            : (int) config('event-reports.zonesoft.full_request_retry_attempts', 3);

        do {
            $requestCount++;
            $response = $this->apiClient->post(
                $machine->application,
                $machine->zs_client_id,
                'documents',
                $completeDocuments ? 'getInstances' : 'getDocumentsHeaders',
                'document',
                [
                    'condition' => $this->buildDocumentCondition(
                        $machine,
                        $syncRange,
                        $lastUpdatedAfter,
                    ),
                    'order' => 'data ASC, numero ASC',
                    'limit' => $limit,
                    'offset' => $offset,
                ],
                false,
                $requestTimeoutSeconds,
                $requestRetryAttempts,
            );

            /** @var list<array<string, mixed>> $batch */
            $batch = is_array($response['document'] ?? null)
                ? array_values(array_filter($response['document'], 'is_array'))
                : [];

            $documents = [...$documents, ...$batch];
            $offset += count($batch);
        } while (count($batch) === $limit);

        return [
            'documents' => $documents,
            'request_count' => $requestCount,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return list<array{store_code:string,doc_type:string,document_series:string,document_number:string}>
     */
    private function buildDocumentIdentities(
        ClientZoneSoftMachine $machine,
        array $documents,
    ): array {
        $identities = [];

        foreach ($documents as $document) {
            $identity = [
                'store_code' => (string) ($document['loja'] ?? $machine->store_id),
                'doc_type' => trim((string) ($document['doc'] ?? '')),
                'document_series' => trim((string) ($document['serie'] ?? '')),
                'document_number' => trim((string) ($document['numero'] ?? '')),
            ];

            if ($identity['doc_type'] === '' || $identity['document_number'] === '') {
                continue;
            }

            $key = implode('|', $identity);
            $identities[$key] = $identity;
        }

        return array_values($identities);
    }

    /**
     * @return array{documents_count:int,api_requests_count:int,duration_ms:int}
     */
    private function machineMetrics(
        float $startedAt,
        int $documentsCount = 0,
        int $apiRequestsCount = 0,
    ): array {
        return [
            'documents_count' => $documentsCount,
            'api_requests_count' => $apiRequestsCount,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     * @return array<int, array<string, mixed>|ZoneSoftApiException>
     */
    private function fetchSalesForDocuments(ClientZoneSoftMachine $machine, array $documents): array
    {
        $payloads = array_map(
            static fn (array $document): array => [
                'doc' => (string) ($document['doc'] ?? ''),
                'serie' => (string) ($document['serie'] ?? ''),
                'numero' => (int) ($document['numero'] ?? 0),
            ],
            $documents,
        );

        return $this->apiClient->postMany(
            $machine->application,
            $machine->zs_client_id,
            'sales',
            'getInstancesFromDocument',
            'sale',
            $payloads,
            self::DOCUMENT_SYNC_CONCURRENCY,
        );
    }

    /**
     * @param  array{start:CarbonImmutable,end:CarbonImmutable}  $syncRange
     */
    private function buildDocumentCondition(
        ClientZoneSoftMachine $machine,
        array $syncRange,
        ?CarbonImmutable $lastUpdatedAfter = null,
    ): string {
        $conditions = [
            sprintf('loja = %d', $machine->store_id),
            sprintf("data >= '%s'", $syncRange['start']->toDateString()),
            sprintf("data <= '%s'", $syncRange['end']->toDateString()),
        ];

        if ($lastUpdatedAfter !== null) {
            $conditions[] = sprintf(
                "lastupdate >= '%s'",
                $lastUpdatedAfter->setTimezone(self::REPORT_TIMEZONE)->format('Y-m-d H:i:s'),
            );
        }

        return implode(' and ', $conditions);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function buildDocumentWarningMessage(array $document, string $message): string
    {
        $parts = array_values(array_filter([
            trim((string) ($document['doc'] ?? '')),
            trim((string) ($document['serie'] ?? '')),
            isset($document['numero']) ? trim((string) $document['numero']) : '',
        ]));

        $documentReference = $parts === []
            ? 'documento sem identificacao'
            : implode(' / ', $parts);

        return sprintf('%s: %s', $documentReference, $message);
    }

    /**
     * @param  list<string>  $documentWarnings
     */
    private function summarizeMachineWarnings(array $documentWarnings): ?string
    {
        if ($documentWarnings === []) {
            return null;
        }

        return sprintf(
            'Falha parcial em %d documento(s). Primeiro erro: %s',
            count($documentWarnings),
            $documentWarnings[0],
        );
    }

    /**
     * @param  array<string, mixed>  $sale
     * @return array<string, mixed>
     */
    private function normalizeSaleRow(
        ClientZoneSoftMachine $machine,
        array $sale,
        int $documentLineNumber,
    ): array {
        $storeCode = isset($sale['loja']) ? (string) $sale['loja'] : (string) $machine->store_id;
        $storeName = $machine->store_label ?: 'Loja '.$storeCode;

        if (! empty($sale['posto'])) {
            $storeName .= ' - POS '.$sale['posto'];
        }

        return [
            'source_sheet' => 'zonesoft:'.$machine->zs_client_id,
            'source_row_number' => $documentLineNumber,
            'store_code' => $storeCode,
            'store_name' => $storeName,
            'sale_date' => $this->normalizeDate($sale['data'] ?? null),
            'sale_datetime' => $this->normalizeDateTime($sale['datahora'] ?? null),
            'doc_type' => isset($sale['doc']) ? (string) $sale['doc'] : null,
            'document_series' => isset($sale['serie']) ? (string) $sale['serie'] : null,
            'document_number' => isset($sale['numero']) ? (string) $sale['numero'] : null,
            'value' => $this->normalizeDecimal($sale['valor'] ?? null),
            'total' => $this->normalizeDecimal($sale['total'] ?? null),
            'discount' => $this->normalizeDecimal(
                ($sale['desconto'] ?? 0) + ($sale['desconto2'] ?? 0),
            ),
            'quantity' => $this->normalizeDecimal($sale['qtd'] ?? null),
            'product_code' => isset($sale['codigo']) ? (string) $sale['codigo'] : null,
            'description' => isset($sale['descricao']) ? (string) $sale['descricao'] : null,
            'raw_row' => [
                'machine_id' => $machine->id,
                'machine_client_id' => $machine->zs_client_id,
                'machine_store_id' => $machine->store_id,
                'id' => $sale['id'] ?? null,
                '_document_line_number' => $documentLineNumber,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $referenceRow
     * @return list<array<string, mixed>>
     */
    private function normalizePaymentDocuments(
        ClientZoneSoftMachine $machine,
        array $document,
        array $referenceRow,
    ): array {
        $baseDocument = [
            'machine_id' => $machine->id,
            'machine_client_id' => $machine->zs_client_id,
            'store_code' => (string) ($referenceRow['store_code'] ?? $machine->store_id),
            'store_name' => (string) ($referenceRow['store_name'] ?? ($machine->store_label ?: 'Loja '.$machine->store_id)),
            'sale_date' => $this->normalizeDate($document['data'] ?? ($referenceRow['sale_date'] ?? null)),
            'sale_datetime' => $this->normalizeDateTime($document['datahora'] ?? ($referenceRow['sale_datetime'] ?? null)),
            'doc_type' => isset($document['doc']) ? (string) $document['doc'] : ($referenceRow['doc_type'] ?? null),
            'document_series' => isset($document['serie']) ? (string) $document['serie'] : ($referenceRow['document_series'] ?? null),
            'document_number' => isset($document['numero']) ? (string) $document['numero'] : ($referenceRow['document_number'] ?? null),
            'payment_reference' => isset($document['referencia_pagamento']) ? (string) $document['referencia_pagamento'] : null,
            'paid' => isset($document['pago']) ? (int) $document['pago'] === 1 : null,
            'document_total' => $this->normalizeDecimal($document['total'] ?? null),
        ];

        $partialPayments = is_array($document['documentos_pagamento'] ?? null)
            ? array_values(array_filter($document['documentos_pagamento'], 'is_array'))
            : [];

        if ($partialPayments === []) {
            return [[
                ...$baseDocument,
                'payment_key' => 'header',
                'payment_code' => isset($document['pagamento']) ? (string) $document['pagamento'] : null,
                'total' => $this->normalizeDecimal($document['total'] ?? null),
            ]];
        }

        $documentTotal = (float) ($baseDocument['document_total'] ?? 0);
        $normalizedPayments = array_map(function (array $payment, int $index) use ($baseDocument, $document, $documentTotal): array {
            $paymentCode = isset($payment['tipo']) && (int) $payment['tipo'] !== 0
                ? (string) $payment['tipo']
                : (isset($document['pagamento']) ? (string) $document['pagamento'] : null);
            $paymentTotal = $this->normalizeDecimal($payment['valor'] ?? ($document['total'] ?? null));

            if ($documentTotal < 0 && $paymentTotal !== null && (float) $paymentTotal > 0) {
                $paymentTotal = $this->normalizeDecimal(-((float) $paymentTotal));
            }

            return [
                ...$baseDocument,
                'payment_key' => implode(':', [
                    $payment['doc'] ?? '',
                    $payment['serie'] ?? '',
                    $payment['numero'] ?? '',
                    $index,
                ]),
                'payment_code' => $paymentCode,
                'payment_document_type' => isset($payment['doc']) ? (string) $payment['doc'] : null,
                'payment_document_series' => isset($payment['serie']) ? (string) $payment['serie'] : null,
                'payment_document_number' => isset($payment['numero']) ? (string) $payment['numero'] : null,
                'payment_card_number' => isset($payment['cartao']) ? (string) $payment['cartao'] : null,
                'total' => $paymentTotal,
            ];
        }, $partialPayments, array_keys($partialPayments));

        $paymentsTotal = array_sum(array_map(
            fn (array $payment): float => (float) ($payment['total'] ?? 0),
            $normalizedPayments,
        ));
        $unallocatedTotal = round($documentTotal - $paymentsTotal, 4);

        if ($unallocatedTotal > 0.0001) {
            $normalizedPayments[] = [
                ...$baseDocument,
                'payment_key' => 'unallocated',
                'payment_code' => null,
                'payment_document_type' => null,
                'payment_document_series' => null,
                'payment_document_number' => null,
                'payment_card_number' => null,
                'total' => $this->normalizeDecimal($unallocatedTotal),
                'is_unallocated' => true,
            ];
        }

        return $normalizedPayments;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function isCancelledDocument(array $document): bool
    {
        return (int) ($document['anulado'] ?? 0) === 1
            || (int) ($document['empanulado'] ?? 0) > 0;
    }

    /**
     * Diagnostic-only summary built purely from what was fetched this cycle,
     * before anything is written. Used only as the `summary` recorded on a
     * sync that fails before publication — never on a completed one, so
     * "rows_count" here means "rows this attempt fetched", not the event's
     * total (that distinction only matters for a failed import's admin
     * display, which was already best-effort before PERF-101).
     *
     * @param  array{pending_rows:list<array<string,mixed>>,pending_payment_documents:list<array<string,mixed>>,sync_range:array{start:string,end:string},fetch_range:array{start:string,end:string},document_fetch_mode:string,metrics:array{documents_count:int,api_requests_count:int,machine_duration_ms:int,machine_timings:list<array<string, int|string|null>>}}  $machineSync
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,store_label:?string,message:string}>  $failedMachines
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,store_label:?string,message:string}>  $machineWarnings
     * @return array<string, mixed>
     */
    private function buildPreliminarySummary(
        array $machineSync,
        int $successfulMachinesCount,
        array $failedMachines,
        array $machineWarnings,
    ): array {
        $rowsCount = count($machineSync['pending_rows']);
        $metrics = $machineSync['metrics'];
        $machineTimings = $metrics['machine_timings'];
        usort(
            $machineTimings,
            fn (array $left, array $right): int => ((int) ($right['duration_ms'] ?? 0))
                <=> ((int) ($left['duration_ms'] ?? 0)),
        );

        return [
            'source' => 'zonesoft_api',
            'machines_count' => $successfulMachinesCount,
            'rows_count' => $rowsCount,
            'fetched_rows_count' => $rowsCount,
            'payment_documents_count' => count($machineSync['pending_payment_documents']),
            'failed_machines' => $failedMachines,
            'machine_warnings' => $machineWarnings,
            'stage' => 'failed',
            'sync_range' => $machineSync['sync_range'],
            'fetch_range' => $machineSync['fetch_range'],
            'document_fetch_mode' => $machineSync['document_fetch_mode'],
            'machines_total' => $successfulMachinesCount + count($failedMachines),
            'machines_processed' => $successfulMachinesCount + count($failedMachines),
            'documents_processed' => $metrics['documents_count'],
            'api_requests_count' => $metrics['api_requests_count'],
            'performance' => [
                'machine_duration_ms' => $metrics['machine_duration_ms'],
                'slowest_machines' => array_slice($machineTimings, 0, 10),
            ],
        ];
    }

    /**
     * The real summary, built AFTER the delta has been upserted/reconciled
     * into event_report_rows/event_report_payment_documents — so "rows_count"
     * here is a fast aggregate over the event's actual current state
     * (COUNT/SUM over event_id), never a copy of it. Called only from inside
     * the finalization transaction in run().
     *
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,store_label:?string,message:string}>  $failedMachines
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,store_label:?string,message:string}>  $machineWarnings
     * @param  array{documents_count:int,api_requests_count:int,machine_duration_ms:int,machine_timings:list<array<string, int|string|null>>}  $metrics
     * @return array<string, mixed>
     */
    private function buildSummaryFromCurrentState(
        int $eventId,
        int $successfulMachinesCount,
        array $failedMachines,
        array $machineWarnings,
        int $fetchedRowsCount,
        array $metrics,
    ): array {
        $totals = EventReportRow::query()
            ->where('event_id', $eventId)
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COALESCE(SUM(value), 0) as value_total')
            ->selectRaw('COALESCE(SUM(total), 0) as sales_total')
            ->selectRaw('COALESCE(SUM(discount), 0) as discount_total')
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity_total')
            ->selectRaw("COUNT(DISTINCT CASE WHEN store_name IS NOT NULL AND store_name != '' THEN store_name END) as unique_stores")
            ->selectRaw("COUNT(DISTINCT CASE WHEN product_code IS NOT NULL AND product_code != '' THEN product_code END) as unique_products")
            ->first();
        $rowsCount = (int) ($totals?->rows_count ?? 0);
        $paymentDocumentsCount = EventReportPaymentDocument::query()
            ->where('event_id', $eventId)
            ->count();
        $machineTimings = $metrics['machine_timings'];
        usort(
            $machineTimings,
            fn (array $left, array $right): int => ((int) ($right['duration_ms'] ?? 0))
                <=> ((int) ($left['duration_ms'] ?? 0)),
        );

        return [
            'source' => 'zonesoft_api',
            'machines_count' => $successfulMachinesCount,
            'rows_count' => $rowsCount,
            'reused_rows_count' => max(0, $rowsCount - $fetchedRowsCount),
            'fetched_rows_count' => $fetchedRowsCount,
            'unique_stores' => (int) ($totals?->unique_stores ?? 0),
            'unique_products' => (int) ($totals?->unique_products ?? 0),
            'value_total' => number_format((float) ($totals?->value_total ?? 0), 4, '.', ''),
            'sales_total' => number_format((float) ($totals?->sales_total ?? 0), 4, '.', ''),
            'discount_total' => number_format((float) ($totals?->discount_total ?? 0), 4, '.', ''),
            'quantity_total' => number_format((float) ($totals?->quantity_total ?? 0), 4, '.', ''),
            'failed_machines' => $failedMachines,
            'machine_warnings' => $machineWarnings,
            'payment_documents_count' => $paymentDocumentsCount,
            'stage' => 'completed',
            'machines_total' => $successfulMachinesCount + count($failedMachines),
            'machines_processed' => $successfulMachinesCount + count($failedMachines),
            'documents_processed' => $metrics['documents_count'],
            'api_requests_count' => $metrics['api_requests_count'],
            'performance' => [
                'machine_duration_ms' => $metrics['machine_duration_ms'],
                'slowest_machines' => array_slice($machineTimings, 0, 10),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function buildPaymentDocumentKey(ClientZoneSoftMachine $machine, array $document): string
    {
        return implode('|', [
            $machine->zs_client_id,
            $document['store_code'] ?? '',
            $document['doc_type'] ?? '',
            $document['document_series'] ?? '',
            $document['document_number'] ?? '',
            $document['payment_key'] ?? 'header',
            $document['payment_code'] ?? '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function buildRowDedupeKey(ClientZoneSoftMachine $machine, array $row): string
    {
        $rawRow = is_array($row['raw_row'] ?? null) ? $row['raw_row'] : [];
        $providerLineId = $rawRow['id'] ?? null;
        $lineIdentity = $providerLineId !== null && (string) $providerLineId !== ''
            ? 'id:'.$providerLineId
            : 'line:'.($rawRow['_document_line_number'] ?? '');

        return implode('|', [
            $machine->zs_client_id,
            $row['store_code'] ?? '',
            $row['doc_type'] ?? '',
            $row['document_series'] ?? '',
            $row['document_number'] ?? '',
            $lineIdentity,
            $row['product_code'] ?? '',
        ]);
    }

    private function persistMachineStatus(
        ClientZoneSoftMachine $machine,
        ?string $message,
        $timestamp,
    ): void {
        $machine->forceFill([
            'last_validated_at' => $timestamp,
            'last_error' => $message,
        ])->save();
    }

    /**
     * @return array{machine_id:int,zs_client_id:string,store_id:int,store_label:?string,message:string}
     */
    private function persistMachineFailure(
        ClientZoneSoftMachine $machine,
        string $message,
        $timestamp,
    ): array {
        $this->persistMachineStatus($machine, $message, $timestamp);

        return [
            'machine_id' => $machine->id,
            'zs_client_id' => $machine->zs_client_id,
            'store_id' => $machine->store_id,
            'store_label' => $machine->store_label,
            'message' => $message,
        ];
    }

    /**
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,store_label:?string,message:string}>  $failedMachines
     */
    private function buildMachineFailureMessage(array $failedMachines): string
    {
        if ($failedMachines === []) {
            return 'Nao foi possivel sincronizar os dados da ZoneSoft.';
        }

        $machine = $failedMachines[0];

        return sprintf(
            'Nenhum Client ID ativo conseguiu sincronizar. Verifique %s (Client ID %s): %s',
            $this->formatMachineSummary($machine),
            $machine['zs_client_id'],
            $machine['message'],
        );
    }

    /**
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,store_label:?string,message:string}>  $failedMachines
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,store_label:?string,message:string}>  $machineWarnings
     */
    private function buildIncompleteSyncMessage(array $failedMachines, array $machineWarnings): string
    {
        $message = sprintf(
            'A sincronizacao nao foi publicada porque ficou incompleta: %d maquina(s) falharam e %d maquina(s) tiveram documentos com erro.',
            count($failedMachines),
            count($machineWarnings),
        );

        $details = [];

        if ($failedMachines !== []) {
            $details[] = 'Falharam: '.$this->formatMachineListPreview($failedMachines);
        }

        if ($machineWarnings !== []) {
            $details[] = 'Documentos com erro: '.$this->formatMachineListPreview($machineWarnings);
        }

        if ($details === []) {
            return $message;
        }

        return $message.' '.implode(' ', $details);
    }

    /**
     * @param  array{store_id:int,store_label:?string}  $machine
     */
    private function formatMachineSummary(array $machine): string
    {
        $storeLabel = trim((string) ($machine['store_label'] ?? ''));

        if ($storeLabel !== '') {
            return sprintf('%s (Store %d)', $storeLabel, $machine['store_id']);
        }

        return sprintf('Store %d', $machine['store_id']);
    }

    /**
     * @param  list<array{store_id:int,store_label:?string}>  $machines
     */
    private function formatMachineListPreview(array $machines): string
    {
        $preview = array_slice(array_map(
            fn (array $machine): string => $this->formatMachineSummary($machine),
            $machines,
        ), 0, 3);
        $remaining = count($machines) - count($preview);

        if ($remaining > 0) {
            $preview[] = sprintf('+%d mais', $remaining);
        }

        return implode(', ', $preview);
    }

    private function ensureSyncIsProcessing(EventReportImport $syncLog): void
    {
        $currentStatus = EventReportImport::query()
            ->whereKey($syncLog->id)
            ->value('status');

        if ($currentStatus !== 'processing') {
            throw new \RuntimeException(
                'Esta sincronizacao ja nao esta em processamento e nao pode publicar dados.',
            );
        }
    }

    private function touchSyncHeartbeat(int $syncImportId): void
    {
        if ($syncImportId <= 0) {
            return;
        }

        EventReportImport::query()
            ->whereKey($syncImportId)
            ->where('status', 'processing')
            ->update(['updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function updateSyncProgress(int $syncImportId, array $progress): void
    {
        $syncImport = EventReportImport::query()
            ->whereKey($syncImportId)
            ->where('status', 'processing')
            ->first();

        if (! $syncImport) {
            return;
        }

        $syncImport->update([
            'summary' => [
                ...(is_array($syncImport->summary) ? $syncImport->summary : []),
                ...$progress,
            ],
        ]);
    }

    /**
     * Stable per-line identity used as the last component of the row's
     * natural key — same rule buildRowDedupeKey() already used to dedupe a
     * single machine's fetch in memory, now also used to upsert across
     * cycles: the API's own line id when present, else the line's position
     * within its document.
     *
     * @param  array<string, mixed>  $row
     */
    private function resolveLineKey(array $row): string
    {
        $rawRow = is_array($row['raw_row'] ?? null) ? $row['raw_row'] : [];
        $providerLineId = $rawRow['id'] ?? null;

        return $providerLineId !== null && (string) $providerLineId !== ''
            ? 'id:'.$providerLineId
            : 'line:'.($rawRow['_document_line_number'] ?? '');
    }

    /**
     * Writes only the rows this cycle actually fetched — an unchanged row is
     * never touched, a changed one is updated in place (same id), a new one
     * is inserted. This is the whole of PERF-101's O(delta) write path for
     * event_report_rows; there is no separate staging step anymore.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsertRows(
        Event $event,
        EventReportImport $syncLog,
        array $rows,
        CarbonInterface $timestamp,
    ): void {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, self::STAGING_INSERT_BATCH_SIZE) as $chunk) {
            EventReportRow::query()->upsert(
                array_map(
                    function (array $row) use ($event, $syncLog, $timestamp): array {
                        $rawRow = $row['raw_row'] ?? null;

                        if (is_array($rawRow)) {
                            $rawRow = json_encode(
                                $rawRow,
                                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                            );
                        }

                        return [
                            'event_id' => $event->id,
                            'event_report_import_id' => $syncLog->id,
                            'machine_id' => $row['machine_id'] ?? null,
                            'source_sheet' => $row['source_sheet'] ?? null,
                            'source_row_number' => $row['source_row_number'] ?? 0,
                            'store_code' => $row['store_code'] ?? null,
                            'store_name' => $row['store_name'] ?? null,
                            'sale_date' => $row['sale_date'] ?? null,
                            'sale_datetime' => $row['sale_datetime'] ?? null,
                            'doc_type' => $row['doc_type'] ?? null,
                            'document_series' => $row['document_series'] ?? null,
                            'document_number' => $row['document_number'] ?? null,
                            'line_key' => $row['line_key'] ?? '',
                            'value' => $row['value'] ?? null,
                            'total' => $row['total'] ?? null,
                            'discount' => $row['discount'] ?? null,
                            'quantity' => $row['quantity'] ?? null,
                            'product_code' => $row['product_code'] ?? null,
                            'description' => $row['description'] ?? null,
                            'raw_row' => is_string($rawRow) ? $rawRow : null,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    },
                    $chunk,
                ),
                uniqueBy: ['event_id', 'machine_id', 'doc_type', 'document_series', 'document_number', 'line_key'],
                update: [
                    'event_report_import_id', 'source_sheet', 'source_row_number',
                    'store_code', 'store_name', 'sale_date', 'sale_datetime',
                    'value', 'total', 'discount', 'quantity',
                    'product_code', 'description', 'raw_row', 'updated_at',
                ],
            );
            $this->touchSyncHeartbeat($syncLog->id);
        }
    }

    /**
     * Same idea as upsertRows() but for event_report_payment_documents,
     * keyed on the event-scoped dedupe_key instead of the row natural key.
     *
     * @param  list<array<string, mixed>>  $documents
     */
    private function upsertPaymentDocuments(
        Event $event,
        EventReportImport $syncLog,
        array $documents,
        CarbonInterface $timestamp,
    ): void {
        if ($documents === []) {
            return;
        }

        foreach (array_chunk($documents, self::STAGING_INSERT_BATCH_SIZE) as $chunk) {
            EventReportPaymentDocument::query()->upsert(
                array_map(
                    fn (array $document): array => $this->mapPaymentDocumentForUpsert(
                        $event,
                        $syncLog,
                        $document,
                        $timestamp,
                    ),
                    $chunk,
                ),
                uniqueBy: ['event_id', 'dedupe_key'],
                update: [
                    'event_report_import_id', 'machine_id', 'machine_client_id',
                    'store_code', 'store_name', 'sale_date', 'sale_datetime',
                    'doc_type', 'document_series', 'document_number',
                    'payment_reference', 'paid', 'document_total', 'payment_key',
                    'payment_code', 'payment_document_type', 'payment_document_series',
                    'payment_document_number', 'payment_card_number', 'total',
                    'is_unallocated', 'updated_at',
                ],
            );
            $this->touchSyncHeartbeat($syncLog->id);
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function mapPaymentDocumentForUpsert(
        Event $event,
        EventReportImport $syncLog,
        array $document,
        CarbonInterface $timestamp,
    ): array {
        return [
            'event_id' => $event->id,
            'event_report_import_id' => $syncLog->id,
            'machine_id' => $document['machine_id'] ?? null,
            'machine_client_id' => $document['machine_client_id'] ?? null,
            'store_code' => $document['store_code'] ?? null,
            'store_name' => $document['store_name'] ?? null,
            'sale_date' => $document['sale_date'] ?? null,
            'sale_datetime' => $document['sale_datetime'] ?? null,
            'doc_type' => $document['doc_type'] ?? null,
            'document_series' => $document['document_series'] ?? null,
            'document_number' => $document['document_number'] ?? null,
            'payment_reference' => $document['payment_reference'] ?? null,
            'paid' => $document['paid'] ?? null,
            'document_total' => $document['document_total'] ?? null,
            'payment_key' => $document['payment_key'] ?? null,
            'payment_code' => $document['payment_code'] ?? null,
            'payment_document_type' => $document['payment_document_type'] ?? null,
            'payment_document_series' => $document['payment_document_series'] ?? null,
            'payment_document_number' => $document['payment_document_number'] ?? null,
            'payment_card_number' => $document['payment_card_number'] ?? null,
            'total' => $document['total'] ?? null,
            'is_unallocated' => (bool) ($document['is_unallocated'] ?? false),
            'dedupe_key' => $this->paymentDocumentDedupeHash($document),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function paymentDocumentDedupeHash(array $document): string
    {
        return hash('sha256', implode('|', [
            $document['machine_client_id'] ?? $document['store_code'] ?? '',
            $document['store_code'] ?? '',
            $document['doc_type'] ?? '',
            $document['document_series'] ?? '',
            $document['document_number'] ?? '',
            $document['payment_key'] ?? 'header',
            $document['payment_code'] ?? '',
        ]));
    }

    /**
     * Reconciles what a document's fetched lines/payments actually contain
     * now against what is durably stored — the replacement for the old
     * copy-then-delete-then-restage dance (removeStagedDocuments()). The
     * natural-key upsert above already handles "line changed" and "line is
     * new"; this only handles "line/payment disappeared from a document
     * that was refetched" (e.g. a document shrank from 5 lines to 3, or was
     * cancelled outright).
     *
     * @param  list<array{machine_id:int,document_keys:list<array<string,string>>}>  $pendingReconciliations
     * @param  list<array<string,mixed>>  $pendingRows
     * @param  list<array<string,mixed>>  $pendingPaymentDocuments
     */
    private function reconcileFetchedDocuments(
        int $eventId,
        array $pendingReconciliations,
        array $pendingRows,
        array $pendingPaymentDocuments,
        bool $isFullMode,
    ): void {
        if ($pendingReconciliations === []) {
            return;
        }

        $survivingLineKeys = [];

        foreach ($pendingRows as $row) {
            $signature = $this->documentSignature(
                (int) ($row['machine_id'] ?? 0),
                (string) ($row['doc_type'] ?? ''),
                (string) ($row['document_series'] ?? ''),
                (string) ($row['document_number'] ?? ''),
            );
            $survivingLineKeys[$signature][] = (string) ($row['line_key'] ?? '');
        }

        $survivingPaymentKeys = [];

        foreach ($pendingPaymentDocuments as $document) {
            $signature = $this->documentSignature(
                (int) ($document['machine_id'] ?? 0),
                (string) ($document['doc_type'] ?? ''),
                (string) ($document['document_series'] ?? ''),
                (string) ($document['document_number'] ?? ''),
            );
            $survivingPaymentKeys[$signature][] = $this->paymentDocumentDedupeHash($document);
        }

        foreach ($pendingReconciliations as $reconciliation) {
            $machineId = $reconciliation['machine_id'];
            $fetchedSignatures = [];

            foreach ($reconciliation['document_keys'] as $documentKey) {
                $docType = trim((string) ($documentKey['doc_type'] ?? ''));
                $documentSeries = trim((string) ($documentKey['document_series'] ?? ''));
                $documentNumber = trim((string) ($documentKey['document_number'] ?? ''));
                $signature = $this->documentSignature($machineId, $docType, $documentSeries, $documentNumber);
                $fetchedSignatures[$signature] = true;

                $rowQuery = EventReportRow::query()
                    ->where('event_id', $eventId)
                    ->where('machine_id', $machineId)
                    ->where('doc_type', $docType)
                    ->whereRaw("COALESCE(document_series, '') = ?", [$documentSeries])
                    ->where('document_number', $documentNumber);
                $survivingLines = $survivingLineKeys[$signature] ?? [];

                if ($survivingLines !== []) {
                    $rowQuery->whereNotIn('line_key', $survivingLines);
                }

                $rowQuery->delete();

                $paymentQuery = EventReportPaymentDocument::query()
                    ->where('event_id', $eventId)
                    ->where('machine_id', $machineId)
                    ->where('doc_type', $docType)
                    ->whereRaw("COALESCE(document_series, '') = ?", [$documentSeries])
                    ->where('document_number', $documentNumber);
                $survivingPayments = $survivingPaymentKeys[$signature] ?? [];

                if ($survivingPayments !== []) {
                    $paymentQuery->whereNotIn('dedupe_key', $survivingPayments);
                }

                $paymentQuery->delete();
            }

            if ($isFullMode) {
                $this->reconcileVanishedDocuments($eventId, $machineId, $fetchedSignatures);
            }
        }
    }

    private function documentSignature(
        int $machineId,
        string $docType,
        string $documentSeries,
        string $documentNumber,
    ): string {
        return implode('|', [$machineId, trim($docType), trim($documentSeries), trim($documentNumber)]);
    }

    /**
     * Full-mode only: a full fetch is the complete set of documents that
     * currently exist for a machine in the sync window, so anything durably
     * stored for that machine that was NOT part of this fetch no longer
     * exists upstream at all (not even as "cancelled") and must be dropped —
     * mirroring what wholesale cleanupSupersededRows() used to guarantee
     * for every full-mode cycle before PERF-101.
     *
     * @param  array<string, bool>  $fetchedSignatures
     */
    private function reconcileVanishedDocuments(int $eventId, int $machineId, array $fetchedSignatures): void
    {
        $existingDocuments = EventReportRow::query()
            ->where('event_id', $eventId)
            ->where('machine_id', $machineId)
            ->select('doc_type', 'document_series', 'document_number')
            ->distinct()
            ->get();

        foreach ($existingDocuments as $document) {
            $docType = (string) $document->doc_type;
            $documentSeries = (string) $document->document_series;
            $documentNumber = (string) $document->document_number;
            $signature = $this->documentSignature($machineId, $docType, $documentSeries, $documentNumber);

            if (isset($fetchedSignatures[$signature])) {
                continue;
            }

            EventReportRow::query()
                ->where('event_id', $eventId)
                ->where('machine_id', $machineId)
                ->where('doc_type', $docType)
                ->whereRaw("COALESCE(document_series, '') = ?", [$documentSeries])
                ->where('document_number', $documentNumber)
                ->delete();

            EventReportPaymentDocument::query()
                ->where('event_id', $eventId)
                ->where('machine_id', $machineId)
                ->where('doc_type', $docType)
                ->whereRaw("COALESCE(document_series, '') = ?", [$documentSeries])
                ->where('document_number', $documentNumber)
                ->delete();
        }
    }

    /**
     * Every machine_id this cycle actually touched — rows it upserted plus
     * any it ran reconciliation for (a machine can have only deletions,
     * e.g. every document cancelled, with zero pending rows) — is exactly
     * the set of machines whose aggregate footprint can possibly be stale.
     *
     * @param  list<array<string, mixed>>  $pendingRows
     * @param  list<array{machine_id:int,document_keys:list<array<string,string>>}>  $pendingReconciliations
     * @return list<int>
     */
    private function touchedMachineIds(array $pendingRows, array $pendingReconciliations): array
    {
        $machineIds = [];

        foreach ($pendingRows as $row) {
            if (isset($row['machine_id'])) {
                $machineIds[(int) $row['machine_id']] = true;
            }
        }

        foreach ($pendingReconciliations as $reconciliation) {
            $machineIds[(int) $reconciliation['machine_id']] = true;
        }

        return array_keys($machineIds);
    }

    /**
     * PERF-401 (fatia 1): rebuilds event_report_row_aggregates/
     * event_report_ticket_aggregates for exactly the machines this cycle
     * touched — delete this machine's existing aggregate footprint, then
     * re-derive it from its current event_report_rows in one GROUP BY
     * pass. Bounded by that machine's row count, never the whole event.
     *
     * Public: in production the only writer of event_report_rows is this
     * service's own upsertRows()/reconcileFetchedDocuments() (called from
     * inside run()'s publish transaction, which already calls this). It's
     * exposed here so anything else that legitimately writes rows directly
     * — a test fixture seeding data without going through a full sync, or
     * a future maintenance command — can keep the aggregate tables
     * consistent instead of leaving them silently stale.
     *
     * A null machine_id is accepted (and refreshed like any other bucket)
     * because rows created directly rather than through a real sync — test
     * fixtures, mainly — can legitimately have no machine attached; the
     * real sync pipeline itself never produces a null machine_id.
     *
     * @param  list<int|null>  $machineIds
     */
    public function refreshRowAggregates(int $eventId, array $machineIds): void
    {
        foreach ($machineIds as $machineId) {
            $this->refreshRowAggregatesForMachine($eventId, $machineId);
        }
    }

    private function refreshRowAggregatesForMachine(int $eventId, ?int $machineId): void
    {
        $timestamp = now();
        $hourExpression = $this->sqlHourOfSaleDatetimeExpression();
        // DATE() around sale_date too, not just sale_datetime: Eloquent's
        // bare 'date' cast (EventReportRow::casts()) only truncates the
        // time component when *reading* the attribute back as Carbon — it
        // still writes the column using $dateFormat ('Y-m-d H:i:s' unless
        // overridden), so sale_date can be stored with a "00:00:00" suffix.
        // Without normalizing both sides, a day/hour comparison against
        // this column would silently exclude every row on date_to filters.
        $dayExpression = 'COALESCE(DATE(sale_date), DATE(sale_datetime))';

        $rowGroups = DB::table('event_report_rows')
            ->where('event_id', $eventId)
            ->where('machine_id', $machineId)
            ->selectRaw("{$dayExpression} as agg_sale_date")
            ->selectRaw("{$hourExpression} as agg_sale_hour")
            ->addSelect(['store_code', 'store_name', 'doc_type', 'product_code', 'description'])
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity_total')
            ->selectRaw('COALESCE(SUM(value), 0) as value_total')
            ->selectRaw('COALESCE(SUM(discount), 0) as discount_total')
            ->selectRaw('COALESCE(SUM(total), 0) as total_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN total = 0 THEN quantity ELSE 0 END), 0) as offered_quantity_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN total != 0 THEN quantity ELSE 0 END), 0) as sold_quantity_total')
            ->groupByRaw($dayExpression)
            ->groupByRaw($hourExpression)
            ->groupBy('store_code', 'store_name', 'doc_type', 'product_code', 'description')
            ->get();

        $ticketGroups = DB::table('event_report_rows')
            ->where('event_id', $eventId)
            ->where('machine_id', $machineId)
            ->selectRaw("{$dayExpression} as agg_sale_date")
            ->selectRaw("{$hourExpression} as agg_sale_hour")
            ->addSelect(['store_code', 'store_name', 'doc_type', 'document_series', 'document_number'])
            ->groupByRaw($dayExpression)
            ->groupByRaw($hourExpression)
            ->groupBy('store_code', 'store_name', 'doc_type', 'document_series', 'document_number')
            ->get();

        EventReportRowAggregate::query()->where('event_id', $eventId)->where('machine_id', $machineId)->delete();
        EventReportTicketAggregate::query()->where('event_id', $eventId)->where('machine_id', $machineId)->delete();

        foreach ($rowGroups->chunk(self::STAGING_INSERT_BATCH_SIZE) as $chunk) {
            EventReportRowAggregate::query()->insert($chunk->map(fn (object $row): array => [
                'event_id' => $eventId,
                'machine_id' => $machineId,
                'sale_date' => $row->agg_sale_date,
                'sale_hour' => $row->agg_sale_hour,
                'store_code' => $row->store_code,
                'store_name' => $row->store_name,
                'doc_type' => $row->doc_type,
                'product_code' => $row->product_code,
                'description' => $row->description,
                'rows_count' => (int) $row->rows_count,
                'quantity_total' => $row->quantity_total,
                'value_total' => $row->value_total,
                'discount_total' => $row->discount_total,
                'total_sum' => $row->total_sum,
                'offered_quantity_total' => $row->offered_quantity_total,
                'sold_quantity_total' => $row->sold_quantity_total,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->all());
        }

        foreach ($ticketGroups->chunk(self::STAGING_INSERT_BATCH_SIZE) as $chunk) {
            EventReportTicketAggregate::query()->insert($chunk->map(fn (object $row): array => [
                'event_id' => $eventId,
                'machine_id' => $machineId,
                'sale_date' => $row->agg_sale_date,
                'sale_hour' => $row->agg_sale_hour,
                'store_code' => $row->store_code,
                'store_name' => $row->store_name,
                'doc_type' => $row->doc_type,
                'document_series' => $row->document_series,
                'document_number' => $row->document_number,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->all());
        }
    }

    /**
     * Same per-driver HOUR(sale_datetime) extraction
     * EventDashboardController::buildHourlySales() already used — kept
     * driver-aware for the same three targets (sqlite locally/CI, mysql/
     * mariadb and pgsql for CR-201/PERF-501).
     */
    private function sqlHourOfSaleDatetimeExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => 'CAST(EXTRACT(HOUR FROM sale_datetime) AS INTEGER)',
            'mysql', 'mariadb' => 'HOUR(sale_datetime)',
            default => "CAST(strftime('%H', sale_datetime) AS INTEGER)",
        };
    }

    /**
     * @param  array<string, mixed>|null  $summary
     */
    private function markSyncAsFailed(
        EventReportImport $syncLog,
        string $message,
        ?array $summary = null,
    ): void {
        try {
            DB::transaction(function () use ($syncLog, $message, $summary): void {
                $lockedSyncLog = EventReportImport::query()
                    ->lockForUpdate()
                    ->find($syncLog->id);

                if (! $lockedSyncLog || $lockedSyncLog->status !== 'processing') {
                    return;
                }

                $lockedSyncLog->update([
                    'status' => 'failed',
                    'is_active' => false,
                    'summary' => [
                        ...($summary ?? $lockedSyncLog->summary ?? []),
                        'error' => $message,
                    ],
                ]);
            });
        } catch (\Throwable $statusException) {
            report($statusException);
        }
    }

    private function resolveExceptionMessage(\Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            $integrationErrors = $exception->errors()['integration'] ?? [];
            $message = $integrationErrors[0] ?? null;

            if (is_string($message) && trim($message) !== '') {
                return $message;
            }
        }

        return trim($exception->getMessage()) !== ''
            ? $exception->getMessage()
            : 'Nao foi possivel sincronizar os dados da ZoneSoft.';
    }

    /**
     * @return array{start:CarbonImmutable,end:CarbonImmutable}
     */
    private function resolveSyncRange(Event $event): array
    {
        $start = $event->report_starts_at
            ? CarbonImmutable::instance($event->report_starts_at)
            : null;
        $end = $event->report_ends_at
            ? CarbonImmutable::instance($event->report_ends_at)
            : null;

        if ($start === null && $end === null) {
            $eventDate = CarbonImmutable::instance($event->event_date);

            return [
                'start' => $eventDate->startOfDay(),
                'end' => $eventDate->endOfDay(),
            ];
        }

        if ($start === null) {
            $start = CarbonImmutable::instance($event->event_date);
        }

        if ($end === null) {
            $end = $start->endOfDay();
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{start:CarbonImmutable,end:CarbonImmutable}  $syncRange
     */
    private function rowMatchesSyncRange(array $row, array $syncRange): bool
    {
        $saleDateTime = $this->parseCarbon($row['sale_datetime'] ?? null);

        if ($saleDateTime !== null) {
            return ! $saleDateTime->lt($syncRange['start'])
                && ! $saleDateTime->gt($syncRange['end']);
        }

        $saleDate = $this->parseCarbon($row['sale_date'] ?? null);

        if ($saleDate === null) {
            return true;
        }

        return ! $saleDate->startOfDay()->lt($syncRange['start']->startOfDay())
            && ! $saleDate->endOfDay()->gt($syncRange['end']->endOfDay());
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function prepareLongRunningSync(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
        }

        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }

    private function normalizeDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            $value = str_replace(',', '.', (string) $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }

    private function parseCarbon(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
