<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventReportRow;
use App\Services\EventReportAutoSyncService;
use App\Services\EventReportSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EventDashboardController extends Controller
{
    private const NON_SALES_DOCUMENT_TYPES = ['CM', 'ZT'];

    public function __construct(
        private readonly EventReportAutoSyncService $autoSync,
    ) {}

    public function show(Request $request, Event $event): Response
    {
        $client = $request->user()->client()->firstOrFail();

        abort_unless(
            $event->client_id === $client->id && $event->is_active,
            404,
        );

        return $this->renderDashboard(
            $request,
            $event,
            false,
            route('dashboard'),
            'Voltar ao dashboard',
        );
    }

    public function preview(Request $request, Event $event): Response
    {
        return $this->renderDashboard(
            $request,
            $event,
            true,
            route('admin.events.index'),
            'Voltar para eventos',
        );
    }

    private function renderDashboard(
        Request $request,
        Event $event,
        bool $previewMode,
        string $backUrl,
        string $backLabel,
    ): Response {
        app(EventReportSyncService::class)->markStaleProcessingImportsAsFailed($event);

        $event->load(['client', 'latestActiveReportImport', 'latestReportImport'])
            ->loadCount([
                'activeReportImports',
                'processingReportImports',
            ]);
        $event->client->loadCount([
            'zonesoftMachines as active_zonesoft_machines_count' => fn ($query) => $query->where('is_active', true),
        ]);
        $latestActiveImportSummary = is_array($event->latestActiveReportImport?->summary)
            ? $event->latestActiveReportImport->summary
            : [];

        $filters = $this->normalizeFilters($request);
        $makeBaseRowsQuery = fn (): Builder => $this->applySalesDocumentScope(
            EventReportRow::query()
                ->where('event_id', $event->id)
                ->fromActiveImports(),
        );
        $makeFilteredRowsQuery = fn (): Builder => $this->applyFilters($makeBaseRowsQuery(), $filters);
        $makeProductRowsQuery = fn (): Builder => $this->applyProductDocumentScope(
            EventReportRow::query()
                ->where('event_id', $event->id)
                ->fromActiveImports(),
        );
        $makeFilteredProductRowsQuery = fn (): Builder => $this->applyFilters($makeProductRowsQuery(), $filters);

        /** @var array<int, array<string, mixed>>|null $documentTypes */
        $documentTypes = null;
        $resolveDocumentTypes = function () use (&$documentTypes, $makeFilteredRowsQuery): array {
            if ($documentTypes === null) {
                $documentTypes = $this->buildDocumentTypes($makeFilteredRowsQuery());
            }

            return $documentTypes;
        };

        $rows = null;
        $resolveRows = function () use (&$rows, $makeFilteredRowsQuery) {
            if ($rows === null) {
                $rows = $makeFilteredRowsQuery()
                    ->orderByDesc('sale_datetime')
                    ->orderByDesc('sale_date')
                    ->orderByDesc('source_row_number')
                    ->paginate(15)
                    ->withQueryString();
            }

            return $rows;
        };

        return Inertia::render('Events/Dashboard', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'event_date' => $event->event_date->toISOString(),
                'client_name' => $event->client->name,
                'client_business_name' => $event->client->business_name,
                'active_imports_count' => (int) $event->active_report_imports_count,
                'processing_imports_count' => (int) $event->processing_report_imports_count,
                'last_synced_at' => $event->latestActiveReportImport?->imported_at?->toISOString(),
            ],
            'integration' => [
                'source' => 'ZoneSoft API',
                'configured_client_ids_count' => (int) ($event->client->active_zonesoft_machines_count ?? 0),
                'machines_count' => (int) ($event->latestActiveReportImport?->summary['machines_count'] ?? 0),
                'last_synced_at' => $event->latestActiveReportImport?->imported_at?->toISOString(),
            ],
            'autoSync' => $this->autoSync->status($event),
            'filters' => $filters,
            'filterOptions' => fn (): array => [
                'barGroups' => $this->buildBarGroupOptions($makeBaseRowsQuery()),
                'stores' => $this->buildStoreOptions($makeBaseRowsQuery()),
                'products' => $this->buildProductOptions($makeBaseRowsQuery()),
            ],
            'summary' => fn (): array => $this->buildSummary(
                $makeBaseRowsQuery(),
                $makeFilteredRowsQuery(),
                (int) $event->processing_report_imports_count,
                $event->latestActiveReportImport?->imported_at?->toISOString(),
                (int) ($event->latestActiveReportImport?->summary['machines_count'] ?? 0),
                $resolveDocumentTypes(),
            ),
            'barGroups' => fn (): array => $this->buildBarGroups($makeFilteredRowsQuery()),
            'zoneDevices' => fn (): array => $this->buildZoneDevices($makeFilteredRowsQuery()),
            'topStores' => fn (): array => $this->buildTopStores($makeFilteredRowsQuery()),
            'topProducts' => fn (): array => $this->buildTopProducts($makeFilteredProductRowsQuery()),
            'productBreakdowns' => fn (): array => $this->buildProductBreakdowns($makeFilteredProductRowsQuery()),
            'dailySales' => fn (): array => $this->buildDailyFinancialTotals(
                $latestActiveImportSummary,
                $filters,
                $makeFilteredProductRowsQuery(),
            ),
            'documentTypes' => fn (): array => $resolveDocumentTypes(),
            'paymentSummary' => fn (): array => $this->buildPaymentSummary($latestActiveImportSummary, $filters),
            'reconciliation' => fn (): array => $this->buildPaymentReconciliation(
                $latestActiveImportSummary,
                $makeFilteredRowsQuery(),
                $filters,
            ),
            'comparison' => fn (): array => $this->buildComparison(
                $event,
                $makeBaseRowsQuery(),
                $latestActiveImportSummary,
            ),
            'rows' => fn () => $resolveRows()->getCollection()->map(fn (EventReportRow $row): array => [
                'id' => $row->id,
                'store_code' => $row->store_code,
                'store_name' => $row->store_name,
                'sale_date' => $row->sale_date?->toDateString(),
                'sale_datetime' => $row->sale_datetime?->toISOString(),
                'doc_type' => $row->doc_type,
                'document_series' => $row->document_series,
                'document_number' => $row->document_number,
                'product_code' => $row->product_code,
                'description' => $row->description,
                'quantity' => (float) ($row->quantity ?? 0),
                'value' => (float) ($row->value ?? 0),
                'discount' => (float) ($row->discount ?? 0),
                'total' => (float) ($row->total ?? 0),
            ])->values(),
            'pagination' => fn (): array => [
                'current_page' => $resolveRows()->currentPage(),
                'last_page' => $resolveRows()->lastPage(),
                'per_page' => $resolveRows()->perPage(),
                'total' => $resolveRows()->total(),
                'from' => $resolveRows()->firstItem(),
                'to' => $resolveRows()->lastItem(),
                'prev_page_url' => $resolveRows()->previousPageUrl(),
                'next_page_url' => $resolveRows()->nextPageUrl(),
            ],
            'previewMode' => $previewMode,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ]);
    }

    /**
     * @return array{bar_group: string, store: string, product: string, date_from: string, date_to: string, total_min: string, total_max: string}
     */
    private function normalizeFilters(Request $request): array
    {
        $validated = $request->validate([
            'bar_group' => ['nullable', 'string', 'max:255'],
            'store' => ['nullable', 'string', 'max:255'],
            'product' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'total_min' => ['nullable', 'string', 'max:40'],
            'total_max' => ['nullable', 'string', 'max:40'],
        ]);

        return [
            'bar_group' => trim((string) ($validated['bar_group'] ?? '')),
            'store' => trim((string) ($validated['store'] ?? '')),
            'product' => trim((string) ($validated['product'] ?? '')),
            'date_from' => trim((string) ($validated['date_from'] ?? '')),
            'date_to' => trim((string) ($validated['date_to'] ?? '')),
            'total_min' => $this->normalizeDecimalString($validated['total_min'] ?? null),
            'total_max' => $this->normalizeDecimalString($validated['total_max'] ?? null),
        ];
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        if ($filters['bar_group'] !== '') {
            $this->applyBarGroupFilter($query, $filters['bar_group']);
        }

        if ($filters['store'] !== '') {
            $query->where('store_name', $filters['store']);
        }

        if ($filters['product'] !== '') {
            $query->where('product_code', $filters['product']);
        }

        if ($filters['date_from'] !== '') {
            $query->whereDate('sale_date', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $query->whereDate('sale_date', '<=', $filters['date_to']);
        }

        if ($filters['total_min'] !== '') {
            $query->where('total', '>=', (float) $filters['total_min']);
        }

        if ($filters['total_max'] !== '') {
            $query->where('total', '<=', (float) $filters['total_max']);
        }

        return $query;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBarGroupOptions(Builder $query): array
    {
        return collect($this->buildBarGroups($query))
            ->map(fn (array $group): array => [
                'value' => (string) $group['label'],
                'label' => (string) $group['label'],
                'rows_count' => (int) $group['rows_count'],
            ])
            ->sortBy(fn (array $group): string => Str::lower($group['label']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildStoreOptions(Builder $query): array
    {
        return $query
            ->select('store_name')
            ->selectRaw('COUNT(*) as rows_count')
            ->whereNotNull('store_name')
            ->where('store_name', '!=', '')
            ->groupBy('store_name')
            ->orderBy('store_name')
            ->get()
            ->map(fn (EventReportRow $row): array => [
                'value' => (string) $row->store_name,
                'label' => (string) $row->store_name,
                'rows_count' => (int) $row->rows_count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildProductOptions(Builder $query): array
    {
        return $query
            ->select('product_code', 'description')
            ->selectRaw('COUNT(*) as rows_count')
            ->whereNotNull('product_code')
            ->where('product_code', '!=', '')
            ->groupBy('product_code', 'description')
            ->orderBy('description')
            ->orderBy('product_code')
            ->get()
            ->map(fn (EventReportRow $row): array => [
                'value' => (string) $row->product_code,
                'label' => trim(sprintf(
                    '%s%s',
                    (string) ($row->description ?: 'Produto sem descricao'),
                    $row->product_code ? " ({$row->product_code})" : '',
                )),
                'rows_count' => (int) $row->rows_count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummary(
        Builder $baseRowsQuery,
        Builder $filteredRowsQuery,
        int $processingImportsCount,
        ?string $lastSyncedAt,
        int $machinesCount,
        array $documentTypes,
    ): array {
        $filteredRowsCount = (clone $filteredRowsQuery)->count();
        $totalSales = (float) ((clone $filteredRowsQuery)->sum('total') ?? 0);
        $ticketsCount = array_sum(array_map(
            fn (array $documentType): int => (int) ($documentType['tickets_count'] ?? 0),
            $documentTypes,
        ));

        return [
            'processing_imports_count' => $processingImportsCount,
            'total_rows' => (int) ((clone $baseRowsQuery)->count()),
            'filtered_rows' => $filteredRowsCount,
            'bar_groups_count' => count($this->buildBarGroups(clone $filteredRowsQuery)),
            'total_sales' => $totalSales,
            'total_value' => (float) ((clone $filteredRowsQuery)->sum('value') ?? 0),
            'total_discount' => (float) ((clone $filteredRowsQuery)->sum('discount') ?? 0),
            'total_quantity' => (float) ((clone $filteredRowsQuery)->sum('quantity') ?? 0),
            'stores_count' => (int) ((clone $filteredRowsQuery)
                ->whereNotNull('store_name')
                ->where('store_name', '!=', '')
                ->distinct()
                ->count('store_name')),
            'tickets_count' => $ticketsCount,
            'products_count' => (int) ((clone $filteredRowsQuery)
                ->whereNotNull('product_code')
                ->where('product_code', '!=', '')
                ->distinct()
                ->count('product_code')),
            'document_types_count' => count($documentTypes),
            'average_ticket' => $ticketsCount > 0
                ? round($totalSales / $ticketsCount, 4)
                : 0,
            'last_synced_at' => $lastSyncedAt,
            'machines_count' => $machinesCount,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildBarGroups(Builder $query): array
    {
        /** @var \Illuminate\Support\Collection<int, EventReportRow> $rows */
        $rows = $query
            ->get(['store_name', 'store_code', 'quantity', 'total']);

        return $rows
            ->groupBy(fn (EventReportRow $row): string => $this->resolveBarGroupLabel($row->store_name))
            ->map(function ($groupRows, string $label): array {
                $members = $groupRows
                    ->pluck('store_name')
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                return [
                    'label' => $label,
                    'stores_count' => count($members),
                    'members' => $members,
                    'rows_count' => $groupRows->count(),
                    'quantity_total' => round((float) $groupRows->sum('quantity'), 4),
                    'sales_total' => round((float) $groupRows->sum('total'), 4),
                ];
            })
            ->sortByDesc('sales_total')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTopStores(Builder $query): array
    {
        return $query
            ->select('store_name', 'store_code')
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity_total')
            ->selectRaw('COALESCE(SUM(total), 0) as sales_total')
            ->groupBy('store_name', 'store_code')
            ->orderByDesc('sales_total')
            ->limit(5)
            ->get()
            ->map(fn (EventReportRow $row): array => [
                'label' => $row->store_name ?: 'Sem loja',
                'code' => $row->store_code,
                'rows_count' => (int) $row->rows_count,
                'quantity_total' => (float) ($row->quantity_total ?? 0),
                'sales_total' => (float) ($row->sales_total ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTopProducts(Builder $query): array
    {
        $productCode = "CASE WHEN doc_type = 'ZT' THEN 'ZT-CARD' ELSE product_code END";
        $productDescription = "CASE WHEN doc_type = 'ZT' THEN 'Contactless' ELSE description END";

        return $query
            ->selectRaw("{$productCode} as product_code")
            ->selectRaw("{$productDescription} as description")
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity_total')
            ->selectRaw('COALESCE(SUM(total), 0) as sales_total')
            ->groupByRaw("{$productCode}, {$productDescription}")
            ->orderByDesc('quantity_total')
            ->orderByDesc('sales_total')
            ->limit(12)
            ->get()
            ->map(fn (EventReportRow $row): array => [
                'label' => $row->description ?: 'Produto sem descricao',
                'code' => $row->product_code,
                'rows_count' => (int) $row->rows_count,
                'quantity_total' => (float) ($row->quantity_total ?? 0),
                'sales_total' => (float) ($row->sales_total ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{total: array<int, array<string, mixed>>, days: array<int, array<string, mixed>>}
     */
    private function buildProductBreakdowns(Builder $query): array
    {
        $dates = (clone $query)
            ->select('sale_date')
            ->whereNotNull('sale_date')
            ->distinct()
            ->orderBy('sale_date')
            ->get()
            ->map(fn (EventReportRow $row) => $row->sale_date)
            ->filter();

        return [
            'total' => $this->buildTopProducts(clone $query),
            'days' => $dates
                ->map(fn ($date): array => [
                    'date' => $date->toDateString(),
                    'label' => $date->locale('pt_PT')->translatedFormat('d M'),
                    'items' => $this->buildTopProducts(
                        (clone $query)->whereDate('sale_date', $date->toDateString()),
                    ),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDailySales(Builder $query): array
    {
        /** @var Collection<int, EventReportRow> $rows */
        $rows = $query
            ->whereNotNull('sale_date')
            ->get([
                'id',
                'sale_date',
                'doc_type',
                'document_series',
                'document_number',
                'store_code',
                'quantity',
                'total',
            ]);

        return $rows
            ->groupBy(fn (EventReportRow $row): string => $row->sale_date->toDateString())
            ->map(function (Collection $dayRows, string $date): array {
                $day = $dayRows->first()?->sale_date;

                return [
                    'date' => $date,
                    'label' => $day?->locale('pt_PT')->translatedFormat('d M') ?? $date,
                    'sales_total' => round((float) $dayRows->sum('total'), 4),
                    'quantity_total' => round((float) $dayRows->sum('quantity'), 4),
                    'tickets_count' => $dayRows
                        ->map(fn (EventReportRow $row): string => $this->buildTicketKey($row))
                        ->unique()
                        ->count(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $latestImportSummary
     * @param  array{bar_group: string, store: string, product: string, date_from: string, date_to: string, total_min: string, total_max: string}  $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildDailyFinancialTotals(
        array $latestImportSummary,
        array $filters,
        Builder $fallbackRowsQuery,
    ): array {
        /** @var Collection<int, array<string, mixed>> $documents */
        $documents = collect($latestImportSummary['payment_documents'] ?? [])
            ->filter(fn (mixed $document): bool => is_array($document))
            ->values();
        $documents = $this->filterPaymentDocuments($documents, $filters)
            ->filter(fn (array $document): bool => $this->isSalesPaymentDocument($document))
            ->filter(fn (array $document): bool => filled($document['sale_date'] ?? null))
            ->values();

        if ($documents->isEmpty()) {
            return $this->buildDailySales($fallbackRowsQuery);
        }

        return $documents
            ->groupBy(fn (array $document): string => (string) $document['sale_date'])
            ->map(function (Collection $dayDocuments, string $date): array {
                $day = CarbonImmutable::parse($date);

                return [
                    'date' => $date,
                    'label' => $day->locale('pt_PT')->translatedFormat('d M'),
                    'sales_total' => round((float) $dayDocuments->sum(
                        fn (array $document): float => (float) ($document['total'] ?? 0),
                    ), 4),
                    'quantity_total' => 0.0,
                    'tickets_count' => $dayDocuments
                        ->map(fn (array $document): string => implode('|', [
                            $document['machine_client_id'] ?? '',
                            $document['doc_type'] ?? '',
                            $document['document_series'] ?? '',
                            $document['document_number'] ?? '',
                        ]))
                        ->unique()
                        ->count(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildZoneDevices(Builder $query): array
    {
        /** @var Collection<int, EventReportRow> $rows */
        $rows = $query
            ->select('store_name', 'store_code')
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity_total')
            ->selectRaw('COALESCE(SUM(total), 0) as sales_total')
            ->groupBy('store_name', 'store_code')
            ->orderByDesc('sales_total')
            ->get();

        return $rows
            ->groupBy(fn (EventReportRow $row): string => $this->resolveBarGroupLabel($row->store_name))
            ->map(function (Collection $zoneRows, string $label): array {
                $items = $zoneRows
                    ->map(fn (EventReportRow $row): array => [
                        'label' => $row->store_name ?: 'Sem loja',
                        'code' => $row->store_code,
                        'rows_count' => (int) ($row->rows_count ?? 0),
                        'quantity_total' => (float) ($row->quantity_total ?? 0),
                        'sales_total' => (float) ($row->sales_total ?? 0),
                    ])
                    ->sortByDesc('sales_total')
                    ->values();

                return [
                    'label' => $label,
                    'devices_count' => $items->count(),
                    'total_sales' => round((float) $items->sum('sales_total'), 4),
                    'items' => $items->all(),
                ];
            })
            ->sortByDesc('total_sales')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDocumentTypes(Builder $query): array
    {
        /** @var Collection<int, EventReportRow> $rows */
        $rows = $query
            ->get([
                'id',
                'doc_type',
                'document_series',
                'document_number',
                'store_code',
                'quantity',
                'total',
            ]);

        return $rows
            ->groupBy(fn (EventReportRow $row): string => filled($row->doc_type) ? (string) $row->doc_type : 'Sem tipo')
            ->map(function (Collection $groupRows, string $label): array {
                return [
                    'label' => $label,
                    'code' => $label === 'Sem tipo' ? null : $label,
                    'tickets_count' => $groupRows
                        ->map(fn (EventReportRow $row): string => $this->buildTicketKey($row))
                        ->unique()
                        ->count(),
                    'rows_count' => $groupRows->count(),
                    'quantity_total' => round((float) $groupRows->sum('quantity'), 4),
                    'sales_total' => round((float) $groupRows->sum('total'), 4),
                ];
            })
            ->sortByDesc('sales_total')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $latestImportSummary
     * @param  array{bar_group: string, store: string, product: string, date_from: string, date_to: string, total_min: string, total_max: string}  $filters
     * @return array<string, mixed>
     */
    private function buildPaymentSummary(array $latestImportSummary, array $filters): array
    {
        /** @var Collection<int, array<string, mixed>> $documents */
        $documents = collect($latestImportSummary['payment_documents'] ?? [])
            ->filter(fn (mixed $document): bool => is_array($document))
            ->values();

        $documents = $this->filterPaymentDocuments($documents, $filters);
        $hasProductSpecificFilters = $filters['product'] !== ''
            || $filters['total_min'] !== ''
            || $filters['total_max'] !== '';

        if ($documents->isEmpty()) {
            return [
                'available' => false,
                'source' => 'unavailable',
                'documents_count' => 0,
                'movement_documents_count' => 0,
                'multibanco' => 0.0,
                'cash' => 0.0,
                'zticket' => 0.0,
                'other' => 0.0,
                'sales_total' => 0.0,
                'total_without_zt' => 0.0,
                'total_with_zt' => 0.0,
                'movement_total' => 0.0,
                'other_movements' => 0.0,
                'top_up_documents_count' => 0,
                'top_up_loaded' => 0.0,
                'top_up_spent' => 0.0,
                'top_up_remaining' => 0.0,
                'scope_note' => $hasProductSpecificFilters
                    ? 'Pagamentos indisponiveis nos documentos sincronizados. Este bloco nao usa mais fallback externo.'
                    : 'Pagamentos indisponiveis porque a ultima sincronizacao nao guardou os documentos de pagamento.',
            ];
        }

        $totals = [
            'multibanco' => 0.0,
            'cash' => 0.0,
            'zticket' => 0.0,
            'other' => 0.0,
            'top_up_loaded' => 0.0,
            'top_up_spent' => 0.0,
            'other_movements' => 0.0,
        ];
        $salesDocumentsCount = 0;
        $topUpDocumentsCount = 0;

        foreach ($documents as $document) {
            $amount = (float) ($document['total'] ?? 0);
            $category = $this->resolvePaymentCategory((string) ($document['payment_code'] ?? ''));
            $isTopUp = $this->isTopUpDocument($document);

            if ($isTopUp) {
                $totals['top_up_loaded'] += $amount;
                $topUpDocumentsCount++;

                continue;
            }

            if (! $this->isSalesPaymentDocument($document)) {
                $totals['other_movements'] += $amount;

                continue;
            }

            $totals[$category] += $amount;
            $salesDocumentsCount++;

            if ($category === 'zticket') {
                $totals['top_up_spent'] += $amount;
            }
        }

        $salesTotal = $totals['multibanco']
            + $totals['cash']
            + $totals['zticket']
            + $totals['other'];
        $movementTotal = $salesTotal
            + $totals['top_up_loaded']
            + $totals['other_movements'];
        $totalWithZt = $salesTotal + $totals['top_up_loaded'];

        return [
            'available' => true,
            'source' => 'documents_headers',
            'documents_count' => $salesDocumentsCount,
            'movement_documents_count' => $documents->count(),
            'multibanco' => round($totals['multibanco'], 4),
            'cash' => round($totals['cash'], 4),
            'zticket' => round($totals['zticket'], 4),
            'other' => round($totals['other'], 4),
            'sales_total' => round($salesTotal, 4),
            'total_without_zt' => round($salesTotal, 4),
            'total_with_zt' => round($totalWithZt, 4),
            'movement_total' => round($movementTotal, 4),
            'other_movements' => round($totals['other_movements'], 4),
            'top_up_documents_count' => $topUpDocumentsCount,
            'top_up_loaded' => round($totals['top_up_loaded'], 4),
            'top_up_spent' => round($totals['top_up_spent'], 4),
            'top_up_remaining' => round(max($totals['top_up_loaded'] - $totals['top_up_spent'], 0), 4),
            'scope_note' => $hasProductSpecificFilters
                ? 'Pagamentos de vendas calculados pelos documentos sincronizados. Filtros de produto e total nao alteram este bloco.'
                : 'Faturacao, carregamentos e outros movimentos sao apresentados separadamente para alinhar com o ZPOS.',
        ];
    }

    /**
     * @param  array<string, mixed>  $latestImportSummary
     * @param  array{bar_group: string, store: string, product: string, date_from: string, date_to: string, total_min: string, total_max: string}  $filters
     * @return array<string, mixed>
     */
    private function buildPaymentReconciliation(
        array $latestImportSummary,
        Builder $rowsQuery,
        array $filters,
    ): array {
        /** @var Collection<int, array<string, mixed>> $documents */
        $documents = collect($latestImportSummary['payment_documents'] ?? [])
            ->filter(fn (mixed $document): bool => is_array($document))
            ->values();
        $documents = $this->filterPaymentDocuments($documents, $filters)
            ->filter(fn (array $document): bool => $this->isSalesPaymentDocument($document))
            ->values();

        $hasProductSpecificFilters = $filters['product'] !== ''
            || $filters['total_min'] !== ''
            || $filters['total_max'] !== '';

        $items = [];

        foreach ((clone $rowsQuery)
            ->select('store_code', 'store_name')
            ->selectRaw('COALESCE(SUM(total), 0) as sales_total')
            ->groupBy('store_code', 'store_name')
            ->get() as $row) {
            $key = $this->buildStoreKey($row->store_code, $row->store_name);

            if (! isset($items[$key])) {
                $items[$key] = $this->emptyReconciliationItem(
                    $row->store_code,
                    $row->store_name,
                );
            }

            $items[$key]['sales_total'] += (float) ($row->sales_total ?? 0);
        }

        foreach ($documents as $document) {
            $storeCode = isset($document['store_code']) ? (string) $document['store_code'] : null;
            $storeName = isset($document['store_name']) ? (string) $document['store_name'] : null;
            $key = $this->buildStoreKey($storeCode, $storeName);

            if (! isset($items[$key])) {
                $items[$key] = $this->emptyReconciliationItem($storeCode, $storeName);
            }

            $category = $this->resolvePaymentCategory((string) ($document['payment_code'] ?? ''));
            $items[$key][$category] += (float) ($document['total'] ?? 0);
            $items[$key]['documents_count']++;
        }

        $normalizedItems = collect($items)
            ->map(function (array $item) use ($hasProductSpecificFilters): array {
                $item['payments_total'] = round(
                    (float) $item['multibanco']
                    + (float) $item['cash']
                    + (float) $item['zticket']
                    + (float) $item['other'],
                    4,
                );
                $item['sales_total'] = round((float) $item['sales_total'], 4);
                $item['difference'] = $hasProductSpecificFilters
                    ? null
                    : round($item['payments_total'] - $item['sales_total'], 4);

                foreach (['multibanco', 'cash', 'zticket', 'other'] as $category) {
                    $item[$category] = round((float) $item[$category], 4);
                }

                return $item;
            })
            ->sortByDesc('payments_total')
            ->values();

        return [
            'available' => $documents->isNotEmpty(),
            'documents_count' => $documents->count(),
            'comparable' => ! $hasProductSpecificFilters,
            'scope_note' => $hasProductSpecificFilters
                ? 'Os pagamentos nao podem ser repartidos por produto ou valor de linha. A diferenca fica oculta com estes filtros.'
                : 'Conferencia entre vendas e documentos de pagamento devolvidos pela ZoneSoft.',
            'totals' => [
                'multibanco' => round((float) $normalizedItems->sum('multibanco'), 4),
                'cash' => round((float) $normalizedItems->sum('cash'), 4),
                'zticket' => round((float) $normalizedItems->sum('zticket'), 4),
                'other' => round((float) $normalizedItems->sum('other'), 4),
                'payments_total' => round((float) $normalizedItems->sum('payments_total'), 4),
                'sales_total' => round((float) $normalizedItems->sum('sales_total'), 4),
                'difference' => $hasProductSpecificFilters
                    ? null
                    : round((float) $normalizedItems->sum('difference'), 4),
            ],
            'items' => $normalizedItems->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyReconciliationItem(?string $storeCode, ?string $storeName): array
    {
        return [
            'store_code' => $storeCode,
            'store_name' => filled($storeName) ? $storeName : 'Sem device',
            'documents_count' => 0,
            'multibanco' => 0.0,
            'cash' => 0.0,
            'zticket' => 0.0,
            'other' => 0.0,
            'payments_total' => 0.0,
            'sales_total' => 0.0,
            'difference' => 0.0,
        ];
    }

    private function buildStoreKey(?string $storeCode, ?string $storeName): string
    {
        if (filled($storeCode)) {
            return 'code:'.trim((string) $storeCode);
        }

        return 'name:'.Str::lower(trim((string) $storeName));
    }

    /**
     * @param  array<string, mixed>  $currentImportSummary
     * @return array<string, mixed>
     */
    private function buildComparison(
        Event $event,
        Builder $currentRowsQuery,
        array $currentImportSummary,
    ): array {
        $previousEvent = Event::query()
            ->where('client_id', $event->client_id)
            ->where('id', '!=', $event->id)
            ->where('event_date', '<', $event->event_date)
            ->whereHas('activeReportImports')
            ->with('latestActiveReportImport')
            ->orderByDesc('event_date')
            ->first();

        if (! $previousEvent) {
            $previousEvent = Event::query()
                ->where('client_id', $event->client_id)
                ->where('id', '!=', $event->id)
                ->whereHas('activeReportImports')
                ->with('latestActiveReportImport')
                ->orderByDesc('event_date')
                ->first();
        }

        if (! $previousEvent || ! $previousEvent->latestActiveReportImport) {
            return [
                'available' => false,
                'message' => 'Ainda nao existe outro evento sincronizado para comparar.',
            ];
        }

        $current = $this->buildComparisonSnapshot(
            $event,
            clone $currentRowsQuery,
            $currentImportSummary,
        );
        $previous = $this->buildComparisonSnapshot(
            $previousEvent,
            $this->applySalesDocumentScope(
                EventReportRow::query()
                    ->where('event_id', $previousEvent->id)
                    ->fromActiveImports(),
            ),
            is_array($previousEvent->latestActiveReportImport->summary)
                ? $previousEvent->latestActiveReportImport->summary
                : [],
        );

        $metricDefinitions = [
            ['key' => 'total_sales', 'label' => 'Total faturado', 'format' => 'currency'],
            ['key' => 'machines_count', 'label' => 'Devices', 'format' => 'number'],
            ['key' => 'zones_count', 'label' => 'Zonas', 'format' => 'number'],
            ['key' => 'average_ticket', 'label' => 'Ticket medio', 'format' => 'currency'],
            ['key' => 'average_per_device', 'label' => 'Media por device', 'format' => 'currency'],
        ];
        $paymentDefinitions = [
            ['key' => 'multibanco', 'label' => 'Multibanco'],
            ['key' => 'zticket', 'label' => 'ZT - Card'],
            ['key' => 'cash', 'label' => 'Dinheiro'],
            ['key' => 'other', 'label' => 'Outros'],
        ];

        return [
            'available' => true,
            'current' => $current,
            'previous' => $previous,
            'total_variation' => $this->calculateVariation(
                (float) $current['total_sales'],
                (float) $previous['total_sales'],
            ),
            'metrics' => collect($metricDefinitions)
                ->map(fn (array $definition): array => [
                    ...$definition,
                    'current' => (float) $current[$definition['key']],
                    'previous' => (float) $previous[$definition['key']],
                    'variation' => $this->calculateVariation(
                        (float) $current[$definition['key']],
                        (float) $previous[$definition['key']],
                    ),
                ])
                ->all(),
            'payments' => collect($paymentDefinitions)
                ->map(fn (array $definition): array => [
                    ...$definition,
                    'current' => (float) $current['payments'][$definition['key']],
                    'previous' => (float) $previous['payments'][$definition['key']],
                    'variation' => $this->calculateVariation(
                        (float) $current['payments'][$definition['key']],
                        (float) $previous['payments'][$definition['key']],
                    ),
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $importSummary
     * @return array<string, mixed>
     */
    private function buildComparisonSnapshot(Event $event, Builder $rowsQuery, array $importSummary): array
    {
        $documentTypes = $this->buildDocumentTypes(clone $rowsQuery);
        $summary = $this->buildSummary(
            clone $rowsQuery,
            clone $rowsQuery,
            0,
            $event->latestActiveReportImport?->imported_at?->toISOString(),
            (int) ($importSummary['machines_count'] ?? 0),
            $documentTypes,
        );
        $payments = $this->buildPaymentSummary($importSummary, $this->emptyFilters());
        $machinesCount = (int) $summary['machines_count'];

        return [
            'event_id' => $event->id,
            'title' => $event->title,
            'event_date' => $event->event_date->toISOString(),
            'total_sales' => (float) $summary['total_sales'],
            'machines_count' => $machinesCount,
            'zones_count' => (int) $summary['bar_groups_count'],
            'tickets_count' => (int) $summary['tickets_count'],
            'average_ticket' => (float) $summary['average_ticket'],
            'average_per_device' => $machinesCount > 0
                ? round((float) $summary['total_sales'] / $machinesCount, 4)
                : 0.0,
            'payments' => [
                'multibanco' => (float) $payments['multibanco'],
                'cash' => (float) $payments['cash'],
                'zticket' => (float) $payments['zticket'],
                'other' => (float) $payments['other'],
            ],
        ];
    }

    /**
     * @return array{bar_group: string, store: string, product: string, date_from: string, date_to: string, total_min: string, total_max: string}
     */
    private function emptyFilters(): array
    {
        return [
            'bar_group' => '',
            'store' => '',
            'product' => '',
            'date_from' => '',
            'date_to' => '',
            'total_min' => '',
            'total_max' => '',
        ];
    }

    private function calculateVariation(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.0001) {
            return null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $documents
     * @param  array{bar_group: string, store: string, product: string, date_from: string, date_to: string, total_min: string, total_max: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filterPaymentDocuments(Collection $documents, array $filters): Collection
    {
        if ($filters['bar_group'] !== '') {
            $documents = $documents->filter(function (array $document) use ($filters): bool {
                $storeName = is_string($document['store_name'] ?? null)
                    ? $document['store_name']
                    : null;

                return $this->resolveBarGroupLabel($storeName) === $filters['bar_group'];
            })->values();
        }

        if ($filters['store'] !== '') {
            $documents = $documents->filter(
                fn (array $document): bool => ($document['store_name'] ?? null) === $filters['store'],
            )->values();
        }

        if ($filters['date_from'] !== '') {
            $documents = $documents->filter(function (array $document) use ($filters): bool {
                $saleDate = $document['sale_date'] ?? null;

                return ! is_string($saleDate) || $saleDate >= $filters['date_from'];
            })->values();
        }

        if ($filters['date_to'] !== '') {
            $documents = $documents->filter(function (array $document) use ($filters): bool {
                $saleDate = $document['sale_date'] ?? null;

                return ! is_string($saleDate) || $saleDate <= $filters['date_to'];
            })->values();
        }

        return $documents;
    }

    private function resolvePaymentCategory(string $paymentCode): string
    {
        return match ($paymentCode) {
            '1' => 'cash',
            '3', '4', '20' => 'multibanco',
            '10', '12', '14', '56' => 'zticket',
            default => 'other',
        };
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function isTopUpDocument(array $document): bool
    {
        $docType = Str::upper(trim((string) ($document['doc_type'] ?? '')));

        if ($docType !== '') {
            return $docType === 'ZT';
        }

        $storeName = trim((string) ($document['store_name'] ?? ''));

        return preg_match('/^(top\s*up|bc\s*top)\b/i', $storeName) === 1;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function isSalesPaymentDocument(array $document): bool
    {
        if ($this->isTopUpDocument($document)) {
            return false;
        }

        $docType = Str::upper(trim((string) ($document['doc_type'] ?? '')));

        return ! in_array($docType, self::NON_SALES_DOCUMENT_TYPES, true);
    }

    private function applySalesDocumentScope(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder
                ->whereNull('doc_type')
                ->orWhereNotIn('doc_type', self::NON_SALES_DOCUMENT_TYPES);
        });
    }

    private function applyProductDocumentScope(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder
                ->whereNull('doc_type')
                ->orWhere('doc_type', '!=', 'CM');
        });
    }

    private function buildTicketKey(EventReportRow $row): string
    {
        $parts = array_filter([
            $row->doc_type,
            $row->document_series,
            $row->document_number,
            $row->store_code,
        ], fn (mixed $value): bool => $value !== null && trim((string) $value) !== '');

        if ($parts === []) {
            return 'row:'.$row->id;
        }

        return implode('|', $parts);
    }

    private function normalizeDecimalString(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        $normalized = str_replace(' ', '', trim($value));

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            if (strrpos($normalized, ',') > strrpos($normalized, '.')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?? '';

        if ($normalized === '' || ! is_numeric($normalized)) {
            return '';
        }

        return (string) $normalized;
    }

    private function applyBarGroupFilter(Builder $query, string $barGroup): void
    {
        $normalizedBarGroup = trim($barGroup);

        if ($normalizedBarGroup === 'Sem loja') {
            $query->where(function (Builder $builder): void {
                $builder
                    ->whereNull('store_name')
                    ->orWhereRaw("TRIM(COALESCE(store_name, '')) = ''");
            });

            return;
        }

        if (preg_match('/^bar\s+\d+$/i', $normalizedBarGroup) === 1) {
            $normalizedLower = Str::lower($normalizedBarGroup);

            $query->where(function (Builder $builder) use ($normalizedLower): void {
                $builder
                    ->whereRaw('LOWER(COALESCE(store_name, \'\')) = ?', [$normalizedLower])
                    ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', [$normalizedLower.' %'])
                    ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', [$normalizedLower.'-%']);
            });

            return;
        }

        if (in_array(Str::lower($normalizedBarGroup), ['vip', 'top up', 'bengaleiro', 'bilheteira'], true)) {
            $normalizedLower = Str::lower($normalizedBarGroup);

            $query->where(function (Builder $builder) use ($normalizedLower): void {
                if ($normalizedLower === 'top up') {
                    $builder
                        ->whereRaw('LOWER(COALESCE(store_name, \'\')) = ?', [$normalizedLower])
                        ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', [$normalizedLower.' %'])
                        ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', [$normalizedLower.'-%'])
                        ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', ['bc top%']);

                    return;
                }

                $builder
                    ->whereRaw('LOWER(COALESCE(store_name, \'\')) = ?', [$normalizedLower])
                    ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', [$normalizedLower.' %'])
                    ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', [$normalizedLower.'-%']);
            });

            return;
        }

        $query->whereRaw('TRIM(COALESCE(store_name, \'\')) = ?', [$normalizedBarGroup]);
    }

    private function resolveBarGroupLabel(?string $storeName): string
    {
        if ($storeName === null || trim($storeName) === '') {
            return 'Sem loja';
        }

        if (preg_match('/^(bar\s+\d+)/i', $storeName, $matches) === 1) {
            return Str::title($matches[1]);
        }

        if (preg_match('/^(vip)\b/i', $storeName) === 1) {
            return 'VIP';
        }

        if (preg_match('/^(top\s*up|bc\s*top)\b/i', $storeName) === 1) {
            return 'Top Up';
        }

        if (preg_match('/^(bengaleiro)\b/i', $storeName) === 1) {
            return 'Bengaleiro';
        }

        if (preg_match('/^(bilheteira)\b/i', $storeName) === 1) {
            return 'Bilheteira';
        }

        return trim($storeName);
    }
}
