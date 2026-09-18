<?php

namespace App\Services;

use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventZone;
use App\Models\EventZoneAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class EventZoneManagementService
{
    private const BUSINESS_TIMEZONE = 'Europe/Lisbon';

    public function __construct(
        private readonly EventZoneAttributionService $attribution,
        private readonly EventReportSyncService $reportSync,
    ) {}

    /** @return array<int, int> */
    public function initializeMissingMachines(Event $event, ?User $actor = null): array
    {
        $machines = $event->zonesoftMachines()->orderBy('id')->get();
        $startsAt = CarbonImmutable::instance($event->report_starts_at ?? $event->event_date);
        $changeAt = $this->effectiveNow($event);
        $touchedMachineIds = DB::transaction(function () use ($event, $actor, $machines, $startsAt, $changeAt): array {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $linkedMachineIds = $machines->pluck('id')->map(fn (int|string $id): int => (int) $id)->all();
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

            $existingMachineIds = EventZoneAssignment::query()
                ->where('event_id', $event->id)
                ->whereIn('machine_id', $machines->pluck('id'))
                ->whereNull('ends_at')
                ->pluck('machine_id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all();
            $known = array_fill_keys($existingMachineIds, true);
            $historicalMachineIds = EventZoneAssignment::query()
                ->where('event_id', $event->id)
                ->whereIn('machine_id', $machines->pluck('id'))
                ->distinct()
                ->pluck('machine_id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all();
            $hasHistory = array_fill_keys($historicalMachineIds, true);
            $zones = EventZone::query()
                ->where('event_id', $event->id)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (EventZone $zone): string => mb_strtolower(trim($zone->name)));
            $nextOrder = ((int) $zones->max('sort_order')) + 1;

            foreach ($machines as $machine) {
                if (isset($known[$machine->id])) {
                    continue;
                }

                $fallbackName = $machine->store_label ?: 'Loja '.$machine->store_id;
                $name = $this->attribution->fallbackLabel($fallbackName);
                $key = mb_strtolower($name);
                $zone = $zones->get($key);

                if (! $zone) {
                    $zone = EventZone::create([
                        'event_id' => $event->id,
                        'name' => $name,
                        'sort_order' => $nextOrder++,
                    ]);
                    $zones->put($key, $zone);
                } elseif ($zone->archived_at !== null) {
                    $zone->update(['archived_at' => null]);
                }

                $assignmentStartsAt = isset($hasHistory[$machine->id]) ? $changeAt : $startsAt;
                $boundaryAssignment = EventZoneAssignment::query()
                    ->where('event_id', $event->id)
                    ->where('machine_id', $machine->id)
                    ->where('starts_at', $assignmentStartsAt)
                    ->first();

                if ($boundaryAssignment) {
                    $boundaryAssignment->update([
                        'event_zone_id' => $zone->id,
                        'ends_at' => null,
                        'assigned_by_user_id' => $actor?->id,
                        'source' => 'relinked',
                    ]);
                    $touched[] = $machine->id;

                    continue;
                }

                EventZoneAssignment::create([
                    'event_id' => $event->id,
                    'event_zone_id' => $zone->id,
                    'machine_id' => $machine->id,
                    'starts_at' => $assignmentStartsAt,
                    'ends_at' => null,
                    'assigned_by_user_id' => $actor?->id,
                    'source' => isset($hasHistory[$machine->id]) ? 'relinked' : 'initial',
                ]);
                $touched[] = $machine->id;
            }

            $this->refreshAttribution($event->id, $touched);

            return $touched;
        });

        return $touchedMachineIds;
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

        DB::transaction(function () use ($event, $zone, $machine, $effectiveAt, $actor): void {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $assignments = EventZoneAssignment::query()
                ->where('event_id', $event->id)
                ->where('machine_id', $machine->id)
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
                        'ends_at' => $next?->starts_at,
                        'assigned_by_user_id' => $actor?->id,
                        'source' => 'manual',
                    ]);
                    $changed = true;
                }
            }

            if ($changed) {
                $this->refreshAttribution($event->id, [$machine->id]);
            }
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
