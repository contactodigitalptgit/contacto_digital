<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventReportRowAggregate;
use App\Models\EventReportTicketAggregate;
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
        $event->loadMissing('latestActiveReportImport');

        $totals = $this->salesRowsQuery($event->id)
            ->selectRaw('COALESCE(SUM(total_sum), 0) as total_sales')
            ->selectRaw("COUNT(DISTINCT CASE WHEN store_name IS NOT NULL AND store_name != '' THEN store_name END) as stores_count")
            ->first();

        $ticketsCount = $this->salesTicketsQuery($event->id)->count();
        $totalSales = (float) ($totals?->total_sales ?? 0);

        return response()->json([
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
            ],
            'summary' => [
                'total_sales' => $totalSales,
                'stores_count' => (int) ($totals?->stores_count ?? 0),
                'tickets_count' => $ticketsCount,
                'average_ticket' => $ticketsCount > 0 ? round($totalSales / $ticketsCount, 4) : 0,
                'machines_count' => (int) ($event->latestActiveReportImport?->summary['machines_count'] ?? 0),
                'last_synced_at' => $event->latestActiveReportImport?->imported_at?->toISOString(),
            ],
        ]);
    }

    public function topStores(Request $request, Event $event): JsonResponse
    {
        $event = $this->authorizeEventForClient($request, $event);

        $stores = $this->salesRowsQuery($event->id)
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
            ]);

        return response()->json(['top_stores' => $stores]);
    }

    private function authorizeEventForClient(Request $request, Event $event): Event
    {
        $client = $request->user()->client()->firstOrFail();

        abort_unless($event->client_id === $client->id && $event->is_active, 404);

        return $event;
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
