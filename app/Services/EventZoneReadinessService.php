<?php

namespace App\Services;

use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventZoneAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EventZoneReadinessService
{
    private const BUSINESS_TIMEZONE = 'Europe/Lisbon';

    /** @return Collection<int, ClientZoneSoftMachine> */
    public function unassignedMachines(Event $event): Collection
    {
        $machines = $event->zonesoftMachines()
            ->where('is_active', true)
            ->orderBy('store_id')
            ->get();

        if ($machines->isEmpty()) {
            return $machines;
        }

        $startsAt = CarbonImmutable::instance($event->report_starts_at ?? $event->event_date);
        $endsAt = $event->report_ends_at ? CarbonImmutable::instance($event->report_ends_at) : null;
        $localNow = CarbonImmutable::now(self::BUSINESS_TIMEZONE);
        $referenceAt = CarbonImmutable::parse($localNow->format('Y-m-d H:i:s'));

        if ($referenceAt->lessThan($startsAt)) {
            $referenceAt = $startsAt;
        } elseif ($endsAt && $referenceAt->greaterThan($endsAt)) {
            $referenceAt = $endsAt;
        }

        $assignedIds = EventZoneAssignment::query()
            ->where('event_id', $event->id)
            ->whereIn('machine_id', $machines->modelKeys())
            ->where('starts_at', '<=', $referenceAt)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $referenceAt))
            ->whereHas('zone', fn ($query) => $query->whereNull('archived_at'))
            ->pluck('machine_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->flip();

        return $machines->filter(fn (ClientZoneSoftMachine $machine): bool => ! $assignedIds->has($machine->id))
            ->values();
    }

    public function isReady(Event $event): bool
    {
        return ! $event->requires_explicit_zones || $this->unassignedMachines($event)->isEmpty();
    }

    public function assertReady(Event $event): void
    {
        if (! $event->requires_explicit_zones) {
            return;
        }

        $unassigned = $this->unassignedMachines($event);

        if ($unassigned->isEmpty()) {
            return;
        }

        $labels = $unassigned->take(5)
            ->map(fn (ClientZoneSoftMachine $machine): string => (string) $machine->store_id)
            ->implode(', ');
        $extra = $unassigned->count() > 5 ? '…' : '';

        throw ValidationException::withMessages([
            'integration' => sprintf(
                'Atribua os %d TPAs sem zona antes de sincronizar (Store IDs: %s%s).',
                $unassigned->count(),
                $labels,
                $extra,
            ),
        ]);
    }
}
