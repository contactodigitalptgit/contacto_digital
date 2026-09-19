<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventReportPaymentDocument;
use App\Models\EventReportRow;
use App\Models\EventReportRowAggregate;
use App\Models\EventZone;
use App\Models\EventZoneAssignment;
use App\Models\EventZoneDay;
use App\Services\EventReportSyncService;
use App\Services\EventZoneAttributionService;
use App\Services\EventZoneManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EventZoneController extends Controller
{
    private const BUSINESS_TIMEZONE = 'Europe/Lisbon';

    public function __construct(
        private readonly EventZoneManagementService $zoneManagement,
        private readonly EventZoneAttributionService $zoneAttribution,
        private readonly EventReportSyncService $reportSync,
    ) {}

    public function index(Event $event): Response
    {
        $event->load('client');
        $machines = $event->zonesoftMachines()->orderBy('store_id')->get();
        $days = $event->zoneDays()->orderBy('starts_at')->get();
        $requestedDay = request()->query('day');
        $selectedDay = $days->firstWhere('id', (int) $requestedDay);
        if ($requestedDay !== 'legacy' && ! $selectedDay && $days->isNotEmpty()) {
            $now = $this->defaultEffectiveAt($event);
            $selectedDay = $days->first(fn (EventZoneDay $day): bool => $day->ends_at->greaterThan($now))
                ?? $days->last();
        }
        $referenceAt = $selectedDay
            ? $this->referenceAtForDay($selectedDay, $event)
            : $this->defaultEffectiveAt($event);
        $openAssignments = EventZoneAssignment::query()
            ->where('event_id', $event->id)
            ->where('starts_at', '<=', $referenceAt)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $referenceAt))
            ->when($selectedDay, fn ($query) => $query->whereHas('zone', fn ($zones) => $zones
                ->where('event_zone_day_id', $selectedDay->id)))
            ->when(! $selectedDay && $days->isNotEmpty(), fn ($query) => $query
                ->whereHas('zone', fn ($zones) => $zones->whereNull('event_zone_day_id')))
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
            ->when($selectedDay, fn ($query) => $query->where('event_zone_day_id', $selectedDay->id))
            ->when(! $selectedDay && $days->isNotEmpty(), fn ($query) => $query->whereNull('event_zone_day_id'))
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
                'requires_explicit_zones' => $event->requires_explicit_zones,
                'legacy_zone_ends_at' => $event->legacy_zone_ends_at?->format('Y-m-d\TH:i'),
            ],
            'client' => [
                'id' => $event->client->id,
                'name' => $event->client->name,
            ],
            'days' => $days->map(fn (EventZoneDay $day): array => [
                'id' => $day->id,
                'operational_date' => $day->operational_date->toDateString(),
                'starts_at' => $day->starts_at->format('Y-m-d\TH:i'),
                'ends_at' => $day->ends_at->format('Y-m-d\TH:i'),
                'confirmed_at' => $day->confirmed_at?->toISOString(),
            ])->values(),
            'selected_day_id' => $selectedDay?->id,
            'has_legacy_zones' => $event->zones()->whereNull('event_zone_day_id')->exists(),
            'default_effective_at' => ($selectedDay
                ? $referenceAt
                : ($event->activeReportImports()->exists()
                ? $this->defaultEffectiveAt($event)
                : CarbonImmutable::instance($event->report_starts_at ?? $event->event_date)))
                ->format('Y-m-d\TH:i'),
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
                ->when($selectedDay, fn ($query) => $query->whereHas('zone', fn ($zones) => $zones
                    ->where('event_zone_day_id', $selectedDay->id)))
                ->when(! $selectedDay && $days->isNotEmpty(), fn ($query) => $query
                    ->whereHas('zone', fn ($zones) => $zones->whereNull('event_zone_day_id')))
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

    public function store(Request $request, Event $event): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'day_id' => ['nullable', 'integer'],
        ]);
        $day = isset($validated['day_id'])
            ? $event->zoneDays()->findOrFail($validated['day_id'])
            : null;
        if (! $day && $event->zoneDays()->exists()) {
            throw ValidationException::withMessages(['day_id' => 'Selecione um dia operacional.']);
        }
        $name = trim($validated['name']);
        $this->ensureUniqueName($event, $name, null, $day);
        $nextOrder = ((int) EventZone::query()->where('event_id', $event->id)
            ->where('event_zone_day_id', $day?->id)->max('sort_order')) + 1;

        EventZone::create([
            'event_id' => $event->id,
            'event_zone_day_id' => $day?->id,
            'name' => $name,
            'sort_order' => $nextOrder,
        ]);
        $this->zoneAttribution->forget($event->id);

        return $this->redirectToDay($event, $day?->id)
            ->with('success', 'Zona criada com sucesso.');
    }

    public function update(Request $request, Event $event, EventZone $zone): RedirectResponse
    {
        $this->resolveZone($event, $zone);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);
        $name = trim($validated['name']);
        if ($zone->name !== $name && $this->zoneHasSales($zone)) {
            throw ValidationException::withMessages(['name' => 'Esta zona já tem vendas. O nome histórico não pode ser alterado.']);
        }
        $this->ensureUniqueName($event, $name, $zone, $zone->day);

        $zone->update(['name' => $name]);
        $this->zoneAttribution->forget($event->id);

        return $this->redirectToDay($event, $zone->event_zone_day_id)
            ->with('success', 'Zona atualizada com sucesso.');
    }

    public function destroy(Event $event, EventZone $zone): RedirectResponse
    {
        $this->resolveZone($event, $zone);

        if ($this->zoneHasSales($zone)) {
            throw ValidationException::withMessages(['zone' => 'Esta zona já tem vendas e deve permanecer no histórico.']);
        }

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

        return $this->redirectToDay($event, $zone->event_zone_day_id)
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
        $effectiveAt = $this->validatedEffectiveAt($event, $validated['effective_at']);
        $this->assertWithinZoneDay($zone, $effectiveAt);

        $this->zoneManagement->moveMachine(
            $event,
            $zone,
            $machine,
            $effectiveAt,
            $request->user(),
        );

        return $this->redirectToDay($event, $zone->event_zone_day_id)
            ->with('success', $zone->day && ! $zone->day->confirmed_at
                ? 'TPA atribuído ao plano em preparação. As vendas publicadas não foram alteradas.'
                : 'TPA movido e faturação recalculada pelo horário da mudança.');
    }

    public function assignMachines(Request $request, Event $event, EventZone $zone): RedirectResponse
    {
        $this->resolveZone($event, $zone);
        abort_if($zone->archived_at !== null, 404);
        $validated = $request->validate([
            'machine_ids' => ['required', 'array', 'min:1', 'max:500'],
            'machine_ids.*' => ['required', 'integer', 'distinct'],
            'effective_at' => ['required', 'date'],
        ]);
        $effectiveAt = $this->validatedEffectiveAt($event, $validated['effective_at']);
        $this->assertWithinZoneDay($zone, $effectiveAt);
        $machineIds = collect($validated['machine_ids'])->map(fn (int|string $id): int => (int) $id);

        DB::transaction(function () use ($event, $zone, $machineIds, $effectiveAt, $request): void {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $machines = $event->zonesoftMachines()->whereIn('client_zonesoft_machines.id', $machineIds)->get();

            if ($machines->count() !== $machineIds->count()) {
                throw ValidationException::withMessages([
                    'machine_ids' => 'Só pode atribuir TPAs associados a este evento.',
                ]);
            }

            $alreadyAssigned = EventZoneAssignment::query()
                ->where('event_id', $event->id)
                ->whereIn('machine_id', $machineIds)
                ->where('starts_at', '<=', $effectiveAt)
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $effectiveAt))
                ->when($zone->event_zone_day_id, fn ($query) => $query->whereHas('zone', fn ($zones) => $zones
                    ->where('event_zone_day_id', $zone->event_zone_day_id)))
                ->exists();

            if ($alreadyAssigned) {
                throw ValidationException::withMessages([
                    'machine_ids' => 'A seleção inclui TPAs já atribuídos. Use a mudança individual de zona.',
                ]);
            }

            foreach ($machines as $machine) {
                $this->zoneManagement->moveMachine($event, $zone, $machine, $effectiveAt, $request->user());
            }
        });

        return $this->redirectToDay($event, $zone->event_zone_day_id)
            ->with('success', 'TPAs atribuídos à zona.');
    }

    public function storeDay(Request $request, Event $event): RedirectResponse
    {
        $values = $this->validatedDay($request, $event);
        $day = DB::transaction(function () use ($event, $values): EventZoneDay {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $this->assertDayWindowAvailable($event, $values);

            return $event->zoneDays()->create($values);
        });

        return $this->redirectToDay($event, $day->id)
            ->with('success', 'Dia operacional criado. Configure as zonas e os TPAs antes de confirmar.');
    }

    public function updateDay(Request $request, Event $event, EventZoneDay $day): RedirectResponse
    {
        $this->resolveDay($event, $day);
        $values = $this->validatedDay($request, $event);

        DB::transaction(function () use ($event, $day, $values): void {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($day->confirmed_at || EventZoneAssignment::query()->whereIn(
                'event_zone_id', $day->zones()->select('id'),
            )->exists()) {
                throw ValidationException::withMessages([
                    'day' => 'O horário deste dia já tem atribuições ou foi confirmado e não pode ser alterado.',
                ]);
            }
            $this->assertDayWindowAvailable($event, $values, $day);
            $day->update($values);
        });

        return $this->redirectToDay($event, $day->id)->with('success', 'Dia atualizado.');
    }

    public function destroyDay(Event $event, EventZoneDay $day): RedirectResponse
    {
        $this->resolveDay($event, $day);
        if ($day->confirmed_at || $day->zones()->exists()) {
            throw ValidationException::withMessages([
                'day' => 'Só é possível eliminar um dia ainda não confirmado e sem zonas.',
            ]);
        }

        $day->delete();

        return to_route('admin.events.zones.manage', $event)
            ->with('success', 'Dia vazio eliminado.');
    }

    public function confirmDay(Event $event, EventZoneDay $day): RedirectResponse
    {
        $this->resolveDay($event, $day);

        DB::transaction(function () use ($event, $day): void {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $day->refresh();
            if ($day->confirmed_at) {
                return;
            }
            $validZoneIds = $day->zones()->whereNull('archived_at')->pluck('id')->mapWithKeys(
                fn ($id): array => [(int) $id => true],
            );
            if ($validZoneIds->isEmpty()) {
                throw ValidationException::withMessages(['day' => 'Crie as zonas deste dia antes de confirmar.']);
            }

            if ($day->ends_at->greaterThan($this->defaultEffectiveAt($event))) {
                $machineIds = $event->zonesoftMachines()->where('is_active', true)
                    ->pluck('client_zonesoft_machines.id')->map(fn ($id): int => (int) $id);
                $assignedIds = EventZoneAssignment::query()
                    ->where('event_id', $event->id)
                    ->whereIn('machine_id', $machineIds)
                    ->where('starts_at', '<=', $day->starts_at)
                    ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $day->starts_at))
                    ->whereHas('zone', fn ($zones) => $zones
                        ->where('event_zone_day_id', $day->id)->whereNull('archived_at'))
                    ->pluck('machine_id')->map(fn ($id): int => (int) $id);

                if ($machineIds->diff($assignedIds)->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'day' => sprintf('Faltam %d TPAs ativos para atribuir neste dia.', $machineIds->diff($assignedIds)->count()),
                    ]);
                }
            }

            $touchedMachineIds = [];
            $this->zoneAttribution->forget($event->id);
            foreach ([EventReportRow::class, EventReportPaymentDocument::class] as $model) {
                if ($model::query()->where('event_id', $event->id)
                    ->whereNull('sale_datetime')
                    ->where(fn ($query) => $query
                        ->whereNull('sale_date')
                        ->orWhereDate('sale_date', '>=', $day->starts_at->toDateString())
                        ->whereDate('sale_date', '<=', $day->ends_at->toDateString()))
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'day' => 'Há vendas sem hora neste período. Não é seguro atribuí-las a um dia operacional.',
                    ]);
                }

                $model::query()->where('event_id', $event->id)
                    ->where('sale_datetime', '>=', $day->starts_at)
                    ->where('sale_datetime', '<', $day->ends_at)
                    ->select(['id', 'machine_id', 'sale_datetime'])
                    ->chunkById(500, function ($sales) use ($event, $validZoneIds, &$touchedMachineIds): void {
                        foreach ($sales as $sale) {
                            $zoneId = $this->zoneAttribution->zoneIdFor(
                                $event->id, $sale->machine_id, $sale->sale_datetime, null, true,
                            );
                            if (! $zoneId || ! $validZoneIds->has($zoneId)) {
                                throw ValidationException::withMessages([
                                    'day' => 'Existem vendas sem zona diária neste período. Atribua o TPA pela hora da venda antes de confirmar.',
                                ]);
                            }
                            $touchedMachineIds[(int) $sale->machine_id] = (int) $sale->machine_id;
                        }
                    });
            }

            $day->update(['confirmed_at' => now()]);
            $this->zoneAttribution->forget($event->id);
            foreach ($touchedMachineIds as $machineId) {
                $this->zoneAttribution->reattributeMachine($event->id, $machineId, $day->starts_at, $day->ends_at);
            }
            if ($touchedMachineIds !== []) {
                $this->reportSync->refreshRowAggregates($event->id, array_values($touchedMachineIds));
            }
        });

        $this->zoneAttribution->forget($event->id);

        return $this->redirectToDay($event, $day->id)->with('success', 'Dia confirmado.');
    }

    public function closeLegacy(Request $request, Event $event): RedirectResponse
    {
        $validated = $request->validate(['ends_at' => ['required', 'date']]);
        $endsAt = $this->validatedEffectiveAt($event, $validated['ends_at']);
        $this->zoneManagement->closeLegacyAssignments($event, $endsAt);
        $this->zoneAttribution->forget($event->id);

        return $this->redirectToDay($event, null)
            ->with('success', 'Atribuições antigas encerradas e zonas antigas arquivadas. O histórico das vendas foi preservado.');
    }

    /** @return array{operational_date:string, starts_at:CarbonImmutable, ends_at:CarbonImmutable} */
    private function validatedDay(Request $request, Event $event): array
    {
        $validated = $request->validate([
            'operational_date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);
        $startsAt = CarbonImmutable::parse($validated['starts_at']);
        $endsAt = CarbonImmutable::parse($validated['ends_at']);
        $eventStart = CarbonImmutable::instance($event->report_starts_at ?? $event->event_date);
        $eventEnd = $event->report_ends_at ? CarbonImmutable::instance($event->report_ends_at) : null;

        if ($startsAt->lessThan($eventStart) || ($eventEnd && $endsAt->greaterThan($eventEnd))) {
            throw ValidationException::withMessages([
                'starts_at' => 'O dia deve ficar dentro do período de relatório do evento.',
            ]);
        }
        if ($startsAt->toDateString() !== $validated['operational_date']) {
            throw ValidationException::withMessages([
                'operational_date' => 'A data operacional deve corresponder à data de início.',
            ]);
        }

        return [
            'operational_date' => $validated['operational_date'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }

    /** @param array{operational_date:string, starts_at:CarbonImmutable, ends_at:CarbonImmutable} $values */
    private function assertDayWindowAvailable(Event $event, array $values, ?EventZoneDay $except = null): void
    {
        if ($event->zoneDays()->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->where(function ($query) use ($values): void {
                $query->where('operational_date', $values['operational_date'])
                    ->orWhere(fn ($overlap) => $overlap
                        ->where('starts_at', '<', $values['ends_at'])
                        ->where('ends_at', '>', $values['starts_at']));
            })->exists()) {
            throw ValidationException::withMessages([
                'starts_at' => 'Este dia sobrepõe-se a outro ou já existe para esta data.',
            ]);
        }
    }

    private function resolveDay(Event $event, EventZoneDay $day): void
    {
        abort_unless($day->event_id === $event->id, 404);
    }

    private function assertWithinZoneDay(EventZone $zone, CarbonImmutable $effectiveAt): void
    {
        if (! $zone->event_zone_day_id) {
            $firstDay = EventZoneDay::query()->where('event_id', $zone->event_id)->orderBy('starts_at')->first();
            if ($firstDay && $effectiveAt->greaterThanOrEqualTo($firstDay->starts_at)) {
                throw ValidationException::withMessages(['effective_at' => 'As zonas antigas só podem ser usadas antes do primeiro dia operacional.']);
            }

            return;
        }

        $day = $zone->day;
        if (! $day || $effectiveAt->lessThan($day->starts_at) || $effectiveAt->greaterThanOrEqualTo($day->ends_at)) {
            throw ValidationException::withMessages(['effective_at' => 'A atribuição deve começar dentro do dia desta zona.']);
        }
    }

    private function referenceAtForDay(EventZoneDay $day, Event $event): CarbonImmutable
    {
        $now = $this->defaultEffectiveAt($event);

        if ($now->lessThan($day->starts_at)) {
            return CarbonImmutable::instance($day->starts_at);
        }

        return $now->greaterThanOrEqualTo($day->ends_at)
            ? CarbonImmutable::instance($day->ends_at)->subSecond()
            : $now;
    }

    private function zoneHasSales(EventZone $zone): bool
    {
        return EventReportRow::query()->where('event_zone_id', $zone->id)->exists()
            || EventReportPaymentDocument::query()->where('event_zone_id', $zone->id)->exists();
    }

    private function redirectToDay(Event $event, ?int $dayId): RedirectResponse
    {
        return to_route('admin.events.zones.manage', $dayId
            ? ['event' => $event, 'day' => $dayId]
            : ($event->zoneDays()->exists() ? ['event' => $event, 'day' => 'legacy'] : $event));
    }

    private function validatedEffectiveAt(Event $event, string $value): CarbonImmutable
    {
        $effectiveAt = CarbonImmutable::parse($value);
        $startsAt = CarbonImmutable::instance($event->report_starts_at ?? $event->event_date);
        $endsAt = $event->report_ends_at ? CarbonImmutable::instance($event->report_ends_at) : null;

        if ($effectiveAt->lessThan($startsAt) || ($endsAt && $effectiveAt->greaterThan($endsAt))) {
            throw ValidationException::withMessages([
                'effective_at' => 'A atribuição deve ocorrer dentro do período configurado para o evento.',
            ]);
        }

        return $effectiveAt;
    }

    private function resolveZone(Event $event, EventZone $zone): EventZone
    {
        abort_unless($zone->event_id === $event->id, 404);

        return $zone;
    }

    private function ensureUniqueName(Event $event, string $name, ?EventZone $except = null, ?EventZoneDay $day = null): void
    {
        $exists = EventZone::query()
            ->where('event_id', $event->id)
            ->where('event_zone_day_id', $day?->id)
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Já existe uma zona com este nome neste dia.',
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
