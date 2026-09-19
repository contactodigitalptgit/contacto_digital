<?php

namespace App\Services;

use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventReportPaymentDocument;
use App\Models\EventReportRow;
use App\Models\EventZone;
use App\Models\EventZoneAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventZoneManagementService
{
    private const BUSINESS_TIMEZONE = 'Europe/Lisbon';

    public function __construct(
        private readonly EventZoneAttributionService $attribution,
        private readonly EventReportSyncService $reportSync,
    ) {}

    /** Close assignments for TPAs removed from the event without guessing zones for new TPAs. */
    public function closeDetachedAssignments(Event $event): void
    {
        $linkedMachineIds = $event->zonesoftMachines()->pluck('client_zonesoft_machines.id')->all();
        $changeAt = $this->effectiveNow($event);
        DB::transaction(function () use ($event, $linkedMachineIds, $changeAt): void {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $detachedAssignments = EventZoneAssignment::query()
                ->where('event_id', $event->id)
                ->whereNull('ends_at')
                ->when(
                    $linkedMachineIds !== [],
                    fn ($query) => $query->whereNotIn('machine_id', $linkedMachineIds),
                )
                ->lockForUpdate()
                ->get();
            $touched = [];

            foreach ($detachedAssignments as $assignment) {
                $assignment->update([
                    'ends_at' => $changeAt->greaterThan($assignment->starts_at)
                        ? $changeAt
                        : $assignment->starts_at,
                ]);
                $touched[] = $assignment->machine_id;
            }
            $this->refreshAttribution($event->id, $touched);
        });
    }

    public function synchronizeMachineLabel(
        ClientZoneSoftMachine $machine,
        ?string $previousLabel,
    ): void {
        $currentLabel = trim((string) $machine->store_label);
        $previousLabel = trim((string) $previousLabel);

        if ($currentLabel === '' || $currentLabel === $previousLabel) {
            return;
        }

        $machine->events()->get()->each(function (Event $event) use ($machine, $previousLabel, $currentLabel): void {
            DB::transaction(function () use ($event, $machine, $previousLabel, $currentLabel): void {
                $lastConfirmedDayEnd = $event->zoneDays()->whereNotNull('confirmed_at')->max('ends_at');
                $this->renameMachineStoreData(
                    $event->id,
                    $machine,
                    $previousLabel,
                    $currentLabel,
                    $lastConfirmedDayEnd ? CarbonImmutable::parse($lastConfirmedDayEnd) : null,
                );
                $this->refreshAttribution($event->id, [$machine->id]);
            });
        });
    }

    public function moveMachine(
        Event $event,
        EventZone $zone,
        ClientZoneSoftMachine $machine,
        CarbonInterface $effectiveAt,
        ?User $actor = null,
    ): void {
        abort_unless($zone->event_id === $event->id, 404);
        abort_unless(
            $machine->client_id === $event->client_id
            && $event->zonesoftMachines()->whereKey($machine->id)->exists(),
            404,
        );

        $effectiveAt = CarbonImmutable::instance($effectiveAt);
        if ($zone->event_zone_day_id) {
            $day = $zone->day;
            if (! $day || $effectiveAt->lessThan($day->starts_at) || $effectiveAt->greaterThanOrEqualTo($day->ends_at)) {
                throw ValidationException::withMessages(['effective_at' => 'A atribuição deve estar dentro do dia operacional da zona.']);
            }
        }

        DB::transaction(function () use ($event, $zone, $machine, $effectiveAt, $actor): void {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $assignments = EventZoneAssignment::query()
                ->where('event_id', $event->id)
                ->where('machine_id', $machine->id)
                ->whereHas('zone', fn ($query) => $query->where('event_zone_day_id', $zone->event_zone_day_id))
                ->lockForUpdate()
                ->orderBy('starts_at')
                ->get();
            $atBoundary = $assignments->first(
                fn (EventZoneAssignment $assignment): bool => $assignment->starts_at->equalTo($effectiveAt),
            );

            $changed = false;

            if ($atBoundary && $atBoundary->event_zone_id !== $zone->id) {
                $atBoundary->update([
                    'event_zone_id' => $zone->id,
                    'ends_at' => $zone->day && (! $atBoundary->ends_at || $atBoundary->ends_at->greaterThan($zone->day->ends_at))
                        ? $zone->day->ends_at
                        : $atBoundary->ends_at,
                    'assigned_by_user_id' => $actor?->id,
                    'source' => 'manual',
                ]);
                $changed = true;
            }

            if (! $atBoundary) {
                $current = $assignments->first(function (EventZoneAssignment $assignment) use ($effectiveAt): bool {
                    return $assignment->starts_at->lessThan($effectiveAt)
                        && ($assignment->ends_at === null || $assignment->ends_at->greaterThan($effectiveAt));
                });

                if ($current?->event_zone_id !== $zone->id) {
                    $next = $assignments->first(
                        fn (EventZoneAssignment $assignment): bool => $assignment->starts_at->greaterThan($effectiveAt),
                    );

                    if ($current) {
                        $current->update(['ends_at' => $effectiveAt]);
                    }

                    EventZoneAssignment::create([
                        'event_id' => $event->id,
                        'event_zone_id' => $zone->id,
                        'machine_id' => $machine->id,
                        'starts_at' => $effectiveAt,
                        'ends_at' => $zone->day
                            ? ($next && $next->starts_at->lessThan($zone->day->ends_at)
                                ? $next->starts_at : $zone->day->ends_at)
                            : $next?->starts_at,
                        'assigned_by_user_id' => $actor?->id,
                        'source' => 'manual',
                    ]);
                    $changed = true;
                }
            }

            // A draft day must not alter already-published sales. Its assignments
            // become effective for attribution only after the plan is confirmed.
            if ($changed && (! $zone->day || $zone->day->confirmed_at)) {
                $this->refreshAttribution($event->id, [$machine->id]);
            }
        });
    }

    public function closeLegacyAssignments(Event $event, CarbonInterface $endsAt): void
    {
        $endsAt = CarbonImmutable::instance($endsAt);

        DB::transaction(function () use ($event, $endsAt): void {
            $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($lockedEvent->legacy_zone_ends_at && ! $lockedEvent->legacy_zone_ends_at->equalTo($endsAt)) {
                throw ValidationException::withMessages([
                    'ends_at' => 'As atribuições antigas já foram fechadas. Uma correção requer reconciliação histórica.',
                ]);
            }
            $legacyZoneIds = EventZone::query()->where('event_id', $event->id)
                ->whereNull('event_zone_day_id')->pluck('id');
            if ($legacyZoneIds->isEmpty()) {
                throw ValidationException::withMessages(['ends_at' => 'Este evento não tem zonas antigas para fechar.']);
            }
            $firstDay = $event->zoneDays()->orderBy('starts_at')->first();
            if ($firstDay && $event->zoneDays()
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $endsAt)
                ->exists()) {
                throw ValidationException::withMessages([
                    'ends_at' => 'O fecho das zonas antigas não pode ocorrer dentro de um dia operacional.',
                ]);
            }
            foreach ([EventReportRow::class, EventReportPaymentDocument::class] as $model) {
                if ($model::query()->where('event_id', $event->id)
                    ->whereIn('event_zone_id', $legacyZoneIds)
                    ->where(function ($query) use ($endsAt): void {
                        $query->where('sale_datetime', '>=', $endsAt)
                            ->orWhere(fn ($missingTime) => $missingTime
                                ->whereNull('sale_datetime')
                                ->whereDate('sale_date', '>=', $endsAt->toDateString()));
                    })->exists()) {
                    throw ValidationException::withMessages([
                        'ends_at' => 'Há vendas posteriores à hora de fecho atribuídas às zonas antigas. Reconcilie esses dados primeiro.',
                    ]);
                }
            }
            $assignments = EventZoneAssignment::query()->where('event_id', $event->id)
                ->whereIn('event_zone_id', $legacyZoneIds)
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $endsAt))
                ->lockForUpdate()->get();

            if ($assignments->contains(fn (EventZoneAssignment $assignment): bool => $assignment->starts_at->greaterThan($endsAt))) {
                throw ValidationException::withMessages(['ends_at' => 'Há atribuições antigas que começam depois da hora de fecho.']);
            }

            foreach ($assignments as $assignment) {
                $assignment->update(['ends_at' => $endsAt]);
            }
            EventZone::query()->whereIn('id', $legacyZoneIds)
                ->whereNull('archived_at')
                ->update(['archived_at' => now()]);
            $lockedEvent->update(['legacy_zone_ends_at' => $endsAt]);
            $this->attribution->forget($event->id);
        });
    }

    /** @param array<int, int> $machineIds */
    private function refreshAttribution(int $eventId, array $machineIds): void
    {
        $machineIds = array_values(array_unique($machineIds));

        foreach ($machineIds as $machineId) {
            $this->attribution->reattributeMachine($eventId, $machineId);
        }

        if ($machineIds !== []) {
            $this->reportSync->refreshRowAggregates($eventId, $machineIds);
        }
    }

    private function renameMachineStoreData(
        int $eventId,
        ClientZoneSoftMachine $machine,
        string $previousLabel,
        string $currentLabel,
        ?CarbonImmutable $onlyFrom = null,
    ): void {
        $previousCandidates = array_values(array_unique(array_filter([
            $previousLabel,
            'Loja '.$machine->store_id,
            'Store '.$machine->store_id,
        ])));

        foreach (['event_report_rows', 'event_report_payment_documents'] as $table) {
            $storedNames = DB::table($table)
                ->where('event_id', $eventId)
                ->where('machine_id', $machine->id)
                ->when($onlyFrom, fn ($query) => $query->where('sale_datetime', '>=', $onlyFrom))
                ->whereNotNull('store_name')
                ->distinct()
                ->pluck('store_name');

            foreach ($storedNames as $storedName) {
                $storedName = (string) $storedName;
                $replacement = $this->replaceStoreNamePrefix(
                    $storedName,
                    $previousCandidates,
                    $currentLabel,
                );

                if ($replacement === null || $replacement === $storedName) {
                    continue;
                }

                DB::table($table)
                    ->where('event_id', $eventId)
                    ->where('machine_id', $machine->id)
                    ->when($onlyFrom, fn ($query) => $query->where('sale_datetime', '>=', $onlyFrom))
                    ->where('store_name', $storedName)
                    ->update(['store_name' => $replacement]);
            }
        }
    }

    /** @param list<string> $previousCandidates */
    private function replaceStoreNamePrefix(
        string $storedName,
        array $previousCandidates,
        string $currentLabel,
    ): ?string {
        foreach ($previousCandidates as $candidate) {
            if ($storedName === $candidate) {
                return $currentLabel;
            }

            $posPrefix = $candidate.' - POS ';

            if (str_starts_with($storedName, $posPrefix)) {
                return $currentLabel.substr($storedName, strlen($candidate));
            }
        }

        return null;
    }

    private function effectiveNow(Event $event): CarbonImmutable
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
}
