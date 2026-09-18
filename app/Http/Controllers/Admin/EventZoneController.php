<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventReportRow;
use App\Models\EventReportRowAggregate;
use App\Models\EventZone;
use App\Models\EventZoneAssignment;
use App\Services\EventZoneAttributionService;
use App\Services\EventZoneManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EventZoneController extends Controller
{
    private const BUSINESS_TIMEZONE = 'Europe/Lisbon';

    public function __construct(
        private readonly EventZoneManagementService $zoneManagement,
        private readonly EventZoneAttributionService $zoneAttribution,
    ) {}

    public function index(Event $event): Response
    {
        $event->load('client');
        $machines = $event->zonesoftMachines()->orderBy('store_id')->get();
        $referenceAt = $this->defaultEffectiveAt($event);
        $openAssignments = EventZoneAssignment::query()
            ->where('event_id', $event->id)
            ->where('starts_at', '<=', $referenceAt)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $referenceAt))
            ->get()
            ->keyBy('machine_id');
        $salesByZone = EventReportRowAggregate::query()
            ->where('event_id', $event->id)
            ->whereNotNull('event_zone_id')
            ->selectRaw('event_zone_id, COALESCE(SUM(total_sum), 0) as total_sales')
            ->groupBy('event_zone_id')
            ->pluck('total_sales', 'event_zone_id');
        $zones = EventZone::query()
            ->where('event_id', $event->id)
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $machinesByZone = $machines->groupBy(
            fn (ClientZoneSoftMachine $machine): int => (int) ($openAssignments->get($machine->id)?->event_zone_id ?? 0),
        );

        return Inertia::render('Admin/Events/ManageZones', [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'event_date' => $event->event_date?->toISOString(),
                'report_starts_at' => $event->report_starts_at?->toISOString(),
                'report_ends_at' => $event->report_ends_at?->toISOString(),
            ],
            'client' => [
                'id' => $event->client->id,
                'name' => $event->client->name,
            ],
            'initialized' => $zones->isNotEmpty(),
            'default_effective_at' => $this->defaultEffectiveAt($event)->format('Y-m-d\TH:i'),
            'zones' => $zones->map(fn (EventZone $zone): array => [
                'id' => $zone->id,
                'name' => $zone->name,
                'sort_order' => $zone->sort_order,
                'total_sales' => round((float) ($salesByZone[$zone->id] ?? 0), 4),
                'machines' => $this->serializeMachines($machinesByZone->get($zone->id, collect())),
            ])->values(),
            'unassigned_machines' => $this->serializeMachines($machinesByZone->get(0, collect())),
            'history' => EventZoneAssignment::query()
                ->select('event_zone_assignments.*')
                ->selectSub(
                    EventReportRow::query()
                        ->selectRaw('COALESCE(SUM(total), 0)')
                        ->whereColumn('event_report_rows.event_id', 'event_zone_assignments.event_id')
                        ->whereColumn('event_report_rows.machine_id', 'event_zone_assignments.machine_id')
                        ->whereColumn('event_report_rows.sale_datetime', '>=', 'event_zone_assignments.starts_at')
                        ->where(function ($query): void {
                            $query
                                ->whereNull('event_zone_assignments.ends_at')
                                ->orWhereColumn('event_report_rows.sale_datetime', '<', 'event_zone_assignments.ends_at');
                        })
                        ->where(fn ($query) => $query->whereNull('doc_type')->orWhereNotIn('doc_type', ['CM', 'ZT'])),
                    'sales_total',
                )
                ->with(['zone:id,name', 'machine:id,store_id,store_label', 'assignedBy:id,name'])
                ->where('event_id', $event->id)
                ->latest('starts_at')
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (EventZoneAssignment $assignment): array => [
                    'id' => $assignment->id,
                    'zone' => $assignment->zone?->name ?? 'Zona arquivada',
                    'machine' => $assignment->machine?->store_label
                        ?: 'Store '.($assignment->machine?->store_id ?? '—'),
                    'starts_at' => $assignment->starts_at->format('Y-m-d\TH:i:s'),
                    'ends_at' => $assignment->ends_at?->format('Y-m-d\TH:i:s'),
                    'source' => $assignment->source,
                    'assigned_by' => $assignment->assignedBy?->name,
                    'sales_total' => round((float) $assignment->sales_total, 4),
                ])->values(),
        ]);
    }

    public function initialize(Request $request, Event $event): RedirectResponse
    {
        $this->zoneManagement->initializeMissingMachines($event, $request->user());

        return to_route('admin.events.zones.manage', $event)
            ->with('success', 'Zonas iniciais geradas a partir dos nomes dos TPAs.');
    }

    public function store(Request $request, Event $event): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);
        $name = trim($validated['name']);
        $this->ensureUniqueName($event, $name);
        $nextOrder = ((int) EventZone::query()->where('event_id', $event->id)->max('sort_order')) + 1;

        EventZone::create([
            'event_id' => $event->id,
            'name' => $name,
            'sort_order' => $nextOrder,
        ]);
        $this->zoneAttribution->forget($event->id);

        return to_route('admin.events.zones.manage', $event)
            ->with('success', 'Zona criada com sucesso.');
    }

    public function update(Request $request, Event $event, EventZone $zone): RedirectResponse
    {
        $this->resolveZone($event, $zone);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);
        $name = trim($validated['name']);
        $this->ensureUniqueName($event, $name, $zone);

        $zone->update(['name' => $name]);
        $this->zoneAttribution->forget($event->id);

        return to_route('admin.events.zones.manage', $event)
            ->with('success', 'Zona atualizada com sucesso.');
    }

    public function destroy(Event $event, EventZone $zone): RedirectResponse
    {
        $this->resolveZone($event, $zone);

        $referenceAt = $this->defaultEffectiveAt($event);

        if ($zone->assignments()
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $referenceAt))
            ->exists()) {
            throw ValidationException::withMessages([
                'zone' => 'Mova todos os TPAs desta zona antes de a arquivar.',
            ]);
        }

        $zone->update(['archived_at' => now()]);
        $this->zoneAttribution->forget($event->id);

        return to_route('admin.events.zones.manage', $event)
            ->with('success', 'Zona arquivada. O histórico foi preservado.');
    }

    public function moveMachine(
        Request $request,
        Event $event,
        EventZone $zone,
        ClientZoneSoftMachine $machine,
    ): RedirectResponse {
        $this->resolveZone($event, $zone);
        abort_if($zone->archived_at !== null, 404);
        $validated = $request->validate([
            'effective_at' => ['required', 'date'],
        ]);
        $effectiveAt = CarbonImmutable::parse($validated['effective_at']);
        $startsAt = CarbonImmutable::instance($event->report_starts_at ?? $event->event_date);
        $endsAt = $event->report_ends_at ? CarbonImmutable::instance($event->report_ends_at) : null;

        if ($effectiveAt->lessThan($startsAt) || ($endsAt && $effectiveAt->greaterThan($endsAt))) {
            throw ValidationException::withMessages([
                'effective_at' => 'A mudança deve ocorrer dentro do período configurado para o evento.',
            ]);
        }

        $this->zoneManagement->moveMachine(
            $event,
            $zone,
            $machine,
            $effectiveAt,
            $request->user(),
        );

        return to_route('admin.events.zones.manage', $event)
            ->with('success', 'TPA movido e faturação recalculada pelo horário da mudança.');
    }

    private function resolveZone(Event $event, EventZone $zone): EventZone
    {
        abort_unless($zone->event_id === $event->id, 404);

        return $zone;
    }

    private function ensureUniqueName(Event $event, string $name, ?EventZone $except = null): void
    {
        $exists = EventZone::query()
            ->where('event_id', $event->id)
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Já existe uma zona com este nome neste evento.',
            ]);
        }
    }

    private function defaultEffectiveAt(Event $event): CarbonImmutable
    {
        $startsAt = CarbonImmutable::instance($event->report_starts_at ?? $event->event_date);
        $endsAt = $event->report_ends_at ? CarbonImmutable::instance($event->report_ends_at) : null;
        $localNow = CarbonImmutable::now(self::BUSINESS_TIMEZONE);
        $now = CarbonImmutable::parse($localNow->format('Y-m-d H:i:s'));

        if ($now->lessThan($startsAt)) {
            return $startsAt;
        }

        if ($endsAt && $now->greaterThan($endsAt)) {
            return $endsAt;
        }

        return $now;
    }

    /** @param Collection<int, ClientZoneSoftMachine> $machines */
    private function serializeMachines(Collection $machines): Collection
    {
        return $machines
            ->map(fn (ClientZoneSoftMachine $machine): array => [
                'id' => $machine->id,
                'store_id' => $machine->store_id,
                'store_label' => $machine->store_label,
                'zs_client_id' => $machine->zs_client_id,
                'is_active' => $machine->is_active,
            ])
            ->values();
    }
}
