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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventReportSyncService
{
    public function __construct(
        private readonly ZoneSoftApiClient $apiClient,
    ) {
    }

    public function sync(Event $event, ?User $uploadedBy = null): EventReportImport
    {
        $syncLog = $this->start($event, $uploadedBy);

        return $this->run($syncLog);
    }

    public function start(Event $event, ?User $uploadedBy = null): EventReportImport
    {
        $event->load('client');

        if ($event->reportImports()->where('status', 'processing')->exists()) {
            throw ValidationException::withMessages([
                'integration' => 'Ja existe uma sincronizacao em andamento para este evento.',
            ]);
        }

        $machines = $this->resolveMachines($event);

        return $this->createSyncLog($event, $machines, $uploadedBy);
    }

    public function run(EventReportImport $syncLog): EventReportImport
    {
        $this->prepareLongRunningSync();
        $syncLog->loadMissing('event.client');

        $event = $syncLog->event;

        if (! $event) {
            throw new \RuntimeException('O evento associado a esta sincronizacao nao foi encontrado.');
        }

        try {
            $machines = $this->resolveMachines($event);
            $machineSync = $this->fetchRows($event, $machines);
        } catch (\Throwable $exception) {
            $syncLog->update([
                'status' => 'failed',
                'summary' => [
                    ...($syncLog->summary ?? []),
                    'error' => $exception->getMessage() !== ''
                        ? $exception->getMessage()
                        : 'Nao foi possivel sincronizar os dados da ZoneSoft.',
                ],
            ]);

            throw $exception;
        }

        $rows = $machineSync['rows'];
        $successfulMachinesCount = $machineSync['successful_machines_count'];
        $failedMachines = $machineSync['failed_machines'];
        $machineWarnings = $machineSync['machine_warnings'];
        $salesDayRecords = $machineSync['salesday_records'];
        $salesDayWarnings = $machineSync['salesday_warnings'];
        $paymentDocuments = $machineSync['payment_documents'];

        if ($successfulMachinesCount === 0) {
            $message = $this->buildMachineFailureMessage($failedMachines);

            $syncLog->update([
                'status' => 'failed',
                'summary' => [
                    ...($syncLog->summary ?? []),
                    'machines_count' => 0,
                    'failed_machines' => $failedMachines,
                    'error' => $message,
                ],
            ]);

            throw ValidationException::withMessages([
                'integration' => $message,
            ]);
        }

        $summary = $this->buildSummary(
            $rows,
            $successfulMachinesCount,
            $failedMachines,
            $machineWarnings,
            $salesDayRecords,
            $salesDayWarnings,
            $paymentDocuments,
        );
        $timestamp = now();

        return DB::transaction(function () use ($event, $syncLog, $rows, $summary, $timestamp): EventReportImport {
            $event->reportImports()
                ->where('is_active', true)
                ->update(['is_active' => false]);

            foreach (array_chunk($rows, 500) as $chunk) {
                EventReportRow::query()->insert(
                    array_map(
                        fn (array $row): array => [
                            ...$row,
                            'event_id' => $event->id,
                            'event_report_import_id' => $syncLog->id,
                            'raw_row' => json_encode($row['raw_row'], JSON_UNESCAPED_UNICODE),
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ],
                        $chunk,
                    ),
                );
            }

            $syncLog->update([
                'summary' => $summary,
                'imported_rows_count' => count($rows),
                'imported_at' => $timestamp,
                'is_active' => true,
                'status' => 'completed',
            ]);

            return $syncLog->fresh();
        });
    }

    /**
     * @return Collection<int, ClientZoneSoftMachine>
     */
    private function resolveMachines(Event $event): Collection
    {
        $machines = $event->client->zonesoftMachines()
            ->with('application')
            ->where('is_active', true)
            ->get();

        if ($machines->isEmpty()) {
            throw ValidationException::withMessages([
                'integration' => 'Este cliente ainda nao possui Client IDs ativos para sincronizar.',
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
     *     salesday_records:list<array<string, mixed>>,
     *     salesday_warnings:list<array{machine_id:int,zs_client_id:string,store_id:int,message:string}>,
     *     payment_documents:list<array<string, mixed>>
     * }
     */
    private function fetchRows(Event $event, Collection $machines): array
    {
        $syncRange = $this->resolveSyncRange($event);
        $rows = [];
        $dedupe = [];
        $salesDayRecords = [];
        $salesDayDedupe = [];
        $paymentDocuments = [];
        $paymentDocumentDedupe = [];
        $successfulMachinesCount = 0;
        $failedMachines = [];
        $machineWarnings = [];
        $salesDayWarnings = [];

        foreach ($machines as $machine) {
            if (! $machine->application) {
                $failedMachines[] = $this->markMachineFailure(
                    $machine,
                    'A aplicacao ZoneSoft desta maquina nao esta disponivel.',
                );

                continue;
            }

            try {
                $documents = $this->fetchDocuments($machine, $syncRange);
            } catch (ZoneSoftApiException $exception) {
                $failedMachines[] = $this->markMachineFailure($machine, $exception->getMessage());

                continue;
            }

            $documentWarnings = [];
            $salesDayWarning = null;

            foreach ($documents as $document) {
                try {
                    $sales = $this->fetchSalesFromDocument($machine, $document);
                } catch (ZoneSoftApiException $exception) {
                    $documentWarnings[] = $this->buildDocumentWarningMessage(
                        $document,
                        $exception->getMessage(),
                    );

                    continue;
                }

                $documentRows = [];

                foreach ($sales as $sale) {
                    $normalizedRow = $this->normalizeSaleRow(
                        $machine,
                        $sale,
                        count($rows) + 1,
                    );

                    if (! $this->rowMatchesSyncRange($normalizedRow, $syncRange)) {
                        continue;
                    }

                    $dedupeKey = implode('|', [
                        $machine->zs_client_id,
                        $normalizedRow['store_code'] ?? '',
                        $normalizedRow['doc_type'] ?? '',
                        $normalizedRow['document_series'] ?? '',
                        $normalizedRow['document_number'] ?? '',
                        $sale['id'] ?? '',
                        $normalizedRow['product_code'] ?? '',
                    ]);

                    if (isset($dedupe[$dedupeKey])) {
                        continue;
                    }

                    $dedupe[$dedupeKey] = true;
                    $rows[] = $normalizedRow;
                    $documentRows[] = $normalizedRow;
                }

                if ($documentRows !== []) {
                    $normalizedPaymentDocument = $this->normalizePaymentDocument(
                        $machine,
                        $document,
                        $documentRows[0],
                    );
                    $paymentDocumentKey = $this->buildPaymentDocumentKey(
                        $machine,
                        $normalizedPaymentDocument,
                    );

                    if (! isset($paymentDocumentDedupe[$paymentDocumentKey])) {
                        $paymentDocumentDedupe[$paymentDocumentKey] = true;
                        $paymentDocuments[] = $normalizedPaymentDocument;
                    }
                }
            }

            try {
                $machineSalesDayRecords = $this->fetchSalesDayRecords($machine, $syncRange);
            } catch (ZoneSoftApiException $exception) {
                $salesDayWarning = sprintf(
                    'Resumo Salesday indisponivel para a loja %d: %s',
                    $machine->store_id,
                    $exception->getMessage(),
                );
                $salesDayWarnings[] = [
                    'machine_id' => $machine->id,
                    'zs_client_id' => $machine->zs_client_id,
                    'store_id' => $machine->store_id,
                    'message' => $salesDayWarning,
                ];
                $machineSalesDayRecords = [];
            }

            foreach ($machineSalesDayRecords as $salesDayRecord) {
                $normalizedSalesDayRecord = $this->normalizeSalesDayRecord($machine, $salesDayRecord);

                $salesDayKey = implode('|', [
                    $machine->zs_client_id,
                    $normalizedSalesDayRecord['store_code'] ?? '',
                    $normalizedSalesDayRecord['sale_date'] ?? '',
                    $normalizedSalesDayRecord['cash_register_code'] ?? '',
                ]);

                if (isset($salesDayDedupe[$salesDayKey])) {
                    continue;
                }

                $salesDayDedupe[$salesDayKey] = true;
                $salesDayRecords[] = $normalizedSalesDayRecord;
            }

            $successfulMachinesCount++;
            $warningMessage = $this->summarizeMachineWarnings($documentWarnings, $salesDayWarning);

            $machine->forceFill([
                'last_validated_at' => now(),
                'last_error' => $warningMessage,
            ])->save();

            if ($warningMessage !== null) {
                $machineWarnings[] = [
                    'machine_id' => $machine->id,
                    'zs_client_id' => $machine->zs_client_id,
                    'store_id' => $machine->store_id,
                    'message' => $warningMessage,
                ];
            }
        }

        return [
            'rows' => $rows,
            'successful_machines_count' => $successfulMachinesCount,
            'failed_machines' => $failedMachines,
            'machine_warnings' => $machineWarnings,
            'salesday_records' => $salesDayRecords,
            'salesday_warnings' => $salesDayWarnings,
            'payment_documents' => $paymentDocuments,
        ];
    }

    /**
     * @param  array{start:CarbonImmutable,end:CarbonImmutable}  $syncRange
     * @return list<array<string, mixed>>
     */
    private function fetchDocuments(ClientZoneSoftMachine $machine, array $syncRange): array
    {
        $documents = [];
        $offset = 0;
        $limit = 250;

        do {
            $response = $this->apiClient->post(
                $machine->application,
                $machine->zs_client_id,
                'documents',
                'getDocumentsHeaders',
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

        return $documents;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private function fetchSalesFromDocument(ClientZoneSoftMachine $machine, array $document): array
    {
        $response = $this->apiClient->post(
            $machine->application,
            $machine->zs_client_id,
            'sales',
            'getInstancesFromDocument',
            'sale',
            [
                'doc' => (string) ($document['doc'] ?? ''),
                'serie' => (string) ($document['serie'] ?? ''),
                'numero' => (int) ($document['numero'] ?? 0),
            ],
        );

        return is_array($response['sale'] ?? null)
            ? array_values(array_filter($response['sale'], 'is_array'))
            : [];
    }

    /**
     * @param  array{start:CarbonImmutable,end:CarbonImmutable}  $syncRange
     * @return list<array<string, mixed>>
     */
    private function fetchSalesDayRecords(ClientZoneSoftMachine $machine, array $syncRange): array
    {
        $records = [];
        $offset = 0;
        $limit = 250;

        do {
            $response = $this->apiClient->post(
                $machine->application,
                $machine->zs_client_id,
                'salesday',
                'getInstances',
                'salesday',
                [
                    'condition' => $this->buildSalesDayCondition($machine, $syncRange),
                    'order' => 'data ASC, caixa ASC',
                    'limit' => $limit,
                    'offset' => $offset,
                ],
            );

            /** @var list<array<string, mixed>> $batch */
            $batch = is_array($response['salesday'] ?? null)
                ? array_values(array_filter($response['salesday'], 'is_array'))
                : [];

            $records = [...$records, ...$batch];
            $offset += count($batch);
        } while (count($batch) === $limit);

        return $records;
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
     * @param  array{start:CarbonImmutable,end:CarbonImmutable}  $syncRange
     */
    private function buildSalesDayCondition(ClientZoneSoftMachine $machine, array $syncRange): string
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
    private function summarizeMachineWarnings(array $documentWarnings, ?string $salesDayWarning = null): ?string
    {
        $messages = [];

        if ($documentWarnings !== []) {
            $messages[] = sprintf(
                'Falha parcial em %d documento(s). Primeiro erro: %s',
                count($documentWarnings),
                $documentWarnings[0],
            );
        }

        if ($salesDayWarning !== null) {
            $messages[] = $salesDayWarning;
        }

        return $messages === []
            ? null
            : implode(' ', $messages);
    }

    /**
     * @param  array<string, mixed>  $sale
     * @return array<string, mixed>
     */
    private function normalizeSaleRow(ClientZoneSoftMachine $machine, array $sale, int $rowNumber): array
    {
        $storeCode = isset($sale['loja']) ? (string) $sale['loja'] : (string) $machine->store_id;
        $storeName = $machine->store_label ?: 'Loja '.$storeCode;

        if (! empty($sale['posto'])) {
            $storeName .= ' - POS '.$sale['posto'];
        }

        return [
            'source_sheet' => 'zonesoft:'.$machine->zs_client_id,
            'source_row_number' => $rowNumber,
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
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $salesDay
     * @return array<string, mixed>
     */
    private function normalizeSalesDayRecord(ClientZoneSoftMachine $machine, array $salesDay): array
    {
        $storeCode = isset($salesDay['loja']) ? (string) $salesDay['loja'] : (string) $machine->store_id;
        $storeName = $machine->store_label ?: 'Loja '.$storeCode;
        $openState = $salesDay['Open'] ?? $salesDay['open'] ?? null;

        return [
            'machine_id' => $machine->id,
            'machine_client_id' => $machine->zs_client_id,
            'store_code' => $storeCode,
            'store_name' => $storeName,
            'sale_date' => $this->normalizeDate($salesDay['data'] ?? null),
            'cash_register_code' => isset($salesDay['caixa']) ? (string) $salesDay['caixa'] : null,
            'is_closed' => is_numeric($openState) ? (int) $openState === 1 : null,
            'opened_at' => $this->normalizeDateTime($salesDay['dataopen'] ?? null),
            'closed_at' => $this->normalizeDateTime($salesDay['dataclose'] ?? null),
            'opened_by' => isset($salesDay['opencx']) ? (string) $salesDay['opencx'] : null,
            'closed_by' => isset($salesDay['closecx']) ? (string) $salesDay['closecx'] : null,
            'vd' => $this->normalizeDecimal($salesDay['vd'] ?? null),
            'tk' => $this->normalizeDecimal($salesDay['tk'] ?? null),
            'fs' => $this->normalizeDecimal($salesDay['fs'] ?? null),
            'ft' => $this->normalizeDecimal($salesDay['ft'] ?? null),
            'nc' => $this->normalizeDecimal($salesDay['nc'] ?? null),
            'rc' => $this->normalizeDecimal($salesDay['rc'] ?? null),
            'ad' => $this->normalizeDecimal($salesDay['ad'] ?? null),
            'enc' => $this->normalizeDecimal($salesDay['enc'] ?? null),
            'movimento' => $this->normalizeDecimal($salesDay['movimento'] ?? null),
            'num' => $this->normalizeDecimal($salesDay['num'] ?? null),
            'deb' => $this->normalizeDecimal($salesDay['deb'] ?? null),
            'crd' => $this->normalizeDecimal($salesDay['crd'] ?? null),
            'chq' => $this->normalizeDecimal($salesDay['chq'] ?? null),
            'cartoes' => $this->normalizeDecimal($salesDay['cartoes'] ?? null),
            'etk' => $this->normalizeDecimal($salesDay['etk'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $referenceRow
     * @return array<string, mixed>
     */
    private function normalizePaymentDocument(
        ClientZoneSoftMachine $machine,
        array $document,
        array $referenceRow,
    ): array {
        return [
            'machine_id' => $machine->id,
            'machine_client_id' => $machine->zs_client_id,
            'store_code' => (string) ($referenceRow['store_code'] ?? $machine->store_id),
            'store_name' => (string) ($referenceRow['store_name'] ?? ($machine->store_label ?: 'Loja '.$machine->store_id)),
            'sale_date' => $this->normalizeDate($document['data'] ?? ($referenceRow['sale_date'] ?? null)),
            'sale_datetime' => $this->normalizeDateTime($document['datahora'] ?? ($referenceRow['sale_datetime'] ?? null)),
            'doc_type' => isset($document['doc']) ? (string) $document['doc'] : ($referenceRow['doc_type'] ?? null),
            'document_series' => isset($document['serie']) ? (string) $document['serie'] : ($referenceRow['document_series'] ?? null),
            'document_number' => isset($document['numero']) ? (string) $document['numero'] : ($referenceRow['document_number'] ?? null),
            'payment_code' => isset($document['pagamento']) ? (string) $document['pagamento'] : null,
            'payment_reference' => isset($document['referencia_pagamento']) ? (string) $document['referencia_pagamento'] : null,
            'paid' => isset($document['pago']) ? (int) $document['pago'] === 1 : null,
            'total' => $this->normalizeDecimal($document['total'] ?? null),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,message:string}>  $failedMachines
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,message:string}>  $machineWarnings
     * @param  list<array<string, mixed>>  $salesDayRecords
     * @param  list<array{machine_id:int,zs_client_id:string,store_id:int,message:string}>  $salesDayWarnings
     * @param  list<array<string, mixed>>  $paymentDocuments
     * @return array<string, mixed>
     */
    private function buildSummary(
        array $rows,
        int $successfulMachinesCount,
        array $failedMachines,
        array $machineWarnings,
        array $salesDayRecords,
        array $salesDayWarnings,
        array $paymentDocuments,
    ): array
    {
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
            'unique_stores' => count(array_unique(array_values(array_filter(array_column($rows, 'store_name'))))),
            'unique_products' => count(array_unique(array_values(array_filter(array_column($rows, 'product_code'))))),
            'value_total' => number_format($totals['value'], 4, '.', ''),
            'sales_total' => number_format($totals['total'], 4, '.', ''),
            'discount_total' => number_format($totals['discount'], 4, '.', ''),
            'quantity_total' => number_format($totals['quantity'], 4, '.', ''),
            'failed_machines' => $failedMachines,
            'machine_warnings' => $machineWarnings,
            'salesday_records' => $salesDayRecords,
            'salesday_warnings' => $salesDayWarnings,
            'payment_documents' => $paymentDocuments,
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
        ]);
    }

    /**
     * @return array{machine_id:int,zs_client_id:string,store_id:int,message:string}
     */
    private function markMachineFailure(ClientZoneSoftMachine $machine, string $message): array
    {
        $machine->forceFill([
            'last_validated_at' => now(),
            'last_error' => $message,
        ])->save();

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
