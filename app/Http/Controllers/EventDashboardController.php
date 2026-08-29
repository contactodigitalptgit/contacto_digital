<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventReportImport;
use App\Models\EventReportPaymentDocument;
use App\Models\EventReportRow;
use App\Services\DashboardConfigurationService;
use App\Services\EventReportAutoSyncService;
use App\Services\EventReportSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EventDashboardController extends Controller
{
    private const NON_SALES_DOCUMENT_TYPES = ['CM', 'ZT'];

    public function __construct(
        private readonly EventReportAutoSyncService $autoSync,
        private readonly DashboardConfigurationService $dashboardConfiguration,
    ) {}

    public function show(Request $request, Event $event): Response
    {
        return $this->renderClientDashboard($request, $event, 'summary');
    }

    public function products(Request $request, Event $event): Response
    {
        return $this->renderClientDashboard($request, $event, 'products');
    }

    public function payments(Request $request, Event $event): Response
    {
        return $this->renderClientDashboard($request, $event, 'reconciliation');
    }

    public function zones(Request $request, Event $event): Response
    {
        return $this->renderClientDashboard($request, $event, 'zones');
    }

    public function performance(Request $request, Event $event): Response
    {
        return $this->renderClientDashboard($request, $event, 'highlights');
    }

    public function comparison(Request $request, Event $event): Response
    {
        return $this->renderClientDashboard($request, $event, 'comparison');
    }

    public function preview(Request $request, Event $event): Response
    {
        return $this->renderAdminDashboard($request, $event, 'summary');
    }

    public function previewProducts(Request $request, Event $event): Response
    {
        return $this->renderAdminDashboard($request, $event, 'products');
    }

    public function previewPayments(Request $request, Event $event): Response
    {
        return $this->renderAdminDashboard($request, $event, 'reconciliation');
    }

    public function previewZones(Request $request, Event $event): Response
    {
        return $this->renderAdminDashboard($request, $event, 'zones');
    }

    public function previewPerformance(Request $request, Event $event): Response
    {
        return $this->renderAdminDashboard($request, $event, 'highlights');
    }

    public function previewComparison(Request $request, Event $event): Response
    {
        return $this->renderAdminDashboard($request, $event, 'comparison');
    }

    private function renderClientDashboard(Request $request, Event $event, string $initialSection): Response
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
            $initialSection,
        );
    }

    private function renderAdminDashboard(Request $request, Event $event, string $initialSection): Response
    {
        return $this->renderDashboard(
            $request,
            $event,
            true,
            route('admin.events.index'),
            'Voltar para eventos',
            $initialSection,
        );
    }

    private function renderDashboard(
        Request $request,
        Event $event,
        bool $previewMode,
        string $backUrl,
        string $backLabel,
        string $initialSection,
    ): Response {
        $event->load(['client', 'latestActiveReportImport', 'latestReportImport'])
            ->loadCount([
                'processingReportImports',
                'zonesoftMachines as active_zonesoft_machines_count' => fn ($query) => $query->where('is_active', true),
            ]);

        if ((int) $event->processing_report_imports_count > 0) {
            app(EventReportSyncService::class)->markStaleProcessingImportsAsFailed($event);
            $event->unsetRelation('latestActiveReportImport');
            $event->unsetRelation('latestReportImport');
            $event->load(['latestActiveReportImport', 'latestReportImport'])
                ->loadCount('processingReportImports');
        }

        $latestActiveImportSummary = is_array($event->latestActiveReportImport?->summary)
            ? $event->latestActiveReportImport->summary
            : [];
        $eventOptions = $this->buildEventOptions($event, $previewMode, $initialSection);
        $dashboardConfiguration = $this->dashboardConfiguration->resolve($event);

        $filters = $this->normalizeFilters($request);
        $dashboardCacheVersion = $this->dashboardCacheVersion($event);
        $rememberDashboardValue = fn (
            string $fragment,
            callable $resolver,
            bool $includeFilters = true,
        ): mixed => $this->rememberDashboardValue(
            $event,
            $dashboardCacheVersion,
            $includeFilters ? $filters : [],
            $fragment,
            $resolver,
        );
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
            $event->show_zt_card,
        );
        $makeFilteredProductRowsQuery = fn (): Builder => $this->applyFilters($makeProductRowsQuery(), $filters);

        /** @var array<int, array<string, mixed>>|null $documentTypes */
        $documentTypes = null;
        $resolveDocumentTypes = function () use (
            &$documentTypes,
            $makeFilteredRowsQuery,
            $rememberDashboardValue,
        ): array {
            if ($documentTypes === null) {
                $documentTypes = $rememberDashboardValue(
                    'document-types',
                    fn (): array => $this->buildDocumentTypes($makeFilteredRowsQuery()),
                );
            }

            return $documentTypes;
        };

        /** @var array<int, array<string, mixed>>|null $dailyBreakdowns */
        $dailyBreakdowns = null;
        $resolveDailyBreakdowns = function () use (
            &$dailyBreakdowns,
            $event,
            $latestActiveImportSummary,
            $filters,
            $makeFilteredProductRowsQuery,
            $rememberDashboardValue,
        ): array {
            if ($dailyBreakdowns === null) {
                $dailyBreakdowns = $rememberDashboardValue(
                    'daily-breakdowns',
                    fn (): array => $this->buildDailyBreakdowns(
                        $event->latestActiveReportImport,
                        $latestActiveImportSummary,
                        $filters,
                        $makeFilteredProductRowsQuery(),
                    ),
                );
            }

            return $dailyBreakdowns;
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
                'report_starts_at' => $event->report_starts_at?->toISOString(),
                'report_ends_at' => $event->report_ends_at?->toISOString(),
                'client_name' => $event->client->name,
                'client_business_name' => $event->client->business_name,
                'processing_imports_count' => (int) $event->processing_report_imports_count,
                'last_synced_at' => $event->latestActiveReportImport?->imported_at?->toISOString(),
                'show_zt_card' => $event->show_zt_card,
            ],
            'eventOptions' => $eventOptions,
            'dashboardConfiguration' => $dashboardConfiguration,
            'dashboardEditor' => $previewMode ? [
                'enabled' => true,
                'edit_url' => route('admin.events.dashboard-configuration.edit', $event),
                'manage_tpas_url' => route('admin.events.tpas.manage', $event),
            ] : null,
            'integration' => [
                'source' => 'ZoneSoft API',
                'configured_client_ids_count' => (int) ($event->active_zonesoft_machines_count ?? 0),
                'machines_count' => (int) ($event->latestActiveReportImport?->summary['machines_count'] ?? 0),
                'last_synced_at' => $event->latestActiveReportImport?->imported_at?->toISOString(),
            ],
            'autoSync' => $this->autoSync->status($event),
            'syncStatus' => $this->buildSyncStatus($event),
            'filters' => $filters,
            'filterOptions' => Inertia::defer(fn (): array => $rememberDashboardValue(
                'filter-options',
                fn (): array => [
                    'barGroups' => $this->buildBarGroupOptions($makeBaseRowsQuery()),
                    'stores' => $this->buildStoreOptions($makeBaseRowsQuery()),
                    'products' => $this->buildProductOptions($makeBaseRowsQuery()),
                ],
                false,
            ), 'dashboard-operational'),
            'summary' => fn (): array => $rememberDashboardValue(
                'summary',
                fn (): array => $this->buildSummary(
                    $makeBaseRowsQuery(),
                    $makeFilteredRowsQuery(),
                    (int) $event->processing_report_imports_count,
                    $event->latestActiveReportImport?->imported_at?->toISOString(),
                    (int) ($event->latestActiveReportImport?->summary['machines_count'] ?? 0),
                    $resolveDocumentTypes(),
                ),
            ),
            'barGroups' => Inertia::defer(fn (): array => $rememberDashboardValue(
                'bar-groups',
                fn (): array => $this->buildBarGroups($makeFilteredRowsQuery()),
            ), 'dashboard-operational'),
            'zoneDevices' => Inertia::defer(fn (): array => $rememberDashboardValue(
                'zone-devices',
                fn (): array => $this->buildZoneDevices($makeFilteredRowsQuery()),
            ), 'dashboard-operational'),
            'topStores' => Inertia::defer(fn (): array => $rememberDashboardValue(
                'top-stores',
                fn (): array => $this->buildTopStores($makeFilteredRowsQuery()),
            ), 'dashboard-operational'),
            'topProducts' => Inertia::defer(fn (): array => $rememberDashboardValue(
                'top-products',
                fn (): array => $this->buildTopProducts($makeFilteredProductRowsQuery()),
            ), 'dashboard-products'),
            'productBreakdowns' => Inertia::defer(fn (): array => $rememberDashboardValue(
                'product-breakdowns',
                fn (): array => $this->buildProductBreakdowns($makeFilteredProductRowsQuery()),
            ), 'dashboard-products'),
            'dailySales' => fn (): array => $resolveDailyBreakdowns(),
            'dailyBreakdowns' => fn (): array => $resolveDailyBreakdowns(),
            'hourlySales' => Inertia::defer(fn (): array => $rememberDashboardValue(
                'hourly-sales',
                fn (): array => $this->buildHourlySales($makeFilteredRowsQuery()),
            ), 'dashboard-analytics'),
            'documentTypes' => Inertia::optional(fn (): array => $resolveDocumentTypes()),
            'paymentSummary' => fn (): array => $rememberDashboardValue(
                'payment-summary',
                fn (): array => $this->buildPaymentSummary(
                    $event->latestActiveReportImport,
                    $latestActiveImportSummary,
                    $filters,
                ),
            ),
            'reconciliation' => Inertia::defer(fn (): array => $rememberDashboardValue(
                'reconciliation',
                fn (): array => $this->buildPaymentReconciliation(
                    $event->latestActiveReportImport,
                    $latestActiveImportSummary,
                    $makeFilteredRowsQuery(),
                    $filters,
                ),
            ), 'dashboard-finance'),
            'comparison' => Inertia::defer(fn (): array => $rememberDashboardValue(
                'comparison',
                fn (): array => $this->buildComparison(
                    $event,
                    $makeBaseRowsQuery(),
                    $latestActiveImportSummary,
                ),
                false,
            ), 'dashboard-finance'),
            'rows' => Inertia::optional(fn () => $resolveRows()->getCollection()->map(fn (EventReportRow $row): array => [
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
            ])->values()),
            'pagination' => Inertia::optional(fn (): array => [
                'current_page' => $resolveRows()->currentPage(),
                'last_page' => $resolveRows()->lastPage(),
                'per_page' => $resolveRows()->perPage(),
                'total' => $resolveRows()->total(),
                'from' => $resolveRows()->firstItem(),
                'to' => $resolveRows()->lastItem(),
                'prev_page_url' => $resolveRows()->previousPageUrl(),
                'next_page_url' => $resolveRows()->nextPageUrl(),
            ]),
            'previewMode' => $previewMode,
            'initialSection' => $initialSection,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ]);
    }

    private function dashboardCacheVersion(Event $event): string
    {
        $clientImports = EventReportImport::query()
            ->where('is_active', true)
            ->where('status', 'completed')
            ->whereHas('event', fn (Builder $query): Builder => $query->where('client_id', $event->client_id))
            ->selectRaw('MAX(id) AS latest_id, MAX(updated_at) AS latest_updated_at')
            ->toBase()
            ->first();

        return hash('sha256', serialize([
            'event' => $event->id,
            'event_updated_at' => $event->updated_at?->toISOString(),
            'active_import' => $event->latestActiveReportImport?->id,
            'active_import_updated_at' => $event->latestActiveReportImport?->updated_at?->toISOString(),
            'latest_attempt' => $event->latestReportImport?->id,
            'latest_attempt_status' => $event->latestReportImport?->status,
            'latest_attempt_updated_at' => $event->latestReportImport?->updated_at?->toISOString(),
            'processing_imports' => (int) $event->processing_report_imports_count,
            'client_latest_active_import' => $clientImports?->latest_id,
            'client_latest_active_import_updated_at' => $clientImports?->latest_updated_at,
        ]));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function rememberDashboardValue(
        Event $event,
        string $cacheVersion,
        array $filters,
        string $fragment,
        callable $resolver,
    ): mixed {
        $ttl = max(0, (int) config('event-reports.dashboard.cache_ttl_seconds', 300));

        if ($ttl === 0) {
            return $resolver();
        }

        $cacheFilters = $filters;
        if (is_array($cacheFilters['bar_groups'] ?? null)) {
            sort($cacheFilters['bar_groups']);
        }
        ksort($cacheFilters);

        $cacheKey = implode(':', [
            'event-dashboard-v1',
            $event->id,
            $cacheVersion,
            $fragment,
            hash('sha256', serialize($cacheFilters)),
        ]);

        return Cache::remember($cacheKey, now()->addSeconds($ttl), $resolver);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSyncStatus(Event $event): array
    {
        $latestAttempt = $event->latestReportImport;
        $latestSuccess = $event->latestActiveReportImport;
        $attemptSummary = is_array($latestAttempt?->summary) ? $latestAttempt->summary : [];
        $attemptStatus = $latestAttempt?->status ?? 'idle';
        $latestAttemptAt = $latestAttempt
            ? ($latestAttempt->imported_at ?? $latestAttempt->updated_at ?? $latestAttempt->created_at)
            : null;
        $isStale = $attemptStatus === 'failed'
            && (! $latestSuccess || $latestAttempt?->id !== $latestSuccess->id);

        return [
            'status' => $attemptStatus,
            'is_stale' => $isStale,
            'latest_attempt_at' => $latestAttemptAt?->toISOString(),
            'last_success_at' => $latestSuccess?->imported_at?->toISOString(),
            'stage' => (string) ($attemptSummary['stage'] ?? ($attemptStatus === 'processing' ? 'queued' : $attemptStatus)),
            'machines_total' => (int) ($attemptSummary['machines_total'] ?? $attemptSummary['machines_count'] ?? 0),
            'machines_processed' => (int) ($attemptSummary['machines_processed'] ?? 0),
            'documents_processed' => (int) ($attemptSummary['documents_processed'] ?? 0),
            'message' => $this->buildSyncStatusMessage($attemptStatus, $attemptSummary, $isStale),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function buildSyncStatusMessage(string $status, array $summary, bool $isStale): ?string
    {
        if ($status === 'processing') {
            return 'A sincronização está em curso. Os valores continuam a mostrar o último relatório válido até a nova versão ficar completa.';
        }

        if ($status !== 'failed' || ! $isStale) {
            return null;
        }

        $error = Str::lower((string) ($summary['error'] ?? ''));

        if (Str::contains($error, ['could not resolve host', 'curl error 6', 'ligacao a zonesoft'])) {
            return 'A última tentativa não conseguiu comunicar com a ZoneSoft. Os valores apresentados são da última sincronização válida.';
        }

        if (Str::contains($error, ['database is locked', 'database locked'])) {
            return 'A última tentativa terminou antes de publicar os dados. O último relatório válido foi mantido.';
        }

        return 'A última tentativa não foi concluída. Os valores apresentados são da última sincronização válida.';
    }

    /**
     * @return array<int, array{id: int, title: string, event_date: string, url: string, is_current: bool}>
     */
    private function buildEventOptions(Event $event, bool $previewMode, string $initialSection): array
    {
        return Event::query()
            ->where('client_id', $event->client_id)
            ->where('is_active', true)
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->get(['id', 'title', 'event_date'])
            ->map(fn (Event $option): array => [
                'id' => $option->id,
                'title' => $option->title,
                'event_date' => $option->event_date->toISOString(),
                'url' => $this->eventSectionUrl($option, $previewMode, $initialSection),
                'is_current' => $option->id === $event->id,
            ])
            ->values()
            ->all();
    }

    private function eventSectionUrl(Event $event, bool $previewMode, string $section): string
    {
        $routeSuffix = match ($section) {
            'products' => 'products',
            'reconciliation' => 'payments',
            'zones' => 'zones',
            'highlights' => 'performance',
            'comparison' => 'comparison',
            default => 'dashboard',
        };

        return route(($previewMode ? 'admin.events.' : 'events.').$routeSuffix, $event);
    }

    /**
     * @return array{bar_groups: array<int, string>, store: string, product: string, date_from: string, date_to: string, hour_from: string, hour_to: string, total_min: string, total_max: string}
     */
    private function normalizeFilters(Request $request): array
    {
        $validated = $request->validate([
            'bar_group' => ['nullable', 'string', 'max:255'],
            'bar_groups' => ['nullable', 'array', 'max:50'],
            'bar_groups.*' => ['string', 'max:255'],
            'store' => ['nullable', 'string', 'max:255'],
            'product' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'hour_from' => ['nullable', 'integer', 'between:0,23'],
            'hour_to' => ['nullable', 'integer', 'between:0,23'],
            'total_min' => ['nullable', 'string', 'max:40'],
            'total_max' => ['nullable', 'string', 'max:40'],
        ]);

        $barGroups = collect($validated['bar_groups'] ?? [])
            ->when(
                empty($validated['bar_groups'] ?? []) && isset($validated['bar_group']),
                fn (Collection $groups): Collection => $groups->push($validated['bar_group']),
            )
            ->map(fn (mixed $group): string => trim((string) $group))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'bar_groups' => $barGroups,
            'store' => trim((string) ($validated['store'] ?? '')),
            'product' => trim((string) ($validated['product'] ?? '')),
            'date_from' => trim((string) ($validated['date_from'] ?? '')),
            'date_to' => trim((string) ($validated['date_to'] ?? '')),
            'hour_from' => isset($validated['hour_from']) ? (string) $validated['hour_from'] : '',
            'hour_to' => isset($validated['hour_to']) ? (string) $validated['hour_to'] : '',
            'total_min' => $this->normalizeDecimalString($validated['total_min'] ?? null),
            'total_max' => $this->normalizeDecimalString($validated['total_max'] ?? null),
        ];
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        if ($filters['bar_groups'] !== []) {
            $this->applyBarGroupsFilter($query, $filters['bar_groups']);
        }

        if ($filters['store'] !== '') {
            $query->where('store_name', $filters['store']);
        }

        if ($filters['product'] !== '') {
            $query->where('product_code', $filters['product']);
        }

        if ($filters['date_from'] !== '') {
            $this->applyReportingDateFilter($query, '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $this->applyReportingDateFilter($query, '<=', $filters['date_to']);
        }

        $this->applyHourFilter($query, $filters['hour_from'], $filters['hour_to']);

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
        $totals = (clone $filteredRowsQuery)
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COALESCE(SUM(total), 0) as total_sales')
            ->selectRaw('COALESCE(SUM(value), 0) as total_value')
            ->selectRaw('COALESCE(SUM(discount), 0) as total_discount')
            ->selectRaw('COALESCE(SUM(quantity), 0) as total_quantity')
            ->selectRaw("COUNT(DISTINCT CASE WHEN store_name IS NOT NULL AND store_name != '' THEN store_name END) as stores_count")
            ->selectRaw("COUNT(DISTINCT CASE WHEN product_code IS NOT NULL AND product_code != '' THEN product_code END) as products_count")
            ->first();
        $filteredRowsCount = (int) ($totals?->rows_count ?? 0);
        $totalSales = (float) ($totals?->total_sales ?? 0);
        $eventTotalSales = (float) ((clone $baseRowsQuery)->sum('total') ?? 0);
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
            'event_total_sales' => $eventTotalSales,
            'total_value' => (float) ($totals?->total_value ?? 0),
            'total_discount' => (float) ($totals?->total_discount ?? 0),
            'total_quantity' => (float) ($totals?->total_quantity ?? 0),
            'stores_count' => (int) ($totals?->stores_count ?? 0),
            'tickets_count' => $ticketsCount,
            'products_count' => (int) ($totals?->products_count ?? 0),
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
        $ticketExpression = $this->ticketSqlExpression($query);

        /** @var Collection<int, EventReportRow> $stores */
        $stores = $query
            ->select('store_name', 'store_code')
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw("COUNT(DISTINCT {$ticketExpression}) as tickets_count")
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity_total')
            ->selectRaw('COALESCE(SUM(total), 0) as sales_total')
            ->groupBy('store_name', 'store_code')
            ->get();

        return $stores
            ->groupBy(fn (EventReportRow $row): string => $this->resolveBarGroupLabel($row->store_name))
            ->map(function (Collection $groupStores, string $label): array {
                $members = $groupStores
                    ->pluck('store_name')
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $ticketsCount = (int) $groupStores->sum('tickets_count');
                $salesTotal = round((float) $groupStores->sum('sales_total'), 4);

                return [
                    'label' => $label,
                    'stores_count' => count($members),
                    'members' => $members,
                    'rows_count' => (int) $groupStores->sum('rows_count'),
                    'tickets_count' => $ticketsCount,
                    'quantity_total' => round((float) $groupStores->sum('quantity_total'), 4),
                    'sales_total' => $salesTotal,
                    'average_ticket' => $ticketsCount > 0
                        ? round($salesTotal / $ticketsCount, 4)
                        : 0.0,
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
            ->selectRaw('COALESCE(SUM(CASE WHEN total = 0 THEN quantity ELSE 0 END), 0) as offered_quantity')
            ->selectRaw('COALESCE(SUM(CASE WHEN total != 0 THEN quantity ELSE 0 END), 0) as sold_quantity')
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
                'offered_quantity' => (float) ($row->offered_quantity ?? 0),
                'sold_quantity' => (float) ($row->sold_quantity ?? 0),
                'category' => 'Sem categoria',
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
            ->where(fn (Builder $dateQuery) => $dateQuery
                ->whereNotNull('sale_datetime')
                ->orWhereNotNull('sale_date'))
            ->get(['sale_datetime', 'sale_date'])
            ->map(fn (EventReportRow $row): ?string => $this->resolveRowReportingDate($row))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return [
            'total' => $this->buildTopProducts(clone $query),
            'days' => $dates
                ->map(fn (string $date): array => [
                    'date' => $date,
                    'label' => CarbonImmutable::parse($date)->locale('pt_PT')->translatedFormat('d M'),
                    'items' => $this->buildTopProducts(
                        $this->applyReportingDateFilter(clone $query, '=', $date),
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
            ->where(fn (Builder $dateQuery) => $dateQuery
                ->whereNotNull('sale_datetime')
                ->orWhereNotNull('sale_date'))
            ->get([
                'id',
                'sale_date',
                'sale_datetime',
                'doc_type',
                'document_series',
                'document_number',
                'store_code',
                'quantity',
                'total',
            ]);

        return $rows
            ->groupBy(fn (EventReportRow $row): string => $this->resolveRowReportingDate($row) ?? '')
            ->forget('')
            ->map(function (Collection $dayRows, string $date): array {
                return [
                    'date' => $date,
                    'label' => CarbonImmutable::parse($date)->locale('pt_PT')->translatedFormat('d M'),
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
     * @return array<int, array{date: string, label: string, hour: int, hour_label: string, sales_total: float, tickets_count: int}>
     */
    private function buildHourlySales(Builder $query): array
    {
        $driver = $query->getModel()->getConnection()->getDriverName();

        [$dateExpression, $hourExpression] = match ($driver) {
            'pgsql' => [
                "TO_CHAR(sale_datetime, 'YYYY-MM-DD')",
                'CAST(EXTRACT(HOUR FROM sale_datetime) AS INTEGER)',
            ],
            'mysql', 'mariadb' => [
                "DATE_FORMAT(sale_datetime, '%Y-%m-%d')",
                'HOUR(sale_datetime)',
            ],
            default => [
                "strftime('%Y-%m-%d', sale_datetime)",
                "CAST(strftime('%H', sale_datetime) AS INTEGER)",
            ],
        };
        $ticketExpression = $this->ticketSqlExpression($query);

        return $query
            ->whereNotNull('sale_datetime')
            ->selectRaw("{$dateExpression} as sale_day")
            ->selectRaw("{$hourExpression} as sale_hour")
            ->selectRaw('COALESCE(SUM(total), 0) as sales_total')
            ->selectRaw("COUNT(DISTINCT {$ticketExpression}) as tickets_count")
            ->groupByRaw("{$dateExpression}, {$hourExpression}")
            ->orderByRaw("{$dateExpression}, {$hourExpression}")
            ->get()
            ->map(function (EventReportRow $row): array {
                $date = (string) $row->sale_day;
                $hour = (int) $row->sale_hour;

                return [
                    'date' => $date,
                    'label' => CarbonImmutable::parse($date)->locale('pt_PT')->translatedFormat('d M'),
                    'hour' => $hour,
                    'hour_label' => sprintf('%02d:00', $hour),
                    'sales_total' => round((float) ($row->sales_total ?? 0), 4),
                    'tickets_count' => (int) ($row->tickets_count ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $latestImportSummary
     * @param  array{bar_groups: array<int, string>, store: string, product: string, date_from: string, date_to: string, hour_from: string, hour_to: string, total_min: string, total_max: string}  $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildDailyBreakdowns(
        ?EventReportImport $latestImport,
        array $latestImportSummary,
        array $filters,
        Builder $fallbackRowsQuery,
    ): array {
        $days = [];

        foreach ($this->paymentDocuments(
            $latestImport,
            $latestImportSummary,
            $filters,
        ) as $document) {
            $date = $this->resolveDocumentReportingDate($document);

            if ($date === null) {
                continue;
            }

            if (! isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    'label' => CarbonImmutable::parse($date)->locale('pt_PT')->translatedFormat('d M'),
                    'sales_total' => 0.0,
                    'quantity_total' => 0.0,
                    'ticket_keys' => [],
                    'payments' => [
                        'multibanco' => 0.0,
                        'cash' => 0.0,
                        'zticket' => 0.0,
                        'other' => 0.0,
                    ],
                    'top_up_loaded' => 0.0,
                    'top_up_document_keys' => [],
                    'other_movements' => 0.0,
                ];
            }

            $amount = (float) ($document['total'] ?? 0);

            if ($this->isTopUpDocument($document)) {
                $days[$date]['top_up_loaded'] += $amount;
                $days[$date]['top_up_document_keys'][$this->buildPaymentDocumentKey($document)] = true;

                continue;
            }

            if (! $this->isSalesPaymentDocument($document)) {
                $days[$date]['other_movements'] += $amount;

                continue;
            }

            $category = $this->resolvePaymentCategory((string) ($document['payment_code'] ?? ''));
            $days[$date]['payments'][$category] += $amount;
            $days[$date]['sales_total'] += $amount;
            $days[$date]['ticket_keys'][$this->buildPaymentDocumentKey($document)] = true;
        }

        if ($days === []) {
            return collect($this->buildDailySales($fallbackRowsQuery))
                ->map(fn (array $day): array => [
                    ...$day,
                    'average_ticket' => (int) ($day['tickets_count'] ?? 0) > 0
                        ? round((float) ($day['sales_total'] ?? 0) / (int) $day['tickets_count'], 4)
                        : 0.0,
                    'multibanco' => 0.0,
                    'cash' => 0.0,
                    'zticket' => 0.0,
                    'other' => 0.0,
                    'top_up_documents_count' => 0,
                    'top_up_loaded' => 0.0,
                    'top_up_spent' => 0.0,
                    'top_up_remaining' => 0.0,
                    'total_with_zt' => round((float) ($day['sales_total'] ?? 0), 4),
                    'other_movements' => 0.0,
                ])
                ->values()
                ->all();
        }

        ksort($days);

        return collect($days)
            ->map(function (array $day): array {
                $payments = $day['payments'];
                $salesTotal = (float) $day['sales_total'];
                $ticketsCount = count($day['ticket_keys']);
                $topUpLoaded = (float) $day['top_up_loaded'];
                $topUpSpent = (float) $payments['zticket'];

                return [
                    'date' => $day['date'],
                    'label' => $day['label'],
                    'sales_total' => round($salesTotal, 4),
                    'quantity_total' => 0.0,
                    'tickets_count' => $ticketsCount,
                    'average_ticket' => $ticketsCount > 0 ? round($salesTotal / $ticketsCount, 4) : 0.0,
                    'multibanco' => round((float) $payments['multibanco'], 4),
                    'cash' => round((float) $payments['cash'], 4),
                    'zticket' => round((float) $payments['zticket'], 4),
                    'other' => round((float) $payments['other'], 4),
                    'top_up_documents_count' => count($day['top_up_document_keys']),
                    'top_up_loaded' => round($topUpLoaded, 4),
                    'top_up_spent' => round($topUpSpent, 4),
                    'top_up_remaining' => round(max($topUpLoaded - $topUpSpent, 0), 4),
                    'total_with_zt' => round($salesTotal + $topUpLoaded, 4),
                    'other_movements' => round((float) $day['other_movements'], 4),
                ];
            })
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
        $documentTypeExpression = "CASE WHEN doc_type IS NULL OR TRIM(doc_type) = '' THEN 'Sem tipo' ELSE doc_type END";
        $ticketExpression = $this->ticketSqlExpression($query);

        return $query
            ->selectRaw("{$documentTypeExpression} as document_type_label")
            ->selectRaw("COUNT(DISTINCT {$ticketExpression}) as tickets_count")
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity_total')
            ->selectRaw('COALESCE(SUM(total), 0) as sales_total')
            ->groupByRaw($documentTypeExpression)
            ->orderByDesc('sales_total')
            ->get()
            ->map(function (EventReportRow $row): array {
                $label = (string) $row->document_type_label;

                return [
                    'label' => $label,
                    'code' => $label === 'Sem tipo' ? null : $label,
                    'tickets_count' => (int) ($row->tickets_count ?? 0),
                    'rows_count' => (int) ($row->rows_count ?? 0),
                    'quantity_total' => round((float) ($row->quantity_total ?? 0), 4),
                    'sales_total' => round((float) ($row->sales_total ?? 0), 4),
                ];
            })
            ->values()
            ->all();
    }

    private function ticketSqlExpression(Builder $query): string
    {
        return match ($query->getModel()->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => "CONCAT_WS('|', COALESCE(store_code, ''), COALESCE(doc_type, ''), COALESCE(document_series, ''), COALESCE(document_number, ''))",
            default => "COALESCE(store_code, '') || '|' || COALESCE(doc_type, '') || '|' || COALESCE(document_series, '') || '|' || COALESCE(document_number, '')",
        };
    }

    /**
     * @param  array<string, mixed>  $latestImportSummary
     * @param  array{bar_groups: array<int, string>, store: string, product: string, date_from: string, date_to: string, hour_from: string, hour_to: string, total_min: string, total_max: string}  $filters
     * @return array<string, mixed>
     */
    private function buildPaymentSummary(
        ?EventReportImport $latestImport,
        array $latestImportSummary,
        array $filters,
    ): array {
        $hasProductSpecificFilters = $filters['product'] !== ''
            || $filters['total_min'] !== ''
            || $filters['total_max'] !== '';
        $totals = [
            'multibanco' => 0.0,
            'cash' => 0.0,
            'zticket' => 0.0,
            'other' => 0.0,
            'top_up_loaded' => 0.0,
            'top_up_spent' => 0.0,
            'other_movements' => 0.0,
        ];
        $movementDocumentKeys = [];
        $salesDocumentKeys = [];
        $topUpDocumentKeys = [];
        $hasDocuments = false;

        foreach ($this->paymentDocuments(
            $latestImport,
            $latestImportSummary,
            $filters,
        ) as $document) {
            $hasDocuments = true;
            $amount = (float) ($document['total'] ?? 0);
            $category = $this->resolvePaymentCategory((string) ($document['payment_code'] ?? ''));
            $isTopUp = $this->isTopUpDocument($document);
            $documentKey = $this->buildPaymentDocumentKey($document);
            $movementDocumentKeys[$documentKey] = true;

            if ($isTopUp) {
                $totals['top_up_loaded'] += $amount;
                $topUpDocumentKeys[$documentKey] = true;

                continue;
            }

            if (! $this->isSalesPaymentDocument($document)) {
                $totals['other_movements'] += $amount;

                continue;
            }

            $totals[$category] += $amount;
            $salesDocumentKeys[$documentKey] = true;

            if ($category === 'zticket') {
                $totals['top_up_spent'] += $amount;
            }
        }

        if (! $hasDocuments) {
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
            'documents_count' => count($salesDocumentKeys),
            'movement_documents_count' => count($movementDocumentKeys),
            'multibanco' => round($totals['multibanco'], 4),
            'cash' => round($totals['cash'], 4),
            'zticket' => round($totals['zticket'], 4),
            'other' => round($totals['other'], 4),
            'sales_total' => round($salesTotal, 4),
            'total_without_zt' => round($salesTotal, 4),
            'total_with_zt' => round($totalWithZt, 4),
            'movement_total' => round($movementTotal, 4),
            'other_movements' => round($totals['other_movements'], 4),
            'top_up_documents_count' => count($topUpDocumentKeys),
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
     * @param  array{bar_groups: array<int, string>, store: string, product: string, date_from: string, date_to: string, hour_from: string, hour_to: string, total_min: string, total_max: string}  $filters
     * @return array<string, mixed>
     */
    private function buildPaymentReconciliation(
        ?EventReportImport $latestImport,
        array $latestImportSummary,
        Builder $rowsQuery,
        array $filters,
    ): array {
        $hasProductSpecificFilters = $filters['product'] !== ''
            || $filters['total_min'] !== ''
            || $filters['total_max'] !== '';

        $items = [];
        $documentKeys = [];
        $allDocumentKeys = [];

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

        foreach ($this->paymentDocuments(
            $latestImport,
            $latestImportSummary,
            $filters,
        ) as $document) {
            if (! $this->isSalesPaymentDocument($document)) {
                continue;
            }

            $storeCode = isset($document['store_code']) ? (string) $document['store_code'] : null;
            $storeName = isset($document['store_name']) ? (string) $document['store_name'] : null;
            $key = $this->buildStoreKey($storeCode, $storeName);

            if (! isset($items[$key])) {
                $items[$key] = $this->emptyReconciliationItem($storeCode, $storeName);
            }

            $category = $this->resolvePaymentCategory((string) ($document['payment_code'] ?? ''));
            $items[$key][$category] += (float) ($document['total'] ?? 0);
            $paymentDocumentKey = $this->buildPaymentDocumentKey($document);
            $allDocumentKeys[$paymentDocumentKey] = true;

            if (! isset($documentKeys[$key][$paymentDocumentKey])) {
                $items[$key]['documents_count']++;
                $documentKeys[$key][$paymentDocumentKey] = true;
            }
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
            'available' => $allDocumentKeys !== [],
            'documents_count' => count($allDocumentKeys),
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
     * @param  array<string, mixed>  $document
     */
    private function buildPaymentDocumentKey(array $document): string
    {
        return implode('|', [
            $document['machine_client_id'] ?? $document['store_code'] ?? '',
            $document['doc_type'] ?? '',
            $document['document_series'] ?? '',
            $document['document_number'] ?? '',
        ]);
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
        $payments = $this->buildPaymentSummary(
            $event->latestActiveReportImport,
            $importSummary,
            $this->emptyFilters(),
        );
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
     * @return array{bar_groups: array<int, string>, store: string, product: string, date_from: string, date_to: string, hour_from: string, hour_to: string, total_min: string, total_max: string}
     */
    private function emptyFilters(): array
    {
        return [
            'bar_groups' => [],
            'store' => '',
            'product' => '',
            'date_from' => '',
            'date_to' => '',
            'hour_from' => '',
            'hour_to' => '',
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
     * Stream normalized payment documents without hydrating the complete event payload.
     *
     * @param  array<string, mixed>  $legacySummary
     * @param  array{bar_groups: array<int, string>, store: string, product: string, date_from: string, date_to: string, hour_from: string, hour_to: string, total_min: string, total_max: string}  $filters
     * @return \Generator<int, array<string, mixed>>
     */
    private function paymentDocuments(
        ?EventReportImport $latestImport,
        array $legacySummary,
        array $filters,
    ): \Generator {
        if ($latestImport !== null) {
            $baseQuery = EventReportPaymentDocument::query()
                ->where('event_report_import_id', $latestImport->id);

            if ($baseQuery->exists()) {
                $query = $this->applyPaymentDocumentFilters(
                    EventReportPaymentDocument::query()
                        ->where('event_report_import_id', $latestImport->id),
                    $filters,
                );

                foreach ($query->orderBy('id')->toBase()->cursor() as $document) {
                    yield [
                        'machine_id' => $document->machine_id,
                        'machine_client_id' => $document->machine_client_id,
                        'store_code' => $document->store_code,
                        'store_name' => $document->store_name,
                        'sale_date' => $document->sale_date,
                        'sale_datetime' => $document->sale_datetime,
                        'doc_type' => $document->doc_type,
                        'document_series' => $document->document_series,
                        'document_number' => $document->document_number,
                        'payment_reference' => $document->payment_reference,
                        'paid' => $document->paid === null ? null : (bool) $document->paid,
                        'document_total' => $document->document_total,
                        'payment_key' => $document->payment_key,
                        'payment_code' => $document->payment_code,
                        'payment_document_type' => $document->payment_document_type,
                        'payment_document_series' => $document->payment_document_series,
                        'payment_document_number' => $document->payment_document_number,
                        'payment_card_number' => $document->payment_card_number,
                        'total' => $document->total,
                        'is_unallocated' => (bool) $document->is_unallocated,
                    ];
                }

                return;
            }
        }

        $legacyDocuments = collect($legacySummary['payment_documents'] ?? [])
            ->filter(fn (mixed $document): bool => is_array($document))
            ->values();

        foreach ($this->filterPaymentDocuments($legacyDocuments, $filters) as $document) {
            yield $document;
        }
    }

    /**
     * @param  array{bar_groups: array<int, string>, store: string, product: string, date_from: string, date_to: string, hour_from: string, hour_to: string, total_min: string, total_max: string}  $filters
     */
    private function applyPaymentDocumentFilters(Builder $query, array $filters): Builder
    {
        if ($filters['bar_groups'] !== []) {
            $this->applyBarGroupsFilter($query, $filters['bar_groups']);
        }

        if ($filters['store'] !== '') {
            $query->where('store_name', $filters['store']);
        }

        foreach (['date_from' => '>=', 'date_to' => '<='] as $filter => $operator) {
            if ($filters[$filter] === '') {
                continue;
            }

            $date = $filters[$filter];
            $query->where(function (Builder $dateQuery) use ($operator, $date): void {
                $this->applyReportingDateFilter($dateQuery, $operator, $date)
                    ->orWhere(function (Builder $missingDateQuery): void {
                        $missingDateQuery
                            ->whereNull('sale_datetime')
                            ->whereNull('sale_date');
                    });
            });
        }

        $this->applyHourFilter($query, $filters['hour_from'], $filters['hour_to']);

        return $query;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $documents
     * @param  array{bar_groups: array<int, string>, store: string, product: string, date_from: string, date_to: string, hour_from: string, hour_to: string, total_min: string, total_max: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filterPaymentDocuments(Collection $documents, array $filters): Collection
    {
        if ($filters['bar_groups'] !== []) {
            $documents = $documents->filter(function (array $document) use ($filters): bool {
                $storeName = is_string($document['store_name'] ?? null)
                    ? $document['store_name']
                    : null;

                return in_array($this->resolveBarGroupLabel($storeName), $filters['bar_groups'], true);
            })->values();
        }

        if ($filters['store'] !== '') {
            $documents = $documents->filter(
                fn (array $document): bool => ($document['store_name'] ?? null) === $filters['store'],
            )->values();
        }

        if ($filters['date_from'] !== '') {
            $documents = $documents->filter(function (array $document) use ($filters): bool {
                $saleDate = $this->resolveDocumentReportingDate($document);

                return $saleDate === null || $saleDate >= $filters['date_from'];
            })->values();
        }

        if ($filters['date_to'] !== '') {
            $documents = $documents->filter(function (array $document) use ($filters): bool {
                $saleDate = $this->resolveDocumentReportingDate($document);

                return $saleDate === null || $saleDate <= $filters['date_to'];
            })->values();
        }

        if ($filters['hour_from'] !== '' || $filters['hour_to'] !== '') {
            $documents = $documents->filter(function (array $document) use ($filters): bool {
                $value = $document['sale_datetime'] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    return false;
                }

                try {
                    $hour = CarbonImmutable::parse($value)->hour;
                } catch (\Throwable) {
                    return false;
                }

                return $this->hourIsWithinRange($hour, $filters['hour_from'], $filters['hour_to']);
            })->values();
        }

        return $documents;
    }

    private function applyHourFilter(Builder $query, string $hourFrom, string $hourTo): void
    {
        if ($hourFrom === '' && $hourTo === '') {
            return;
        }

        $driver = $query->getModel()->getConnection()->getDriverName();
        $hourExpression = match ($driver) {
            'pgsql' => 'CAST(EXTRACT(HOUR FROM sale_datetime) AS INTEGER)',
            'mysql', 'mariadb' => 'HOUR(sale_datetime)',
            default => "CAST(strftime('%H', sale_datetime) AS INTEGER)",
        };

        $query->whereNotNull('sale_datetime')
            ->where(function (Builder $hourQuery) use ($hourExpression, $hourFrom, $hourTo): void {
                if ($hourFrom !== '' && $hourTo !== '' && (int) $hourFrom > (int) $hourTo) {
                    $hourQuery
                        ->whereRaw("{$hourExpression} >= ?", [(int) $hourFrom])
                        ->orWhereRaw("{$hourExpression} <= ?", [(int) $hourTo]);

                    return;
                }

                if ($hourFrom !== '') {
                    $hourQuery->whereRaw("{$hourExpression} >= ?", [(int) $hourFrom]);
                }

                if ($hourTo !== '') {
                    $hourQuery->whereRaw("{$hourExpression} <= ?", [(int) $hourTo]);
                }
            });
    }

    private function hourIsWithinRange(int $hour, string $hourFrom, string $hourTo): bool
    {
        if ($hourFrom !== '' && $hourTo !== '' && (int) $hourFrom > (int) $hourTo) {
            return $hour >= (int) $hourFrom || $hour <= (int) $hourTo;
        }

        return ($hourFrom === '' || $hour >= (int) $hourFrom)
            && ($hourTo === '' || $hour <= (int) $hourTo);
    }

    private function applyReportingDateFilter(Builder $query, string $operator, string $date): Builder
    {
        return $query->where(function (Builder $dateQuery) use ($operator, $date): void {
            $dateQuery
                ->whereDate('sale_date', $operator, $date)
                ->orWhere(function (Builder $fallbackQuery) use ($operator, $date): void {
                    $fallbackQuery
                        ->whereNull('sale_date')
                        ->whereDate('sale_datetime', $operator, $date);
                });
        });
    }

    private function resolveRowReportingDate(EventReportRow $row): ?string
    {
        return $row->sale_date?->toDateString()
            ?? $row->sale_datetime?->toDateString();
    }

    /**
     * Match the ZoneSoft dashboard by grouping on its operational document date.
     * Hourly charts still use the transaction timestamp directly.
     *
     * @param  array<string, mixed>  $document
     */
    private function resolveDocumentReportingDate(array $document): ?string
    {
        foreach (['sale_date', 'sale_datetime'] as $key) {
            $value = $document[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                return CarbonImmutable::parse($value)->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
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

    private function applyProductDocumentScope(Builder $query, bool $includeZt): Builder
    {
        $query->where(function (Builder $builder): void {
            $builder
                ->whereNull('doc_type')
                ->orWhere('doc_type', '!=', 'CM');
        });

        if (! $includeZt) {
            $query->where(function (Builder $builder): void {
                $builder
                    ->whereNull('doc_type')
                    ->orWhere('doc_type', '!=', 'ZT');
            });
        }

        return $query;
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
            preg_match('/\d+/', $normalizedBarGroup, $numberMatches);
            $barNumber = $numberMatches[0] ?? '';
            $spacedLabel = 'bar '.$barNumber;
            $compactLabel = 'bar'.$barNumber;

            $query->where(function (Builder $builder) use ($spacedLabel, $compactLabel): void {
                $builder
                    ->whereRaw('LOWER(COALESCE(store_name, \'\')) = ?', [$spacedLabel])
                    ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', ['%'.$spacedLabel.' %'])
                    ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', ['%'.$spacedLabel.'-%'])
                    ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', ['%'.$spacedLabel])
                    ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', ['%'.$compactLabel.' %'])
                    ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', ['%'.$compactLabel.'-%'])
                    ->orWhereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', ['%'.$compactLabel]);
            });

            return;
        }

        if (Str::lower($normalizedBarGroup) === 'bar vip') {
            $query->where(function (Builder $builder): void {
                $builder
                    ->whereRaw('LOWER(COALESCE(store_name, \'\')) LIKE ?', ['%bar vip%'])
                    ->orWhereRaw('LOWER(TRIM(COALESCE(store_name, \'\'))) = ?', ['vip'])
                    ->orWhereRaw('LOWER(TRIM(COALESCE(store_name, \'\'))) LIKE ?', ['vip %'])
                    ->orWhereRaw('LOWER(TRIM(COALESCE(store_name, \'\'))) LIKE ?', ['vip-%']);
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

    /**
     * @param  array<int, string>  $barGroups
     */
    private function applyBarGroupsFilter(Builder $query, array $barGroups): void
    {
        $query->where(function (Builder $builder) use ($barGroups): void {
            foreach ($barGroups as $barGroup) {
                $builder->orWhere(function (Builder $barGroupQuery) use ($barGroup): void {
                    $this->applyBarGroupFilter($barGroupQuery, $barGroup);
                });
            }
        });
    }

    private function resolveBarGroupLabel(?string $storeName): string
    {
        if ($storeName === null || trim($storeName) === '') {
            return 'Sem loja';
        }

        if (preg_match('/\b(top\s*up|bc\s*top)\b/i', $storeName) === 1) {
            return 'Top Up';
        }

        if (preg_match('/\bbar\s*vip\b/i', $storeName) === 1) {
            return 'Bar Vip';
        }

        if (preg_match('/\bbar\s*(\d+)\b/i', $storeName, $matches) === 1) {
            return 'Bar '.$matches[1];
        }

        if (preg_match('/^(vip)\b/i', $storeName) === 1) {
            return 'Bar Vip';
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
