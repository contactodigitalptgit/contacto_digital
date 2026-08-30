<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncEventReportJob;
use App\Models\Client;
use App\Models\Event;
use App\Services\EventReportSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Events/Index', [
            'events' => function () {
                return Event::query()
                    ->select([
                        'id',
                        'client_id',
                        'title',
                        'description',
                        'event_date',
                        'report_starts_at',
                        'report_ends_at',
                        'show_zt_card',
                        'is_active',
                    ])
                    ->with([
                        'latestActiveReportImport' => fn ($query) => $query->select([
                            'event_report_imports.id',
                            'event_report_imports.event_id',
                            'event_report_imports.summary',
                            'event_report_imports.imported_rows_count',
                            'event_report_imports.imported_at',
                            'event_report_imports.status',
                        ]),
                        'latestReportImport' => fn ($query) => $query->select([
                            'event_report_imports.id',
                            'event_report_imports.event_id',
                            'event_report_imports.summary',
                            'event_report_imports.created_at',
                            'event_report_imports.status',
                        ]),
                        'client:id,name',
                    ])
                    ->withCount([
                        'zonesoftMachines as active_zonesoft_machines_count' => fn ($query) => $query->where('is_active', true),
                        'processingReportImports as processing_report_imports_count',
                    ])
                    ->orderByDesc('is_active')
                    ->orderBy('event_date')
                    ->get()
                    ->map(function (Event $event): array {
                        $latestActiveImport = $event->latestActiveReportImport;
                        $latestImport = $event->latestReportImport;
                        $hasAnyImport = $latestActiveImport !== null || $latestImport !== null;
                        $latestActiveImportSummary = is_array($latestActiveImport?->summary)
                            ? $latestActiveImport->summary
                            : [];
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
                            'show_zt_card' => $event->show_zt_card,
                            'client_name' => $event->client->name,
                            'client_id' => $event->client_id,
                            'is_active' => $event->is_active,
                            'available_machine_count' => (int) ($event->active_zonesoft_machines_count ?? 0),
                            'report_summary' => $hasAnyImport ? [
                                'active_syncs_count' => (int) $event->processing_report_imports_count,
                                'active_rows_count' => (int) ($latestActiveImport?->imported_rows_count ?? 0),
                                'total' => (float) ($latestActiveImportSummary['sales_total'] ?? 0),
                                'last_synced_at' => $latestActiveImport?->imported_at?->toISOString(),
                                'machines_count' => (int) ($latestActiveImport?->summary['machines_count'] ?? 0),
                                'status' => $latestImport?->status ?? ($latestActiveImport ? 'completed' : null),
                                'started_at' => $latestImport?->created_at?->toISOString(),
                                'error' => is_string($latestImportSummary['error'] ?? null)
                                    ? $latestImportSummary['error']
                                    : null,
                                'failed_machines' => $this->normalizeMachineIssues(
                                    $latestImportSummary['failed_machines'] ?? [],
                                ),
                                'machine_warnings' => $this->normalizeMachineIssues(
                                    $latestImportSummary['machine_warnings'] ?? [],
                                ),
                            ] : null,
                        ];
                    });
            },
            'clients' => fn () => Client::query()
                ->orderBy('name')
                ->get(['id', 'name', 'business_name']),
        ]);
    }

    /**
     * @param  mixed  $issues
     * @return list<array{machine_id:int,store_id:int,store_label:?string,zs_client_id:string,message:string}>
     */
    private function normalizeMachineIssues(mixed $issues): array
    {
        if (! is_array($issues)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $issue): ?array {
            if (! is_array($issue)) {
                return null;
            }

            $machineId = isset($issue['machine_id']) ? (int) $issue['machine_id'] : 0;
            $storeId = isset($issue['store_id']) ? (int) $issue['store_id'] : 0;
            $zsClientId = trim((string) ($issue['zs_client_id'] ?? ''));
            $message = trim((string) ($issue['message'] ?? ''));
            $storeLabel = isset($issue['store_label']) ? trim((string) $issue['store_label']) : '';

            if ($machineId <= 0 || $storeId <= 0 || $zsClientId === '' || $message === '') {
                return null;
            }

            return [
                'machine_id' => $machineId,
                'store_id' => $storeId,
                'store_label' => $storeLabel !== '' ? $storeLabel : null,
                'zs_client_id' => $zsClientId,
                'message' => $message,
            ];
        }, $issues)));
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
            'report_ends_at' => ['required', 'date', 'after_or_equal:event_date', 'after_or_equal:report_starts_at'],
            'show_zt_card' => ['sometimes', 'boolean'],
        ]);

        $validated['show_zt_card'] = $request->boolean('show_zt_card', true);

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
                'show_zt_card' => $event->show_zt_card,
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
            'report_ends_at' => ['required', 'date', 'after_or_equal:event_date', 'after_or_equal:report_starts_at'],
            'show_zt_card' => ['sometimes', 'boolean'],
        ]);

        $validated['show_zt_card'] = $request->boolean('show_zt_card', true);

        if (
            (int) $validated['client_id'] !== $event->client_id
            && $event->zonesoftMachines()->exists()
        ) {
            throw ValidationException::withMessages([
                'client_id' => 'Não é possível alterar o cliente enquanto o evento tiver integrações configuradas.',
            ]);
        }

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
        $validated = $request->validate([
            'redirect_to' => ['nullable', 'string', 'max:2048'],
        ]);
        $redirectTo = $this->resolveReportRedirect($validated['redirect_to'] ?? null);

        if (app()->runningUnitTests()) {
            $syncService->sync($event, $request->user());

            return redirect()->to($redirectTo);
        }

        $syncLog = $syncService->start($event, $request->user());

        if (app()->isLocal()) {
            $this->dispatchDetachedReportSync($syncLog->id, $event->id);

            return redirect()->to($redirectTo);
        }

        SyncEventReportJob::dispatch($syncLog->id, $event->id);

        return redirect()->to($redirectTo);
    }

    private function dispatchDetachedReportSync(int $importId, int $eventId): void
    {
        $command = sprintf(
            'cd %s && %s artisan events:sync-report-import %d >> %s 2>&1 &',
            escapeshellarg(base_path()),
            escapeshellarg(PHP_BINARY),
            $importId,
            escapeshellarg(storage_path('logs/event-report-sync-process.log')),
        );

        $result = Process::run($command);

        if ($result->successful()) {
            return;
        }

        report(new \RuntimeException(sprintf(
            'Nao foi possivel iniciar o processo destacado da sincronizacao do evento %d: %s',
            $eventId,
            trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : trim($result->output()),
        )));

        SyncEventReportJob::dispatchAfterResponse($importId, $eventId);
    }

    private function resolveReportRedirect(?string $redirectTo): string
    {
        if (is_string($redirectTo) && $redirectTo !== '' && str_starts_with($redirectTo, '/') && ! str_starts_with($redirectTo, '//')) {
            return $redirectTo;
        }

        return route('admin.events.index');
    }
}
