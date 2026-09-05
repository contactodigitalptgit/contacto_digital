<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventReportRowAggregate;
use App\Models\EventReportTicketAggregate;
use App\Services\DashboardConfigurationService;
use App\Services\MobileEventAnalyticsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile-app read API for the client-facing event dashboard (see
 * docs/PLANO_DE_PERFORMANCE_SINCRONIZACAO.md — app Flutter, cliente
 * acompanhar o evento). Deliberately NOT a thin wrapper around
 * EventDashboardController: that controller's private methods are built
 * for the web dashboard's row-level filters, admin preview mode and
 * Inertia-deferred props, none of which the mobile v1 needs. This reads
 * the same PERF-401 aggregate tables
 * (event_report_row_aggregates/event_report_ticket_aggregates) so the
 * numbers match the web dashboard exactly, without any of that complexity.
 */
class EventSummaryController extends Controller
{
    // Kept identical to EventDashboardController::NON_SALES_DOCUMENT_TYPES
    // so "total sales" means the same thing on web and mobile.
    private const NON_SALES_DOCUMENT_TYPES = ['CM', 'ZT'];

    public function __construct(
        private readonly MobileEventAnalyticsService $analytics,
        private readonly DashboardConfigurationService $dashboardConfiguration,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $client = $request->user()->client()->firstOrFail();

        $events = $client->events()
            ->where('is_active', true)
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'event_date' => $event->event_date->toISOString(),
            ]);

        return response()->json(['events' => $events]);
    }

    public function summary(Request $request, Event $event): JsonResponse
    {
        $event = $this->authorizeEventForClient($request, $event);

        return response()->json([
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
            ],
            'summary' => $this->summaryData($event),
        ]);
    }

    public function topStores(Request $request, Event $event): JsonResponse
    {
        $event = $this->authorizeEventForClient($request, $event);

        return response()->json(['top_stores' => $this->topStoresData($event->id)]);
    }

    /**
     * One mobile request returns every visible dashboard section. Keeping the
     * payload consolidated avoids repeating authentication and network latency
     * on event refresh while all queries remain on the PERF-401 aggregates.
     */
    public function dashboard(Request $request, Event $event): JsonResponse
    {
        $event = $this->authorizeEventForClient($request, $event);

        return response()->json($this->analytics->dashboard($event, $this->validatedFilters($request)));
    }

    public function configuration(Request $request, Event $event): JsonResponse
    {
        $event = $this->authorizeEventForClient($request, $event);

        return response()->json([
            'configuration' => $this->dashboardConfiguration->resolve($event),
        ]);
    }

    public function filters(Request $request, Event $event): JsonResponse
    {
        $event = $this->authorizeEventForClient($request, $event);

        return response()->json(['filters' => $this->analytics->filterOptions($event)]);
    }

    public function products(Request $request, Event $event): JsonResponse
    {
        $event = $this->authorizeEventForClient($request, $event);

        return response()->json($this->analytics->products($event, $this->validatedFilters($request)));
    }

    public function payments(Request $request, Event $event): JsonResponse
    {
        $event = $this->authorizeEventForClient($request, $event);

        return response()->json($this->analytics->payments($event, $this->validatedFilters($request)));
    }

    public function zones(Request $request, Event $event): JsonResponse
    {
        $event = $this->authorizeEventForClient($request, $event);

        return response()->json($this->analytics->zones($event, $this->validatedFilters($request)));
    }

    public function performance(Request $request, Event $event): JsonResponse
    {
        $event = $this->authorizeEventForClient($request, $event);

        return response()->json($this->analytics->performance($event, $this->validatedFilters($request)));
    }

    public function comparison(Request $request, Event $event): JsonResponse
    {
        $event = $this->authorizeEventForClient($request, $event);

        return response()->json($this->analytics->comparison($event));
    }

    /**
     * @return array<string, float|int|string|null>
     */
    private function summaryData(Event $event): array
    {
        $event->loadMissing('latestActiveReportImport');

        $totals = $this->salesRowsQuery($event->id)
            ->selectRaw('COALESCE(SUM(total_sum), 0) as total_sales')
            ->selectRaw("COUNT(DISTINCT CASE WHEN store_name IS NOT NULL AND store_name != '' THEN store_name END) as stores_count")
            ->first();

        $ticketsCount = $this->salesTicketsQuery($event->id)->count();
        $totalSales = (float) ($totals?->total_sales ?? 0);

        return [
            'total_sales' => $totalSales,
            'stores_count' => (int) ($totals?->stores_count ?? 0),
            'tickets_count' => $ticketsCount,
            'average_ticket' => $ticketsCount > 0 ? round($totalSales / $ticketsCount, 4) : 0,
            'machines_count' => (int) ($event->latestActiveReportImport?->summary['machines_count'] ?? 0),
            'last_synced_at' => $event->latestActiveReportImport?->imported_at?->toISOString(),
        ];
    }

    /**
     * @return array<int, array{store_name: string, total_sales: float}>
     */
    private function topStoresData(int $eventId): array
    {
        return $this->salesRowsQuery($eventId)
            ->whereNotNull('store_name')
            ->where('store_name', '!=', '')
            ->selectRaw('store_name')
            ->selectRaw('COALESCE(SUM(total_sum), 0) as total_sales')
            ->groupBy('store_name')
            ->orderByDesc('total_sales')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => [
                'store_name' => $row->store_name,
                'total_sales' => round((float) $row->total_sales, 4),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, float|string>>
     */
    private function topProductsData(int $eventId): array
    {
        return $this->salesRowsQuery($eventId)
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->selectRaw('product_code, description')
            ->selectRaw('COALESCE(SUM(sold_quantity_total), 0) as sold_quantity')
            ->selectRaw('COALESCE(SUM(offered_quantity_total), 0) as offered_quantity')
            ->selectRaw('COALESCE(SUM(total_sum), 0) as total_sales')
            ->groupBy('product_code', 'description')
            ->orderByDesc('total_sales')
            ->limit(6)
            ->get()
            ->map(fn (object $row): array => [
                'product_code' => (string) ($row->product_code ?? ''),
                'description' => (string) $row->description,
                'sold_quantity' => round((float) $row->sold_quantity, 4),
                'offered_quantity' => round((float) $row->offered_quantity, 4),
                'total_sales' => round((float) $row->total_sales, 4),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{hour: int, hour_label: string, total_sales: float, tickets_count: int}>
     */
    private function hourlySalesData(int $eventId): array
    {
        $ticketsByHour = $this->salesTicketsQuery($eventId)
            ->whereNotNull('sale_hour')
            ->selectRaw('sale_hour, COUNT(*) as tickets_count')
            ->groupBy('sale_hour')
            ->pluck('tickets_count', 'sale_hour');

        return $this->salesRowsQuery($eventId)
            ->whereNotNull('sale_hour')
            ->selectRaw('sale_hour, COALESCE(SUM(total_sum), 0) as total_sales')
            ->groupBy('sale_hour')
            ->orderBy('sale_hour')
            ->get()
            ->map(function (object $row) use ($ticketsByHour): array {
                $hour = (int) $row->sale_hour;

                return [
                    'hour' => $hour,
                    'hour_label' => sprintf('%02d:00', $hour),
                    'total_sales' => round((float) $row->total_sales, 4),
                    'tickets_count' => (int) ($ticketsByHour->get($hour) ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    private function authorizeEventForClient(Request $request, Event $event): Event
    {
        $client = $request->user()->client()->firstOrFail();

        abort_unless($event->client_id === $client->id && $event->is_active, 404);

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'bar_groups' => ['sometimes', 'array', 'max:50'],
            'bar_groups.*' => ['string', 'max:255'],
            'store' => ['nullable', 'string', 'max:255'],
            'product' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'hour_from' => ['nullable', 'integer', 'between:0,23'],
            'hour_to' => ['nullable', 'integer', 'between:0,23'],
        ]);

        return [
            'bar_groups' => collect($validated['bar_groups'] ?? [])
                ->map(fn (mixed $value): string => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'store' => trim((string) ($validated['store'] ?? '')),
            'product' => trim((string) ($validated['product'] ?? '')),
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'hour_from' => array_key_exists('hour_from', $validated) ? (int) $validated['hour_from'] : null,
            'hour_to' => array_key_exists('hour_to', $validated) ? (int) $validated['hour_to'] : null,
        ];
    }

    private function salesRowsQuery(int $eventId): Builder
    {
        return EventReportRowAggregate::query()
            ->where('event_id', $eventId)
            ->where(fn (Builder $builder) => $builder
                ->whereNull('doc_type')
                ->orWhereNotIn('doc_type', self::NON_SALES_DOCUMENT_TYPES));
    }

    private function salesTicketsQuery(int $eventId): Builder
    {
        return EventReportTicketAggregate::query()
            ->where('event_id', $eventId)
            ->where(fn (Builder $builder) => $builder
                ->whereNull('doc_type')
                ->orWhereNotIn('doc_type', self::NON_SALES_DOCUMENT_TYPES));
    }
}
