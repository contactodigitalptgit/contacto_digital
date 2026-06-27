<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventReportRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EventDashboardController extends Controller
{
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
        $event->load(['client', 'latestActiveReportImport'])
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

        $baseRowsQuery = EventReportRow::query()
            ->where('event_id', $event->id)
            ->fromActiveImports();

        $filteredRowsQuery = $this->applyFilters(clone $baseRowsQuery, $filters);
        $documentTypes = $this->buildDocumentTypes(clone $filteredRowsQuery);

        $rows = (clone $filteredRowsQuery)
            ->orderByDesc('sale_datetime')
            ->orderByDesc('sale_date')
            ->orderByDesc('source_row_number')
            ->paginate(15)
            ->withQueryString();

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
            'filters' => $filters,
            'filterOptions' => [
                'barGroups' => $this->buildBarGroupOptions(clone $baseRowsQuery),
                'stores' => $this->buildStoreOptions(clone $baseRowsQuery),
                'products' => $this->buildProductOptions(clone $baseRowsQuery),
            ],
            'summary' => $this->buildSummary(
                clone $baseRowsQuery,
                clone $filteredRowsQuery,
                (int) $event->processing_report_imports_count,
                $event->latestActiveReportImport?->imported_at?->toISOString(),
                (int) ($event->latestActiveReportImport?->summary['machines_count'] ?? 0),
                $documentTypes,
            ),
            'barGroups' => $this->buildBarGroups(clone $filteredRowsQuery),
            'zoneDevices' => $this->buildZoneDevices(clone $filteredRowsQuery),
            'topStores' => $this->buildTopStores(clone $filteredRowsQuery),
            'topProducts' => $this->buildTopProducts(clone $filteredRowsQuery),
            'documentTypes' => $documentTypes,
            'salesday' => $this->buildSalesDaySummary($latestActiveImportSummary, $filters),
            'paymentSummary' => $this->buildPaymentSummary($latestActiveImportSummary, $filters),
            'rows' => $rows->getCollection()->map(fn (EventReportRow $row): array => [
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
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
                'prev_page_url' => $rows->previousPageUrl(),
                'next_page_url' => $rows->nextPageUrl(),
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
        return $query
            ->select('product_code', 'description')
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity_total')
            ->selectRaw('COALESCE(SUM(total), 0) as sales_total')
            ->groupBy('product_code', 'description')
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
    private function buildSalesDaySummary(array $latestImportSummary, array $filters): array
    {
        /** @var Collection<int, array<string, mixed>> $records */
        $records = collect($latestImportSummary['salesday_records'] ?? [])
            ->filter(fn (mixed $record): bool => is_array($record))
            ->values();
        $warningsCount = count(array_filter($latestImportSummary['salesday_warnings'] ?? [], 'is_array'));

        $records = $this->filterSalesDayRecords($records, $filters);

        $totals = [
            'fs' => 0.0,
            'ft' => 0.0,
            'tk' => 0.0,
            'vd' => 0.0,
            'enc' => 0.0,
            'nc' => 0.0,
            'rc' => 0.0,
            'movimento' => 0.0,
            'num' => 0.0,
            'deb' => 0.0,
            'crd' => 0.0,
            'chq' => 0.0,
            'cartoes' => 0.0,
            'etk' => 0.0,
        ];

        foreach ($records as $record) {
            foreach (array_keys($totals) as $field) {
                $totals[$field] += (float) ($record[$field] ?? 0);
            }
        }

        $hasProductSpecificFilters = $filters['product'] !== ''
            || $filters['total_min'] !== ''
            || $filters['total_max'] !== '';

        return [
            'available' => $records->isNotEmpty(),
            'records_count' => $records->count(),
            'stores_count' => $records
                ->pluck('store_name')
                ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                ->unique()
                ->count(),
            'days_count' => $records
                ->pluck('sale_date')
                ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                ->unique()
                ->count(),
            'cash_registers_count' => $records
                ->pluck('cash_register_code')
                ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                ->unique()
                ->count(),
            'closed_records_count' => $records
                ->filter(fn (array $record): bool => ($record['is_closed'] ?? null) === true)
                ->count(),
            'open_records_count' => $records
                ->filter(fn (array $record): bool => ($record['is_closed'] ?? null) === false)
                ->count(),
            'totals' => array_map(
                fn (float $value): float => round($value, 4),
                $totals,
            ),
            'warnings_count' => $warningsCount,
            'scope_note' => $hasProductSpecificFilters
                ? 'Resumo Z agregado pela ultima sincronizacao. Filtros de produto e total nao alteram este bloco.'
                : 'Resumo Z agregado pela ultima sincronizacao e alinhado com loja, bar e periodo quando aplicados.',
        ];
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
            $salesday = $this->buildSalesDaySummary($latestImportSummary, $filters);
            $multibanco = (float) ($salesday['totals']['deb'] ?? 0) + (float) ($salesday['totals']['crd'] ?? 0);
            $cash = (float) ($salesday['totals']['num'] ?? 0);
            $zticket = (float) ($salesday['totals']['etk'] ?? 0);

            return [
                'available' => (bool) ($salesday['available'] ?? false),
                'source' => 'salesday',
                'documents_count' => 0,
                'multibanco' => round($multibanco, 4),
                'cash' => round($cash, 4),
                'zticket' => round($zticket, 4),
                'other' => 0.0,
                'top_up_loaded' => 0.0,
                'top_up_spent' => round($zticket, 4),
                'top_up_remaining' => 0.0,
                'scope_note' => $hasProductSpecificFilters
                    ? 'Pagamentos indisponiveis nos documentos sincronizados. Foi usado o resumo Salesday como fallback.'
                    : 'Pagamentos calculados pelo resumo Salesday, porque a ultima sincronizacao nao guardou os documentos de pagamento.',
            ];
        }

        $totals = [
            'multibanco' => 0.0,
            'cash' => 0.0,
            'zticket' => 0.0,
            'other' => 0.0,
            'top_up_loaded' => 0.0,
            'top_up_spent' => 0.0,
        ];

        foreach ($documents as $document) {
            $amount = (float) ($document['total'] ?? 0);
            $category = $this->resolvePaymentCategory((string) ($document['payment_code'] ?? ''));
            $isTopUp = $this->isTopUpDocument($document);

            $totals[$category] += $amount;

            if ($isTopUp) {
                $totals['top_up_loaded'] += $amount;
            }

            if ($category === 'zticket' && ! $isTopUp) {
                $totals['top_up_spent'] += $amount;
            }
        }

        return [
            'available' => true,
            'source' => 'documents_headers',
            'documents_count' => $documents->count(),
            'multibanco' => round($totals['multibanco'], 4),
            'cash' => round($totals['cash'], 4),
            'zticket' => round($totals['zticket'], 4),
            'other' => round($totals['other'], 4),
            'top_up_loaded' => round($totals['top_up_loaded'], 4),
            'top_up_spent' => round($totals['top_up_spent'], 4),
            'top_up_remaining' => round(max($totals['top_up_loaded'] - $totals['top_up_spent'], 0), 4),
            'scope_note' => $hasProductSpecificFilters
                ? 'Pagamentos calculados pelos documentos sincronizados. Filtros de produto e total nao alteram este bloco.'
                : 'Pagamentos calculados pelos documentos sincronizados e alinhados com loja, zona e periodo quando aplicados.',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @param  array{bar_group: string, store: string, product: string, date_from: string, date_to: string, total_min: string, total_max: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filterSalesDayRecords(Collection $records, array $filters): Collection
    {
        if ($filters['bar_group'] !== '') {
            $records = $records->filter(function (array $record) use ($filters): bool {
                $storeName = is_string($record['store_name'] ?? null)
                    ? $record['store_name']
                    : null;

                return $this->resolveBarGroupLabel($storeName) === $filters['bar_group'];
            })->values();
        }

        if ($filters['store'] !== '') {
            $records = $records->filter(
                fn (array $record): bool => ($record['store_name'] ?? null) === $filters['store'],
            )->values();
        }

        if ($filters['date_from'] !== '') {
            $records = $records->filter(function (array $record) use ($filters): bool {
                $saleDate = $record['sale_date'] ?? null;

                return ! is_string($saleDate) || $saleDate >= $filters['date_from'];
            })->values();
        }

        if ($filters['date_to'] !== '') {
            $records = $records->filter(function (array $record) use ($filters): bool {
                $saleDate = $record['sale_date'] ?? null;

                return ! is_string($saleDate) || $saleDate <= $filters['date_to'];
            })->values();
        }

        return $records;
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
        $storeName = trim((string) ($document['store_name'] ?? ''));

        if ($docType === 'ZT') {
            return true;
        }

        return preg_match('/^(top\s*up|bc\s*top)\b/i', $storeName) === 1;
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
