<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Event;
use App\Services\EventReportImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(): Response
    {
        $events = Event::query()
            ->with(['client', 'latestActiveReportImport'])
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
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'event_date' => $event->event_date->toISOString(),
                'event_date_input' => $event->event_date->format('Y-m-d\TH:i'),
                'client_name' => $event->client->name,
                'client_id' => $event->client_id,
                'is_active' => $event->is_active,
                'report_summary' => $event->latestActiveReportImport ? [
                    'active_imports_count' => (int) $event->active_report_imports_count,
                    'active_rows_count' => (int) $event->active_report_rows_count,
                    'total' => (float) ($event->active_report_total_sum ?? 0),
                    'last_imported_at' => $event->latestActiveReportImport->imported_at?->toISOString(),
                    'last_filename' => $event->latestActiveReportImport->original_filename,
                ] : null,
            ]);

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
        EventReportImportService $importService,
    ): RedirectResponse {
        $validated = $request->validate([
            'import_strategy' => ['required', Rule::in(['sum', 'replace'])],
            'report_file' => ['required', File::types(['xls', 'xlsx'])->max(20 * 1024)],
        ]);

        $importService->import(
            $event,
            $request->file('report_file'),
            $validated['import_strategy'],
            $request->user(),
        );

        return to_route('admin.events.index');
    }
}
