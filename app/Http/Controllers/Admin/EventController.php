<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncEventReportJob;
use App\Models\Client;
use App\Models\Event;
use App\Services\EventReportSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(): Response
    {
        $events = Event::query()
            ->with([
                'latestActiveReportImport',
                'latestReportImport',
                'client' => fn ($query) => $query->withCount([
                    'zonesoftMachines as active_zonesoft_machines_count' => fn ($machineQuery) => $machineQuery->where('is_active', true),
                ]),
            ])
            ->withCount([
                'activeReportImports as active_report_imports_count',
                'reportRows as active_report_rows_count' => fn ($query) => $query->whereHas(
                    'reportImport',
                    fn ($importQuery) => $importQuery->where('is_active', true)->where('status', 'completed'),
                ),
            ])
            ->withSum([
                'reportRows as active_report_total_sum' => fn ($query) => $query->whereHas(
                    'reportImport',
                    fn ($importQuery) => $importQuery->where('is_active', true)->where('status', 'completed'),
                ),
            ], 'total')
            ->orderByDesc('is_active')
            ->orderBy('event_date')
            ->get()
            ->map(function (Event $event): array {
                $latestActiveImport = $event->latestActiveReportImport;
                $latestImport = $event->latestReportImport;
                $hasAnyImport = $latestActiveImport !== null || $latestImport !== null;
                $latestImportSummary = is_array($latestImport?->summary) ? $latestImport->summary : [];

                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'description' => $event->description,
                    'event_date' => $event->event_date->toISOString(),
                    'event_date_input' => $event->event_date->format('Y-m-d\TH:i'),
                    'report_starts_at' => $event->report_starts_at?->toISOString(),
                    'report_starts_at_input' => $event->report_starts_at?->format('Y-m-d\TH:i') ?? '',
                    'report_ends_at' => $event->report_ends_at?->toISOString(),
                    'report_ends_at_input' => $event->report_ends_at?->format('Y-m-d\TH:i') ?? '',
                    'client_name' => $event->client->name,
                    'client_id' => $event->client_id,
                    'is_active' => $event->is_active,
                    'available_machine_count' => (int) ($event->client->active_zonesoft_machines_count ?? 0),
                    'report_summary' => $hasAnyImport ? [
                        'active_syncs_count' => (int) $event->active_report_imports_count,
                        'active_rows_count' => (int) $event->active_report_rows_count,
                        'total' => (float) ($event->active_report_total_sum ?? 0),
                        'last_synced_at' => $latestActiveImport?->imported_at?->toISOString(),
                        'machines_count' => (int) ($latestActiveImport?->summary['machines_count'] ?? 0),
                        'status' => $latestImport?->status ?? ($latestActiveImport ? 'completed' : null),
                        'started_at' => $latestImport?->created_at?->toISOString(),
                        'error' => is_string($latestImportSummary['error'] ?? null)
                            ? $latestImportSummary['error']
                            : null,
                    ] : null,
                ];
            });

        return Inertia::render('Admin/Events/Index', [
            'events' => $events,
            'clients' => Client::query()
                ->orderBy('name')
                ->get(['id', 'name', 'business_name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Events/Create', [
            'clients' => Client::query()
                ->orderBy('name')
                ->get(['id', 'name', 'business_name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'event_date' => ['required', 'date'],
            'report_starts_at' => ['nullable', 'date'],
            'report_ends_at' => ['nullable', 'date', 'after_or_equal:report_starts_at'],
        ]);

        Event::create($validated);

        return to_route('admin.events.index');
    }

    public function edit(Event $event): Response
    {
        return Inertia::render('Admin/Events/Edit', [
            'event' => [
                'id' => $event->id,
                'client_id' => $event->client_id,
                'title' => $event->title,
                'description' => $event->description,
                'event_date' => $event->event_date->format('Y-m-d\TH:i'),
                'report_starts_at' => $event->report_starts_at?->format('Y-m-d\TH:i'),
                'report_ends_at' => $event->report_ends_at?->format('Y-m-d\TH:i'),
            ],
            'clients' => Client::query()
                ->orderBy('name')
                ->get(['id', 'name', 'business_name']),
        ]);
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'event_date' => ['required', 'date'],
            'report_starts_at' => ['nullable', 'date'],
            'report_ends_at' => ['nullable', 'date', 'after_or_equal:report_starts_at'],
        ]);

        $event->update($validated);

        return to_route('admin.events.index');
    }

    public function toggleStatus(Request $request, Event $event): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $event->update([
            'is_active' => $validated['is_active'],
        ]);

        return to_route('admin.events.index');
    }

    public function destroy(Event $event): RedirectResponse
    {
        $event->delete();

        return to_route('admin.events.index');
    }

    public function storeReport(
        Request $request,
        Event $event,
        EventReportSyncService $syncService,
    ): RedirectResponse {
        if (app()->runningUnitTests()) {
            $syncService->sync($event, $request->user());

            return to_route('admin.events.index');
        }

        $syncLog = $syncService->start($event, $request->user());

        SyncEventReportJob::dispatch($syncLog->id, $event->id);

        return to_route('admin.events.index');
    }
}
