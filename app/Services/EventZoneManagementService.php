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
                $this->renameMachineStoreData($event->id, $machine, $previousLabel, $currentLabel);
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

    private function renameMachineStoreData(
        int $eventId,
        ClientZoneSoftMachine $machine,
        string $previousLabel,
        string $currentLabel,
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
