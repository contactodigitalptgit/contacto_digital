<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventReportPaymentDocument;
use App\Models\EventReportRowAggregate;
use App\Models\EventReportTicketAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Read-only analytics used by the Flutter client portal.
 *
 * The service deliberately reads the same published aggregate/payment tables
 * used by the web dashboard. It never contacts ZoneSoft and never mutates sync
 * state, so mobile traffic cannot slow down or interfere with synchronization.
 */
class MobileEventAnalyticsService
{
    private const NON_SALES_DOCUMENT_TYPES = ['CM', 'ZT'];

    /** @var array<string, array<int, string>> */
    private array $zoneStoreNames = [];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(Event $event, array $filters): array
    {
        $event->loadMissing('latestActiveReportImport');
        $rows = $this->applyFilters($this->salesRows($event->id), $event->id, $filters);
        $tickets = $this->applyFilters($this->salesTickets($event->id), $event->id, $filters)->count();
        $totals = (clone $rows)
            ->selectRaw('COALESCE(SUM(total_sum), 0) as total_sales')
            ->selectRaw("COUNT(DISTINCT CASE WHEN store_name IS NOT NULL AND store_name != '' THEN store_name END) as stores_count")
            ->first();
        $totalSales = (float) ($totals?->total_sales ?? 0);

        $topStores = (clone $rows)
            ->whereNotNull('store_name')
            ->where('store_name', '!=', '')
            ->selectRaw('store_name, COALESCE(SUM(total_sum), 0) as total_sales')
            ->groupBy('store_name')
            ->orderByDesc('total_sales')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => [
                'store_name' => (string) $row->store_name,
                'total_sales' => round((float) $row->total_sales, 4),
            ])
            ->values();

        return [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'event_date' => $event->event_date?->toISOString(),
            ],
            'summary' => [
                'total_sales' => round($totalSales, 4),
                'stores_count' => (int) ($totals?->stores_count ?? 0),
                'tickets_count' => $tickets,
                'average_ticket' => $tickets > 0 ? round($totalSales / $tickets, 4) : 0.0,
                'machines_count' => (int) ($event->latestActiveReportImport?->summary['machines_count'] ?? 0),
                'last_synced_at' => $event->latestActiveReportImport?->imported_at?->toISOString(),
            ],
            'hourly_sales' => $this->hourly($event->id, $filters),
            'top_products' => $this->products($event, $filters)['items']->take(6)->values(),
            'top_stores' => $topStores,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filterOptions(Event $event): array
    {
        $rows = $this->salesRows($event->id);
        $dates = (clone $rows)
            ->selectRaw('MIN(sale_date) as minimum, MAX(sale_date) as maximum')
            ->first();

        $stores = (clone $rows)
            ->whereNotNull('store_name')
            ->where('store_name', '!=', '')
            ->selectRaw('store_name, store_code, COALESCE(SUM(rows_count), 0) as rows_count')
            ->groupBy('store_name', 'store_code')
            ->orderBy('store_name')
            ->get()
            ->map(fn (object $row): array => [
                'value' => (string) $row->store_name,
                'label' => (string) $row->store_name,
                'code' => $row->store_code,
                'rows_count' => (int) $row->rows_count,
            ])
            ->values();

        $zones = $stores
            ->groupBy(fn (array $store): string => $this->zoneLabel($store['label']))
            ->map(fn (Collection $members, string $label): array => [
                'value' => $label,
                'label' => $label,
                'stores_count' => $members->count(),
                'rows_count' => (int) $members->sum('rows_count'),
            ])
            ->sortBy(fn (array $zone): string => Str::lower($zone['label']))
            ->values();

        $products = (clone $rows)
            ->whereNotNull('product_code')
            ->where('product_code', '!=', '')
            ->selectRaw('product_code, description, COALESCE(SUM(rows_count), 0) as rows_count')
            ->groupBy('product_code', 'description')
            ->orderBy('description')
            ->get()
            ->map(fn (object $row): array => [
                'value' => (string) $row->product_code,
                'label' => (string) ($row->description ?: $row->product_code),
                'rows_count' => (int) $row->rows_count,
            ])
            ->values();

        return [
            'zones' => $zones,
            'stores' => $stores,
            'products' => $products,
            'date_bounds' => [
                'from' => $this->dateString($dates?->minimum),
                'to' => $this->dateString($dates?->maximum),
            ],
            'hours' => collect(range(0, 23))->map(fn (int $hour): array => [
                'value' => $hour,
                'label' => sprintf('%02d:00', $hour),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function products(Event $event, array $filters): array
    {
        $query = $this->applyFilters($this->productRows($event), $event->id, $filters, true);
        $totals = (clone $query)
            ->selectRaw('COALESCE(SUM(sold_quantity_total), 0) as sold_quantity')
            ->selectRaw('COALESCE(SUM(offered_quantity_total), 0) as offered_quantity')
            ->selectRaw('COALESCE(SUM(total_sum), 0) as total_sales')
            ->first();
        $productsCount = (clone $query)
            ->selectRaw("CASE WHEN doc_type = 'ZT' THEN 'ZT-CARD' ELSE product_code END as product_key")
            ->selectRaw("CASE WHEN doc_type = 'ZT' THEN 'Contactless' ELSE description END as description_key")
            ->groupByRaw("CASE WHEN doc_type = 'ZT' THEN 'ZT-CARD' ELSE product_code END")
            ->groupByRaw("CASE WHEN doc_type = 'ZT' THEN 'Contactless' ELSE description END")
            ->get()
            ->count();
        $items = (clone $query)
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->selectRaw("CASE WHEN doc_type = 'ZT' THEN 'ZT-CARD' ELSE product_code END as product_code")
            ->selectRaw("CASE WHEN doc_type = 'ZT' THEN 'Contactless' ELSE description END as description")
            ->selectRaw('COALESCE(SUM(sold_quantity_total), 0) as sold_quantity')
            ->selectRaw('COALESCE(SUM(offered_quantity_total), 0) as offered_quantity')
            ->selectRaw('COALESCE(SUM(quantity_total), 0) as served_quantity')
            ->selectRaw('COALESCE(SUM(total_sum), 0) as total_sales')
            ->groupByRaw("CASE WHEN doc_type = 'ZT' THEN 'ZT-CARD' ELSE product_code END")
            ->groupByRaw("CASE WHEN doc_type = 'ZT' THEN 'Contactless' ELSE description END")
            ->orderByDesc('served_quantity')
            ->orderByDesc('total_sales')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => [
                'product_code' => (string) ($row->product_code ?? ''),
                'description' => (string) $row->description,
                'sold_quantity' => round((float) $row->sold_quantity, 4),
                'offered_quantity' => round((float) $row->offered_quantity, 4),
                'served_quantity' => round((float) $row->served_quantity, 4),
                'total_sales' => round((float) $row->total_sales, 4),
            ])
            ->values();

        $sold = (float) ($totals?->sold_quantity ?? 0);
        $offered = (float) ($totals?->offered_quantity ?? 0);
        $served = $sold + $offered;

        $daily = (clone $query)
            ->whereNotNull('sale_date')
            ->selectRaw('sale_date')
            ->selectRaw('COALESCE(SUM(sold_quantity_total), 0) as sold_quantity')
            ->selectRaw('COALESCE(SUM(offered_quantity_total), 0) as offered_quantity')
            ->selectRaw('COALESCE(SUM(total_sum), 0) as total_sales')
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get()
            ->map(fn (object $row): array => [
                'date' => $this->dateString($row->sale_date),
                'label' => $this->dateLabel($row->sale_date),
                'sold_quantity' => round((float) $row->sold_quantity, 4),
                'offered_quantity' => round((float) $row->offered_quantity, 4),
                'total_sales' => round((float) $row->total_sales, 4),
            ])
            ->values();

        return [
            'summary' => [
                'sold_quantity' => round($sold, 4),
                'offered_quantity' => round($offered, 4),
                'served_quantity' => round($served, 4),
                'offer_share' => $served > 0 ? round(($offered / $served) * 100, 2) : 0.0,
                'total_sales' => round((float) ($totals?->total_sales ?? 0), 4),
                'products_count' => $productsCount,
            ],
            'items' => $items,
            'daily' => $daily,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function zones(Event $event, array $filters): array
    {
        $rows = $this->applyFilters($this->salesRows($event->id), $event->id, $filters);
        $tickets = $this->applyFilters($this->salesTickets($event->id), $event->id, $filters);

        $ticketsByStore = (clone $tickets)
            ->selectRaw('store_name, store_code, COUNT(*) as tickets_count')
            ->groupBy('store_name', 'store_code')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $this->storeKey($row->store_name, $row->store_code) => (int) $row->tickets_count,
            ]);

        $stores = (clone $rows)
            ->selectRaw('store_name, store_code')
            ->selectRaw('COALESCE(SUM(rows_count), 0) as rows_count')
            ->selectRaw('COALESCE(SUM(quantity_total), 0) as quantity_total')
            ->selectRaw('COALESCE(SUM(total_sum), 0) as total_sales')
            ->groupBy('store_name', 'store_code')
            ->get()
            ->map(fn (object $row): array => [
                'store_name' => (string) ($row->store_name ?: 'Sem device'),
                'store_code' => $row->store_code,
                'rows_count' => (int) $row->rows_count,
                'quantity_total' => round((float) $row->quantity_total, 4),
                'tickets_count' => (int) $ticketsByStore->get($this->storeKey($row->store_name, $row->store_code), 0),
                'total_sales' => round((float) $row->total_sales, 4),
            ]);

        $zones = $stores
            ->groupBy(fn (array $store): string => $this->zoneLabel($store['store_name']))
            ->map(function (Collection $devices, string $label): array {
                $sales = (float) $devices->sum('total_sales');
                $tickets = (int) $devices->sum('tickets_count');

                return [
                    'label' => $label,
                    'devices_count' => $devices->count(),
                    'tickets_count' => $tickets,
                    'quantity_total' => round((float) $devices->sum('quantity_total'), 4),
                    'total_sales' => round($sales, 4),
                    'average_ticket' => $tickets > 0 ? round($sales / $tickets, 4) : 0.0,
                    'items' => $devices->sortByDesc('total_sales')->values(),
                ];
            })
            ->sortByDesc('total_sales')
            ->values();

        $totalSales = (float) $zones->sum('total_sales');
        $zones = $zones->map(fn (array $zone): array => [
            ...$zone,
            'share' => $totalSales > 0 ? round(((float) $zone['total_sales'] / $totalSales) * 100, 2) : 0.0,
        ]);

        return [
            'summary' => [
                'total_sales' => round($totalSales, 4),
                'tickets_count' => (int) $zones->sum('tickets_count'),
                'devices_count' => $stores->count(),
                'zones_count' => $zones->count(),
                'leading_zone' => $zones->first(),
            ],
            'items' => $zones,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function performance(Event $event, array $filters): array
    {
        $products = $this->products($event, $filters);
        $zones = $this->zones($event, $filters);
        $hourly = $this->hourly($event->id, $filters);
        $peak = collect($hourly)->sortByDesc('total_sales')->first();

        return [
            'summary' => [
                'best_product' => $products['items']->first(),
                'most_served_product' => $products['items']->sortByDesc('served_quantity')->first(),
                'peak_hour' => $peak,
                'leading_zone' => $zones['items']->first(),
            ],
            'products' => $products['items']->take(12)->values(),
            'zones' => $zones['items'],
            'devices' => $zones['items']
                ->flatMap(fn (array $zone): Collection => collect($zone['items'])->map(fn (array $device): array => [
                    ...$device,
                    'zone' => $zone['label'],
                ]))
                ->sortByDesc('total_sales')
                ->take(50)
                ->values(),
            'hourly' => $hourly,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function payments(Event $event, array $filters): array
    {
        $documents = $this->paymentDocuments($event->id, $filters)->get();
        $rows = $this->applyFilters($this->salesRows($event->id), $event->id, $filters);

        $salesByStore = (clone $rows)
            ->selectRaw('store_name, store_code, COALESCE(SUM(total_sum), 0) as total_sales')
            ->groupBy('store_name', 'store_code')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $this->storeKey($row->store_name, $row->store_code) => [
                    'store_name' => (string) ($row->store_name ?: 'Sem device'),
                    'store_code' => $row->store_code,
                    'sales_total' => (float) $row->total_sales,
                ],
            ]);

        $summary = [
            'multibanco' => 0.0,
            'cash' => 0.0,
            'zticket' => 0.0,
            'other' => 0.0,
            'top_up_loaded' => 0.0,
            'top_up_spent' => 0.0,
            'other_movements' => 0.0,
        ];
        $reconciliation = $salesByStore->map(fn (array $item): array => [
            ...$item,
            'documents_count' => 0,
            'multibanco' => 0.0,
            'cash' => 0.0,
            'zticket' => 0.0,
            'other' => 0.0,
        ]);
        $documentKeys = [];
        $topUpKeys = [];
        $storeDocumentKeys = [];

        foreach ($documents as $document) {
            $amount = (float) ($document->total ?? 0);
            $category = $this->paymentCategory((string) ($document->payment_code ?? ''));
            $key = $this->storeKey($document->store_name, $document->store_code);
            $documentKey = $this->paymentDocumentKey($document);

            if ($this->isTopUp($document)) {
                $summary['top_up_loaded'] += $amount;
                $topUpKeys[$documentKey] = true;

                continue;
            }

            $docType = Str::upper(trim((string) ($document->doc_type ?? '')));
            if (in_array($docType, self::NON_SALES_DOCUMENT_TYPES, true)) {
                $summary['other_movements'] += $amount;

                continue;
            }

            $summary[$category] += $amount;
            if ($category === 'zticket') {
                $summary['top_up_spent'] += $amount;
            }
            $documentKeys[$documentKey] = true;
            $item = $reconciliation->get($key, [
                'store_name' => (string) ($document->store_name ?: 'Sem device'),
                'store_code' => $document->store_code,
                'sales_total' => 0.0,
                'documents_count' => 0,
                'multibanco' => 0.0,
                'cash' => 0.0,
                'zticket' => 0.0,
                'other' => 0.0,
            ]);
            $item[$category] += $amount;
            if (! isset($storeDocumentKeys[$key][$documentKey])) {
                $item['documents_count']++;
                $storeDocumentKeys[$key][$documentKey] = true;
            }
            $reconciliation->put($key, $item);
        }

        $normalized = $reconciliation
            ->map(function (array $item): array {
                $payments = (float) $item['multibanco'] + (float) $item['cash']
                    + (float) $item['zticket'] + (float) $item['other'];

                return [
                    ...$item,
                    'multibanco' => round((float) $item['multibanco'], 4),
                    'cash' => round((float) $item['cash'], 4),
                    'zticket' => round((float) $item['zticket'], 4),
                    'other' => round((float) $item['other'], 4),
                    'payments_total' => round($payments, 4),
                    'sales_total' => round((float) $item['sales_total'], 4),
                    'difference' => round($payments - (float) $item['sales_total'], 4),
                ];
            })
            ->sortByDesc('payments_total')
            ->values();

        $salesTotal = (float) $summary['multibanco'] + (float) $summary['cash']
            + (float) $summary['zticket'] + (float) $summary['other'];
        $summary = [
            ...collect($summary)->map(fn (float $value): float => round($value, 4))->all(),
            'available' => $documents->isNotEmpty(),
            'documents_count' => count($documentKeys),
            'top_up_documents_count' => count($topUpKeys),
            'sales_total' => round($salesTotal, 4),
            'total_without_zt' => round($salesTotal, 4),
            'total_with_zt' => round($salesTotal + (float) $summary['top_up_loaded'], 4),
            'movement_total' => round($salesTotal + (float) $summary['top_up_loaded'] + (float) $summary['other_movements'], 4),
            'top_up_remaining' => round(max((float) $summary['top_up_loaded'] - (float) $summary['top_up_spent'], 0), 4),
        ];

        return [
            'summary' => $summary,
            'reconciliation' => [
                'totals' => [
                    'payments_total' => round((float) $normalized->sum('payments_total'), 4),
                    'sales_total' => round((float) $normalized->sum('sales_total'), 4),
                    'difference' => round((float) $normalized->sum('difference'), 4),
                ],
                'items' => $normalized,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function comparison(Event $event): array
    {
        $previous = Event::query()
            ->where('client_id', $event->client_id)
            ->where('id', '!=', $event->id)
            ->where('event_date', '<', $event->event_date)
            ->whereHas('activeReportImports')
            ->orderByDesc('event_date')
            ->first();

        $previous ??= Event::query()
            ->where('client_id', $event->client_id)
            ->where('id', '!=', $event->id)
            ->whereHas('activeReportImports')
            ->orderByDesc('event_date')
            ->first();

        if ($previous === null) {
            return ['available' => false, 'message' => 'Ainda não existe outro evento sincronizado para comparar.'];
        }

        $current = $this->comparisonSnapshot($event);
        $previousSnapshot = $this->comparisonSnapshot($previous);
        $definitions = [
            ['key' => 'total_sales', 'label' => 'Total faturado', 'format' => 'currency'],
            ['key' => 'tickets_count', 'label' => 'Transações', 'format' => 'number'],
            ['key' => 'machines_count', 'label' => 'Devices', 'format' => 'number'],
            ['key' => 'zones_count', 'label' => 'Zonas', 'format' => 'number'],
            ['key' => 'average_ticket', 'label' => 'Ticket médio', 'format' => 'currency'],
            ['key' => 'average_per_device', 'label' => 'Média por device', 'format' => 'currency'],
        ];

        return [
            'available' => true,
            'current' => $current,
            'previous' => $previousSnapshot,
            'total_variation' => $this->variation($current['total_sales'], $previousSnapshot['total_sales']),
            'metrics' => collect($definitions)->map(fn (array $definition): array => [
                ...$definition,
                'current' => $current[$definition['key']],
                'previous' => $previousSnapshot[$definition['key']],
                'variation' => $this->variation($current[$definition['key']], $previousSnapshot[$definition['key']]),
            ])->all(),
            'payments' => collect([
                ['key' => 'multibanco', 'label' => 'Multibanco'],
                ['key' => 'cash', 'label' => 'Dinheiro'],
                ['key' => 'zticket', 'label' => 'ZT - Card'],
                ['key' => 'other', 'label' => 'Outros'],
            ])->map(fn (array $definition): array => [
                ...$definition,
                'current' => $current['payments'][$definition['key']],
                'previous' => $previousSnapshot['payments'][$definition['key']],
                'variation' => $this->variation(
                    $current['payments'][$definition['key']],
                    $previousSnapshot['payments'][$definition['key']],
                ),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function hourly(int $eventId, array $filters): array
    {
        $tickets = $this->applyFilters($this->salesTickets($eventId), $eventId, $filters)
            ->whereNotNull('sale_hour')
            ->selectRaw('sale_hour, COUNT(*) as tickets_count')
            ->groupBy('sale_hour')
            ->pluck('tickets_count', 'sale_hour');

        return $this->applyFilters($this->salesRows($eventId), $eventId, $filters)
            ->whereNotNull('sale_hour')
            ->selectRaw('sale_hour, COALESCE(SUM(total_sum), 0) as total_sales')
            ->groupBy('sale_hour')
            ->orderBy('sale_hour')
            ->get()
            ->map(fn (object $row): array => [
                'hour' => (int) $row->sale_hour,
                'hour_label' => sprintf('%02d:00', (int) $row->sale_hour),
                'total_sales' => round((float) $row->total_sales, 4),
                'tickets_count' => (int) ($tickets->get($row->sale_hour) ?? 0),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function comparisonSnapshot(Event $event): array
    {
        $event->loadMissing('latestActiveReportImport');
        $rows = $this->salesRows($event->id);
        $total = (float) (clone $rows)->sum('total_sum');
        $tickets = $this->salesTickets($event->id)->count();
        $zones = $this->zones($event, []);
        $payments = $this->payments($event, [])['summary'];
        $machines = (int) ($event->latestActiveReportImport?->summary['machines_count'] ?? 0);

        return [
            'event_id' => $event->id,
            'title' => $event->title,
            'event_date' => $event->event_date?->toISOString(),
            'total_sales' => round($total, 4),
            'tickets_count' => $tickets,
            'machines_count' => $machines,
            'zones_count' => (int) $zones['summary']['zones_count'],
            'average_ticket' => $tickets > 0 ? round($total / $tickets, 4) : 0.0,
            'average_per_device' => $machines > 0 ? round($total / $machines, 4) : 0.0,
            'payments' => [
                'multibanco' => (float) $payments['multibanco'],
                'cash' => (float) $payments['cash'],
                'zticket' => (float) $payments['zticket'],
                'other' => (float) $payments['other'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, int $eventId, array $filters, bool $withProduct = false): Builder
    {
        $zones = collect($filters['bar_groups'] ?? [])->filter()->values()->all();
        if ($zones !== []) {
            $query->whereIn('store_name', $this->storeNamesForZones($eventId, $zones));
        }

        if (filled($filters['store'] ?? null)) {
            $query->where('store_name', $filters['store']);
        }

        if ($withProduct && filled($filters['product'] ?? null)) {
            $query->where('product_code', $filters['product']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->where('sale_date', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->where('sale_date', '<=', $filters['date_to']);
        }

        $from = $filters['hour_from'] ?? null;
        $to = $filters['hour_to'] ?? null;
        if ($from !== null || $to !== null) {
            $query->whereNotNull('sale_hour')->where(function (Builder $hours) use ($from, $to): void {
                if ($from !== null && $to !== null && (int) $from > (int) $to) {
                    $hours->where('sale_hour', '>=', (int) $from)->orWhere('sale_hour', '<=', (int) $to);
                } else {
                    if ($from !== null) {
                        $hours->where('sale_hour', '>=', (int) $from);
                    }
                    if ($to !== null) {
                        $hours->where('sale_hour', '<=', (int) $to);
                    }
                }
            });
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function paymentDocuments(int $eventId, array $filters): Builder
    {
        $query = EventReportPaymentDocument::query()->where('event_id', $eventId);
        $zones = collect($filters['bar_groups'] ?? [])->filter()->values()->all();
        if ($zones !== []) {
            $query->whereIn('store_name', $this->storeNamesForZones($eventId, $zones));
        }
        if (filled($filters['store'] ?? null)) {
            $query->where('store_name', $filters['store']);
        }
        if (filled($filters['date_from'] ?? null)) {
            $query->where('sale_date', '>=', $filters['date_from']);
        }
        if (filled($filters['date_to'] ?? null)) {
            $query->where('sale_date', '<=', $filters['date_to']);
        }

        $from = $filters['hour_from'] ?? null;
        $to = $filters['hour_to'] ?? null;
        if ($from !== null || $to !== null) {
            $driver = $query->getModel()->getConnection()->getDriverName();
            $expression = match ($driver) {
                'pgsql' => 'CAST(EXTRACT(HOUR FROM sale_datetime) AS INTEGER)',
                'mysql', 'mariadb' => 'HOUR(sale_datetime)',
                default => "CAST(strftime('%H', sale_datetime) AS INTEGER)",
            };
            $query->whereNotNull('sale_datetime')->where(function (Builder $hours) use ($from, $to, $expression): void {
                if ($from !== null && $to !== null && (int) $from > (int) $to) {
                    $hours->whereRaw("{$expression} >= ?", [(int) $from])->orWhereRaw("{$expression} <= ?", [(int) $to]);
                } else {
                    if ($from !== null) {
                        $hours->whereRaw("{$expression} >= ?", [(int) $from]);
                    }
                    if ($to !== null) {
                        $hours->whereRaw("{$expression} <= ?", [(int) $to]);
                    }
                }
            });
        }

        return $query;
    }

    private function salesRows(int $eventId): Builder
    {
        return EventReportRowAggregate::query()
            ->where('event_id', $eventId)
            ->where(fn (Builder $query) => $query->whereNull('doc_type')->orWhereNotIn('doc_type', self::NON_SALES_DOCUMENT_TYPES));
    }

    private function productRows(Event $event): Builder
    {
        return EventReportRowAggregate::query()
            ->where('event_id', $event->id)
            ->where(fn (Builder $query) => $query->whereNull('doc_type')->orWhere('doc_type', '!=', 'CM'))
            ->when(! $event->show_zt_card, fn (Builder $query) => $query
                ->where(fn (Builder $scope) => $scope->whereNull('doc_type')->orWhere('doc_type', '!=', 'ZT')));
    }

    private function salesTickets(int $eventId): Builder
    {
        return EventReportTicketAggregate::query()
            ->where('event_id', $eventId)
            ->where(fn (Builder $query) => $query->whereNull('doc_type')->orWhereNotIn('doc_type', self::NON_SALES_DOCUMENT_TYPES));
    }

    /** @param array<int, string> $zones @return array<int, string> */
    private function storeNamesForZones(int $eventId, array $zones): array
    {
        $cacheKey = $eventId.'|'.implode('|', $zones);

        return $this->zoneStoreNames[$cacheKey] ??= EventReportRowAggregate::query()
            ->where('event_id', $eventId)
            ->whereNotNull('store_name')
            ->distinct()
            ->pluck('store_name')
            ->filter(fn (?string $name): bool => in_array($this->zoneLabel($name), $zones, true))
            ->values()
            ->all();
    }

    private function zoneLabel(?string $storeName): string
    {
        $name = trim((string) $storeName);
        if ($name === '') {
            return 'Sem zona';
        }
        if (preg_match('/^(top\s*up|bc\s*top)\b/i', $name)) {
            return 'Top Up';
        }
        if (preg_match('/\b(bar\s*vip|vip)\b/i', $name)) {
            return 'Bar Vip';
        }
        if (preg_match('/\bbar\s*(\d+)\b/i', $name, $matches)) {
            return 'Bar '.(int) $matches[1];
        }
        if (preg_match('/\bbengaleiro\b/i', $name)) {
            return 'Bengaleiro';
        }
        if (preg_match('/\bbilheteira\b/i', $name)) {
            return 'Bilheteira';
        }

        return $name;
    }

    private function paymentCategory(string $code): string
    {
        return match ($code) {
            '1' => 'cash',
            '3', '4', '20' => 'multibanco',
            '10', '12', '14', '56' => 'zticket',
            default => 'other',
        };
    }

    private function isTopUp(object $document): bool
    {
        $type = Str::upper(trim((string) ($document->doc_type ?? '')));

        return $type !== ''
            ? $type === 'ZT'
            : preg_match('/^(top\s*up|bc\s*top)\b/i', trim((string) ($document->store_name ?? ''))) === 1;
    }

    private function paymentDocumentKey(object $document): string
    {
        return implode('|', [
            $document->machine_client_id ?? $document->store_code ?? '',
            $document->doc_type ?? '',
            $document->document_series ?? '',
            $document->document_number ?? '',
        ]);
    }

    private function storeKey(?string $storeName, ?string $storeCode): string
    {
        return filled($storeCode)
            ? 'code:'.trim((string) $storeCode)
            : 'name:'.Str::lower(trim((string) $storeName));
    }

    private function variation(float|int $current, float|int $previous): ?float
    {
        return abs((float) $previous) < 0.0001
            ? null
            : round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 1);
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->toDateString();
    }

    private function dateLabel(mixed $value): string
    {
        return CarbonImmutable::parse($value)->locale('pt_PT')->translatedFormat('d M');
    }
}
