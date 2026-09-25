<?php

namespace App\Services;

use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventZoneAssignment;
use App\Models\EventZoneDay;
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

        $referenceAt = $this->referenceAt($event);
        $day = $this->dayAt($event, $referenceAt);
        if ($this->planRequiredAt($event, $referenceAt, $day) && (! $day || ! $day->confirmed_at)) {
            return $machines->values();
        }
        $effectiveDay = $day?->confirmed_at ? $day : null;

        $assignedIds = EventZoneAssignment::query()
            ->where('event_id', $event->id)
            ->whereIn('machine_id', $machines->modelKeys())
            ->where('starts_at', '<=', $referenceAt)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $referenceAt))
            ->whereHas('zone', fn ($query) => $query->whereNull('archived_at')
                ->where('event_zone_day_id', $effectiveDay?->id))
            ->pluck('machine_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->flip();

        return $machines->filter(fn (ClientZoneSoftMachine $machine): bool => ! $assignedIds->has($machine->id))
            ->values();
    }

    public function isReady(Event $event): bool
    {
        if (! $event->requires_explicit_zones) {
            return true;
        }
        $referenceAt = $this->referenceAt($event);
        $day = $this->dayAt($event, $referenceAt);

        return (! $this->planRequiredAt($event, $referenceAt, $day) || ($day && $day->confirmed_at))
            && $this->unassignedMachines($event)->isEmpty();
    }

    public function assertReady(Event $event): void
    {
        if (! $event->requires_explicit_zones) {
            return;
        }

        $referenceAt = $this->referenceAt($event);
        $day = $this->dayAt($event, $referenceAt);
        if ($this->planRequiredAt($event, $referenceAt, $day) && (! $day || ! $day->confirmed_at)) {
            throw ValidationException::withMessages([
                'integration' => 'Crie e confirme o plano de zonas deste dia antes de sincronizar.',
            ]);
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

    private function referenceAt(Event $event): CarbonImmutable
    {
        $startsAt = $event->report_starts_at
            ? CarbonImmutable::instance($event->report_starts_at)
            : CarbonImmutable::instance($event->event_date)->startOfDay();
        $endsAt = $event->report_ends_at ? CarbonImmutable::instance($event->report_ends_at) : null;
        $localNow = CarbonImmutable::now(self::BUSINESS_TIMEZONE);
        $referenceAt = CarbonImmutable::parse($localNow->format('Y-m-d H:i:s'));

        if ($referenceAt->lessThan($startsAt)) {
            return $startsAt;
        }

        return $endsAt && $referenceAt->greaterThanOrEqualTo($endsAt)
            ? $endsAt->subSecond()
            : $referenceAt;
    }

    private function dayAt(Event $event, CarbonImmutable $referenceAt): ?EventZoneDay
    {
        return $event->zoneDays()->where('starts_at', '<=', $referenceAt)
            ->where('ends_at', '>', $referenceAt)->first();
    }

    private function planRequiredAt(Event $event, CarbonImmutable $referenceAt, ?EventZoneDay $day): bool
    {
        if ($event->legacy_zone_ends_at && $referenceAt->greaterThanOrEqualTo($event->legacy_zone_ends_at)) {
            return true;
        }

        if (! $day) {
            return false;
        }

        return ! $event->zones()->whereNull('event_zone_day_id')->exists();
    }
}
