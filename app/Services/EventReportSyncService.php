<?php

namespace App\Services;

use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportRow;
use App\Models\User;
use App\Services\ZoneSoft\ZoneSoftApiClient;
use App\Services\ZoneSoft\ZoneSoftApiException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\Pool as ProcessPool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\SerializableClosure\SerializableClosure;

class EventReportSyncService
{
    private const GLOBAL_SYNC_START_LOCK = 'event-report:global-sync-start';

    private const DOCUMENT_SYNC_CONCURRENCY = 2;

    private const DOCUMENT_REQUEST_BATCH_SIZE = 50;

    private const REPORT_TIMEZONE = 'Europe/Lisbon';

    private const BUSINESS_DAY_REFRESH_LOOKBACK_DAYS = 1;

    private const STALE_PROCESSING_TIMEOUT_MINUTES = 30;

    private const RATE_LIMIT_SERIAL_RETRY_ROUNDS = 2;

    private const RATE_LIMIT_SERIAL_RETRY_ROUND_PAUSE_MICROSECONDS = 2000000;

    private const RATE_LIMIT_SERIAL_RETRY_MACHINE_PAUSE_MICROSECONDS = 350000;

    private const FINALIZATION_TRANSACTION_ATTEMPTS = 5;

    public function __construct(
        private readonly ZoneSoftApiClient $apiClient,
        private readonly ProcessFactory $processFactory,
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
            $this->cleanupImportRows($staleImport->id);
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
        $this->markSyncAsFailed($syncLog, $message, $summary);
        $this->cleanupImportRows($syncLog->id);
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

            $rows = $machineSync['rows'];
            $successfulMachinesCount = $machineSync['successful_machines_count'];
            $failedMachines = $machineSync['failed_machines'];
            $machineWarnings = $machineSync['machine_warnings'];
            $paymentDocuments = $machineSync['payment_documents'];
            $reusedRowsCount = $machineSync['reused_rows_count'];

            if ($successfulMachinesCount === 0) {
                throw ValidationException::withMessages([
                    'integration' => $this->buildMachineFailureMessage($failedMachines),
                ]);
            }

            $summary = $this->buildSummary(
                $rows,
                $successfulMachinesCount,
                $failedMachines,
                $machineWarnings,
                $paymentDocuments,
                $reusedRowsCount,
                $machineSync['metrics'],
            );
            $summary['performance'] = [
                ...($summary['performance'] ?? []),
                'fetch_duration_ms' => $fetchDurationMs,
            ];
            $failureSummary = $summary;

            if ($failedMachines !== [] || $machineWarnings !== []) {
                $message = $this->buildIncompleteSyncMessage($failedMachines, $machineWarnings);

                throw ValidationException::withMessages([
                    'integration' => $message,
                ]);
            }

            $timestamp = now();
            $stagingStartedAt = microtime(true);
            $this->stageRows($event, $syncLog, $rows, $timestamp);
            $summary['performance'] = [
                ...($summary['performance'] ?? []),
                'staging_duration_ms' => (int) round((microtime(true) - $stagingStartedAt) * 1000),
                'total_duration_ms' => (int) round((microtime(true) - $runStartedAt) * 1000),
            ];
            $failureSummary = $summary;

            $completedSync = DB::transaction(function () use ($event, $syncLog, $rows, $summary, $timestamp): EventReportImport {
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

                $lockedEvent->reportImports()
                    ->where('is_active', true)
                    ->update(['is_active' => false]);

                $lockedSyncLog->update([
                    'summary' => $summary,
                    'imported_rows_count' => count($rows),
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

            $this->cleanupSupersededRows($event->id, $completedSync->id);

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
     * @param  Collection<int, ClientZoneSoftMachine>  $machines
     * @return array{
     *     rows:list<array<string, mixed>>,
     *     successful_machines_count:int,
     *     failed_machines:list<array{machine_id:int,zs_client_id:string,store_id:int,message:string}>,
     *     machine_warnings:list<array{machine_id:int,zs_client_id:string,store_id:int,message:string}>,
     *     payment_documents:list<array<string, mixed>>,
     *     reused_rows_count:int,
     *     metrics:array{documents_count:int,api_requests_count:int,machine_duration_ms:int}
     * }
     */
    private function fetchRows(
        Event $event,
        Collection $machines,
        EventReportImport $syncLog,
    ): array {
        $syncRange = $this->resolveSyncRange($event);
        $historicalData = $this->resolveReusableHistoricalData($event, $machines, $syncRange);
        $machineResults = $this->fetchMachineResults(
            $machines,
            $historicalData['fetch_range'],
            $syncLog,
        );
        $rows = $historicalData['rows'];
        $dedupe = [];
        $paymentDocuments = $historicalData['payment_documents'];
        $paymentDocumentDedupe = [];
        $successfulMachinesCount = 0;
        $failedMachines = [];
        $machineWarnings = [];
        $metrics = [
            'documents_count' => 0,
            'api_requests_count' => 0,
            'machine_duration_ms' => 0,
        ];
        $statusTimestamp = now();
        $machinesById = $machines->keyBy('id');

        foreach ($rows as $row) {
            $machineId = (int) data_get($row, 'raw_row.machine_id', 0);
            $machine = $machinesById->get($machineId);

            if ($machine) {
                $dedupe[$this->buildRowDedupeKey($machine, $row)] = true;
            }
        }

        foreach ($paymentDocuments as $paymentDocument) {
            $machine = $machinesById->get((int) ($paymentDocument['machine_id'] ?? 0));

            if ($machine) {
                $paymentDocumentDedupe[$this->buildPaymentDocumentKey($machine, $paymentDocument)] = true;
            }
        }

        foreach ($machines as $machine) {
            $result = $machineResults[$machine->id] ?? [
                'failure_message' => 'Nao foi possivel concluir a sincronizacao desta maquina.',
                'warning_message' => null,
                'rows' => [],
                'payment_documents' => [],
                'metrics' => [],
            ];
            $resultMetrics = is_array($result['metrics'] ?? null) ? $result['metrics'] : [];
            $metrics['documents_count'] += (int) ($resultMetrics['documents_count'] ?? 0);
            $metrics['api_requests_count'] += (int) ($resultMetrics['api_requests_count'] ?? 0);
            $metrics['machine_duration_ms'] += (int) ($resultMetrics['duration_ms'] ?? 0);

            if (is_string($result['failure_message'] ?? null) && $result['failure_message'] !== '') {
                $failedMachines[] = $this->persistMachineFailure(
                    $machine,
                    $result['failure_message'],
                    $statusTimestamp,
                );

                continue;
            }

            foreach ($result['rows'] ?? [] as $normalizedRow) {
                $dedupeKey = $this->buildRowDedupeKey($machine, $normalizedRow);

                if (isset($dedupe[$dedupeKey])) {
                    continue;
                }

                $dedupe[$dedupeKey] = true;
                $rows[] = $normalizedRow;
            }

            foreach ($result['payment_documents'] ?? [] as $paymentDocument) {
                $paymentDocumentKey = $this->buildPaymentDocumentKey(
                    $machine,
                    $paymentDocument,
                );

                if (isset($paymentDocumentDedupe[$paymentDocumentKey])) {
                    continue;
                }

                $paymentDocumentDedupe[$paymentDocumentKey] = true;
                $paymentDocuments[] = $paymentDocument;
            }

            $successfulMachinesCount++;
            $warningMessage = $result['warning_message'] ?? null;

            $this->persistMachineStatus(
                $machine,
                $warningMessage,
                $statusTimestamp,
            );

            if (is_string($warningMessage) && $warningMessage !== '') {
                $machineWarnings[] = [
                    'machine_id' => $machine->id,
                    'zs_client_id' => $machine->zs_client_id,
                    'store_id' => $machine->store_id,
                    'message' => $warningMessage,
                ];
            }
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['source_row_number'] = $index + 1;
        }

        return [
            'rows' => $rows,
            'successful_machines_count' => $successfulMachinesCount,
            'failed_machines' => $failedMachines,
            'machine_warnings' => $machineWarnings,
            'payment_documents' => $paymentDocuments,
            'reused_rows_count' => count($historicalData['rows']),
            'metrics' => $metrics,
        ];
    }

    /**
     * @param  Collection<int, ClientZoneSoftMachine>  $machines
     * @param  array{start:CarbonImmutable,end:CarbonImmutable}  $syncRange
     * @return array{
     *     fetch_range:array{start:CarbonImmutable,end:CarbonImmutable},
     *     rows:list<array<string, mixed>>,
     *     payment_documents:list<array<string, mixed>>
     * }
     */
    private function resolveReusableHistoricalData(
        Event $event,
        Collection $machines,
        array $syncRange,
    ): array {
        $emptyResult = [
            'fetch_range' => $syncRange,
            'rows' => [],
            'payment_documents' => [],
        ];
        $today = CarbonImmutable::now(self::REPORT_TIMEZONE)->startOfDay();
        $refreshFrom = $today->subDays(self::BUSINESS_DAY_REFRESH_LOOKBACK_DAYS);

        if ($syncRange['start']->gte($today) || $syncRange['end']->lt($today)) {
            return $emptyResult;
        }

        $activeImport = $event->reportImports()
            ->where('is_active', true)
            ->where('status', 'completed')
            ->latest('imported_at')
            ->first();

        if (! $activeImport) {
            return $emptyResult;
        }

        $summary = is_array($activeImport->summary) ? $activeImport->summary : [];

        if (($summary['failed_machines'] ?? []) !== [] || ($summary['machine_warnings'] ?? []) !== []) {
            return $emptyResult;
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
            return $emptyResult;
        }

        $rows = $activeImport->rows()
            ->whereDate('sale_date', '>=', $syncRange['start']->toDateString())
            ->whereDate('sale_date', '<', $refreshFrom->toDateString())
            ->orderBy('source_row_number')
            ->get()
            ->map(static fn (EventReportRow $row): array => [
                'source_sheet' => $row->source_sheet,
                'source_row_number' => 0,
                'store_code' => $row->store_code,
                'store_name' => $row->store_name,
                'sale_date' => $row->sale_date?->toDateString(),
                'sale_datetime' => $row->sale_datetime?->format('Y-m-d H:i:s'),
                'doc_type' => $row->doc_type,
                'document_series' => $row->document_series,
                'document_number' => $row->document_number,
                'value' => $row->value,
                'total' => $row->total,
                'discount' => $row->discount,
                'quantity' => $row->quantity,
                'product_code' => $row->product_code,
                'description' => $row->description,
                'raw_row' => is_array($row->raw_row) ? $row->raw_row : [],
            ])
            ->values()
            ->all();
        $paymentDocuments = array_values(array_filter(
            is_array($summary['payment_documents'] ?? null)
                ? $summary['payment_documents']
                : [],
            function (mixed $document) use ($syncRange, $refreshFrom): bool {
                if (! is_array($document)) {
                    return false;
                }

                $saleDate = $this->parseCarbon($document['sale_date'] ?? null);

                return $saleDate !== null
                    && $saleDate->gte($syncRange['start']->startOfDay())
                    && $saleDate->lt($refreshFrom);
            },
        ));

        $fetchStart = $syncRange['start']->gt($refreshFrom)
            ? $syncRange['start']
            : $refreshFrom;

        return [
            'fetch_range' => [
                'start' => $fetchStart,
                'end' => $syncRange['end'],
            ],
            'rows' => $rows,
            'payment_documents' => $paymentDocuments,
        ];
    }

    /**
     * @param  Collection<int, ClientZoneSoftMachine>  $machines
     * @param  array{start:CarbonImmutable,end:CarbonImmutable}  $syncRange
     * @return array<int, array{
     *     failure_message:string|null,
     *     warning_message:string|null,
     *     rows:list<array<string, mixed>>,
     *     payment_documents:list<array<string, mixed>>,
     *     should_retry_serially:bool,
     *     metrics:array<string, int>
     * }>
     */
    private function fetchMachineResults(
        Collection $machines,
        array $syncRange,
        EventReportImport $syncLog,
    ): array {
        $rangePayload = [
            'start' => $syncRange['start']->toIso8601String(),
            'end' => $syncRange['end']->toIso8601String(),
            'sync_import_id' => $syncLog->id,
        ];

        $results = [];
        $processedMachines = 0;
        $documentsCount = 0;
        $apiRequestsCount = 0;
        $totalMachines = $machines->count();

        foreach (array_chunk($machines->modelKeys(), $this->machineSyncConcurrency()) as $machineIdChunk) {
            $tasks = [];

            foreach ($machineIdChunk as $machineId) {
                $tasks[$machineId] = static function () use ($machineId, $rangePayload): array {
                    return app(self::class)->syncMachinePayload($machineId, $rangePayload);
                };
            }

            /** @var array<int, array<string, mixed>> $chunkResults */
            $chunkResults = $this->runConcurrentMachineTasks($tasks);
            $results += $chunkResults;
            $processedMachines += count($chunkResults);

            foreach ($chunkResults as $chunkResult) {
                $chunkMetrics = is_array($chunkResult['metrics'] ?? null) ? $chunkResult['metrics'] : [];
                $documentsCount += (int) ($chunkMetrics['documents_count'] ?? 0);
                $apiRequestsCount += (int) ($chunkMetrics['api_requests_count'] ?? 0);
            }

            $this->updateSyncProgress($syncLog->id, [
                'stage' => 'fetching',
                'machines_total' => $totalMachines,
                'machines_processed' => $processedMachines,
                'documents_processed' => $documentsCount,
                'api_requests_count' => $apiRequestsCount,
            ]);
        }

        return $this->retryRateLimitedMachineResults($results, $rangePayload);
    }

    private function machineSyncConcurrency(): int
    {
        return max(1, min(20, (int) config('event-reports.zonesoft.machine_sync_concurrency', 8)));
    }

    /**
     * @param  array<int, \Closure(): array<string, mixed>>  $tasks
     * @return array<int, array<string, mixed>>
     */
    private function runConcurrentMachineTasks(array $tasks): array
    {
        if (count($tasks) === 1) {
            $key = array_key_first($tasks);

            if ($key === null) {
                return [];
            }

            /** @var array<string, mixed> $result */
            $result = $tasks[$key]();

            return [(int) $key => $result];
        }

        if (app()->runningUnitTests()) {
            /** @var array<int, array<string, mixed>> $results */
            $results = Concurrency::driver('sync')->run($tasks);

            return $results;
        }

        if (PHP_SAPI !== 'cli') {
            /** @var array<int, array<string, mixed>> $results */
            $results = Concurrency::driver()->run($tasks);

            return $results;
        }

        $command = ConsoleApplication::formatCommandString('invoke-serialized-closure');
        $results = $this->processFactory
            ->pool(function (ProcessPool $pool) use ($tasks, $command): void {
                foreach ($tasks as $key => $task) {
                    $pool->as((string) $key)
                        ->path(base_path())
                        ->forever()
                        ->env([
                            'LARAVEL_INVOKABLE_CLOSURE' => base64_encode(
                                serialize(new SerializableClosure($task))
                            ),
                        ])
                        ->command($command);
                }
            })
            ->start()
            ->wait();

        return $results->collect()->mapWithKeys(function ($result, $key): array {
            if ($result->failed()) {
                throw new \RuntimeException(sprintf(
                    'Concurrent process failed with exit code [%s]. Message: %s',
                    $result->exitCode(),
                    trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : trim($result->output()),
                ));
            }

            $payload = json_decode($result->output(), true);

            if (! is_array($payload)) {
                throw new \RuntimeException('Concurrent process returned an invalid payload.');
            }

            if (! ($payload['successful'] ?? false)) {
                $message = is_string($payload['message'] ?? null) && trim($payload['message']) !== ''
                    ? $payload['message']
                    : 'Concurrent process execution failed.';

                throw new \RuntimeException($message);
            }

            return [(int) $key => unserialize($payload['result'])];
        })->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @param  array{start:string,end:string,sync_import_id?:int}  $rangePayload
     * @return array<int, array<string, mixed>>
     */
    private function retryRateLimitedMachineResults(array $results, array $rangePayload): array
    {
        for ($round = 0; $round < self::RATE_LIMIT_SERIAL_RETRY_ROUNDS; $round++) {
            $retryMachineIds = array_values(array_filter(
                array_map(
                    fn (int|string $machineId, array $result): ?int => ($result['should_retry_serially'] ?? false) === true
                        ? (int) $machineId
                        : null,
                    array_keys($results),
                    $results,
                ),
                static fn (?int $machineId): bool => $machineId !== null,
            ));

            if ($retryMachineIds === []) {
                break;
            }

            $this->pauseBeforeRateLimitRetryRound(self::RATE_LIMIT_SERIAL_RETRY_ROUND_PAUSE_MICROSECONDS);

            foreach ($retryMachineIds as $index => $machineId) {
                $results[$machineId] = $this->syncMachinePayload($machineId, $rangePayload);
                $this->touchSyncHeartbeat((int) ($rangePayload['sync_import_id'] ?? 0));

                if ($index < count($retryMachineIds) - 1) {
                    $this->pauseBeforeRateLimitRetryRound(self::RATE_LIMIT_SERIAL_RETRY_MACHINE_PAUSE_MICROSECONDS);
                }
            }
        }

        return $results;
    }

    private function pauseBeforeRateLimitRetryRound(int $microseconds): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        usleep($microseconds);
    }

    /**
     * @param  array{start:string,end:string,sync_import_id?:int}  $rangePayload
     * @return array{
     *     failure_message:string|null,
     *     warning_message:string|null,
     *     rows:list<array<string, mixed>>,
     *     payment_documents:list<array<string, mixed>>,
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
                'should_retry_serially' => false,
                'metrics' => $this->machineMetrics($startedAt),
            ];
        }

        $syncRange = [
            'start' => CarbonImmutable::parse($rangePayload['start']),
            'end' => CarbonImmutable::parse($rangePayload['end']),
        ];

        $usesCompleteDocuments = (bool) config('event-reports.zonesoft.complete_documents', true);
        $documentRequestCount = 0;

        try {
            $documentFetch = $this->fetchDocuments($machine, $syncRange, $usesCompleteDocuments);
            $documents = $documentFetch['documents'];
            $documentRequestCount = $documentFetch['request_count'];
            $documents = array_values(array_filter(
                $documents,
                fn (array $document): bool => ! $this->isCancelledDocument($document),
            ));
        } catch (ZoneSoftApiException $exception) {
            return [
                'failure_message' => $exception->getMessage(),
                'warning_message' => null,
                'rows' => [],
                'payment_documents' => [],
                'should_retry_serially' => $exception->isRateLimited(),
                'metrics' => $this->machineMetrics($startedAt, 0, max(1, $documentRequestCount)),
            ];
        }

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
            'should_retry_serially' => $shouldRetrySerially,
            'metrics' => $this->machineMetrics(
                $startedAt,
                count($documents),
                $documentRequestCount,
            ),
        ];
    }

    /**
     * @param  array{start:CarbonImmutable,end:CarbonImmutable}  $syncRange
     * @return array{documents:list<array<string, mixed>>,request_count:int}
     */
    private function fetchDocuments(
        ClientZoneSoftMachine $machine,
        array $syncRange,
        bool $completeDocuments,
    ): array {
        $documents = [];
        $offset = 0;
        $limit = 250;
        $requestCount = 0;

        do {
            $requestCount++;
            $response = $this->apiClient->post(
                $machine->application,
                $machine->zs_client_id,
                'documents',
                $completeDocuments ? 'getInstances' : 'getDocumentsHeaders',
                'document',
                [
                    'condition' => $this->buildDocumentCondition($machine, $syncRange),
                    'order' => 'data ASC, numero ASC',
                    'limit' => $limit,
                    'offset' => $offset,
                ],
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
    private function buildDocumentCondition(ClientZoneSoftMachine $machine, array $syncRange): string
    {
        return implode(' and ', [
            sprintf('loja = %d', $machine->store_id),
            sprintf("data >= '%s'", $syncRange['start']->toDateString()),
            sprintf("data <= '%s'", $syncRange['end']->toDateString()),
        ]);
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
            'source_row_number' => 0,
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
                ...$sale,
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

        $normalizedPayments = array_map(function (array $payment, int $index) use ($baseDocument, $document): array {
            $paymentCode = isset($payment['tipo']) && (int) $payment['tipo'] !== 0
                ? (string) $payment['tipo']
                : (isset($document['pagamento']) ? (string) $document['pagamento'] : null);

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
                'total' => $this->normalizeDecimal($payment['valor'] ?? ($document['total'] ?? null)),
            ];
        }, $partialPayments, array_keys($partialPayments));

        $documentTotal = (float) ($baseDocument['document_total'] ?? 0);
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
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,message:string}>  $failedMachines
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,message:string}>  $machineWarnings
     * @param  list<array<string, mixed>>  $paymentDocuments
     * @param  array{documents_count:int,api_requests_count:int,machine_duration_ms:int}  $metrics
     * @return array<string, mixed>
     */
    private function buildSummary(
        array $rows,
        int $successfulMachinesCount,
        array $failedMachines,
        array $machineWarnings,
        array $paymentDocuments,
        int $reusedRowsCount,
        array $metrics,
    ): array {
        $totals = [
            'value' => 0.0,
            'total' => 0.0,
            'discount' => 0.0,
            'quantity' => 0.0,
        ];

        foreach ($rows as $row) {
            $totals['value'] += (float) ($row['value'] ?? 0);
            $totals['total'] += (float) ($row['total'] ?? 0);
            $totals['discount'] += (float) ($row['discount'] ?? 0);
            $totals['quantity'] += (float) ($row['quantity'] ?? 0);
        }

        return [
            'source' => 'zonesoft_api',
            'machines_count' => $successfulMachinesCount,
            'rows_count' => count($rows),
            'reused_rows_count' => $reusedRowsCount,
            'fetched_rows_count' => count($rows) - $reusedRowsCount,
            'unique_stores' => count(array_unique(array_values(array_filter(array_column($rows, 'store_name'))))),
            'unique_products' => count(array_unique(array_values(array_filter(array_column($rows, 'product_code'))))),
            'value_total' => number_format($totals['value'], 4, '.', ''),
            'sales_total' => number_format($totals['total'], 4, '.', ''),
            'discount_total' => number_format($totals['discount'], 4, '.', ''),
            'quantity_total' => number_format($totals['quantity'], 4, '.', ''),
            'failed_machines' => $failedMachines,
            'machine_warnings' => $machineWarnings,
            'payment_documents' => $paymentDocuments,
            'stage' => 'completed',
            'machines_total' => $successfulMachinesCount + count($failedMachines),
            'machines_processed' => $successfulMachinesCount + count($failedMachines),
            'documents_processed' => $metrics['documents_count'],
            'api_requests_count' => $metrics['api_requests_count'],
            'performance' => [
                'machine_duration_ms' => $metrics['machine_duration_ms'],
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
     * @return array{machine_id:int,zs_client_id:string,store_id:int,message:string}
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
            'message' => $message,
        ];
    }

    /**
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,message:string}>  $failedMachines
     */
    private function buildMachineFailureMessage(array $failedMachines): string
    {
        if ($failedMachines === []) {
            return 'Nao foi possivel sincronizar os dados da ZoneSoft.';
        }

        $machine = $failedMachines[0];

        return sprintf(
            'Nenhum Client ID ativo conseguiu sincronizar. Verifique o Client ID %s (Store %d): %s',
            $machine['zs_client_id'],
            $machine['store_id'],
            $machine['message'],
        );
    }

    /**
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,message:string}>  $failedMachines
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,message:string}>  $machineWarnings
     */
    private function buildIncompleteSyncMessage(array $failedMachines, array $machineWarnings): string
    {
        return sprintf(
            'A sincronizacao nao foi publicada porque ficou incompleta: %d maquina(s) falharam e %d maquina(s) tiveram documentos com erro.',
            count($failedMachines),
            count($machineWarnings),
        );
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
     * @param  list<array<string, mixed>>  $rows
     */
    private function stageRows(
        Event $event,
        EventReportImport $syncLog,
        array $rows,
        CarbonInterface $timestamp,
    ): void {
        $this->ensureSyncIsProcessing($syncLog);
        $this->cleanupImportRows($syncLog->id, true);
        $this->updateSyncProgress($syncLog->id, [
            'stage' => 'staging',
            'rows_count' => count($rows),
        ]);

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->ensureSyncIsProcessing($syncLog);
            EventReportRow::query()->insert(
                array_map(
                    fn (array $row): array => [
                        ...$row,
                        'event_id' => $event->id,
                        'event_report_import_id' => $syncLog->id,
                        'raw_row' => json_encode($row['raw_row'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                    $chunk,
                ),
            );
            $this->touchSyncHeartbeat($syncLog->id);
        }
    }

    private function cleanupImportRows(int $syncImportId, bool $allowProcessing = false): void
    {
        $syncImport = EventReportImport::query()->find($syncImportId);

        if (! $syncImport || ($syncImport->status === 'processing' && ! $allowProcessing)) {
            return;
        }

        if ($syncImport->status === 'completed' && $syncImport->is_active) {
            return;
        }

        do {
            $rowIds = EventReportRow::query()
                ->where('event_report_import_id', $syncImportId)
                ->limit(1000)
                ->pluck('id');

            if ($rowIds->isNotEmpty()) {
                EventReportRow::query()->whereKey($rowIds)->delete();
            }
        } while ($rowIds->isNotEmpty());
    }

    private function cleanupSupersededRows(int $eventId, int $activeImportId): void
    {
        try {
            do {
                $rowIds = EventReportRow::query()
                    ->where('event_id', $eventId)
                    ->where('event_report_import_id', '!=', $activeImportId)
                    ->whereHas('reportImport', fn ($query) => $query
                        ->where('status', '!=', 'processing'))
                    ->limit(1000)
                    ->pluck('id');

                if ($rowIds->isNotEmpty()) {
                    EventReportRow::query()->whereKey($rowIds)->delete();
                }
            } while ($rowIds->isNotEmpty());
        } catch (\Throwable $exception) {
            report($exception);
        }
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

        if ($start !== null && $end === null) {
            $end = $start->endOfDay();
        }

        if ($start === null && $end !== null) {
            $start = $end->startOfDay();
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
