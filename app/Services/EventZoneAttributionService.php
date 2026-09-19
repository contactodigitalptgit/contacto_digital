<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventReportPaymentDocument;
use App\Models\EventReportRow;
use App\Models\EventZone;
use App\Models\EventZoneAssignment;
use App\Models\EventZoneDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;

class EventZoneAttributionService
{
    /** @var array<string, list<array{zone_id:int,day_id:?int,starts_at:int,ends_at:?int}>> */
    private array $assignmentCache = [];

    /** @var array<int, list<array{id:int,starts_at:int,ends_at:int,confirmed:bool}>> */
    private array $dayCache = [];

    /** @var array<int, ?int> */
    private array $legacyEndCache = [];

    /** @var array<int, array<int, string>> */
    private array $labelCache = [];

    /** @var array<int, bool> */
    private array $configuredCache = [];

    public function fallbackLabel(?string $storeName): string
    {
        if ($storeName === null || trim($storeName) === '') {
            return 'Sem zona';
        }

        if (preg_match('/\b(top\s*up|bc\s*top)\b/i', $storeName) === 1) {
            return 'Top Up';
        }

        if (preg_match('/\bbar\s*vip\b/i', $storeName) === 1 || preg_match('/^(vip)\b/i', $storeName) === 1) {
            return 'Bar Vip';
        }

        if (preg_match('/\bbar\s*(\d+)\b/i', $storeName, $matches) === 1) {
            return 'Bar '.(int) $matches[1];
        }

        if (preg_match('/^(bengaleiro)\b/i', $storeName) === 1) {
            return 'Bengaleiro';
        }

        if (preg_match('/^(bilheteira)\b/i', $storeName) === 1) {
            return 'Bilheteira';
        }

        if (preg_match('/^(.+?)\s*-\s*TPA\b/i', $storeName, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($storeName);
    }

    public function hasConfiguredZones(int $eventId): bool
    {
        return $this->configuredCache[$eventId] ??= EventZone::query()
            ->where('event_id', $eventId)
            ->exists();
    }

    /** @return array<int, string> */
    public function labelsById(int $eventId): array
    {
        if (isset($this->labelCache[$eventId])) {
            return $this->labelCache[$eventId];
        }

        $zones = EventZone::query()
            ->where('event_id', $eventId)
            ->with('day:id,operational_date')
            ->get(['id', 'name', 'event_zone_day_id']);
        $nameCounts = $zones->countBy(fn (EventZone $zone): string => Str::lower(trim($zone->name)));

        return $this->labelCache[$eventId] = $zones
            ->mapWithKeys(function (EventZone $zone) use ($nameCounts): array {
                $duplicated = ($nameCounts[Str::lower(trim($zone->name))] ?? 0) > 1;
                $period = $zone->day?->operational_date?->format('d/m/Y') ?? 'anterior';

                return [$zone->id => $duplicated ? $zone->name.' · '.$period : $zone->name];
            })->all();
    }

    /** @param array<int, string> $labels @return array<int, int> */
    public function zoneIdsForLabels(int $eventId, array $labels): array
    {
        $wanted = collect($labels)
            ->map(fn (string $label): string => Str::lower(trim($label)))
            ->filter()
            ->unique();

        $displayIds = collect($this->labelsById($eventId))
            ->filter(fn (string $name): bool => $wanted->contains(Str::lower(trim($name))))
            ->keys()
            ->map(fn (int|string $id): int => (int) $id);
        $rawIds = EventZone::query()->where('event_id', $eventId)->get(['id', 'name'])
            ->filter(fn (EventZone $zone): bool => $wanted->contains(Str::lower(trim($zone->name))))
            ->pluck('id');

        return $displayIds->merge($rawIds)
            ->unique()
            ->values()
            ->all();
    }

    public function labelFor(int $eventId, int|string|null $zoneId, ?string $storeName): string
    {
        if ($zoneId !== null) {
            $label = $this->labelsById($eventId)[(int) $zoneId] ?? null;

            if ($label !== null) {
                return $label;
            }
        }

        return $this->hasConfiguredZones($eventId) ? 'Sem zona' : $this->fallbackLabel($storeName);
    }

    public function zoneIdFor(int $eventId, int|string|null $machineId, mixed $saleDateTime, mixed $saleDate = null, bool $includeDraft = false): ?int
    {
        if ($machineId === null || ! $this->hasConfiguredZones($eventId)) {
            return null;
        }

        $assignments = $this->assignmentsFor($eventId, (int) $machineId);

        if ($assignments === []) {
            return null;
        }

        $timestamp = $this->timestamp($saleDateTime)
            ?? $this->timestamp($saleDate, true)
            ?? $assignments[0]['starts_at'];
        $days = $this->daysFor($eventId);
        $day = collect($days)->first(fn (array $candidate): bool => $timestamp >= $candidate['starts_at']
            && $timestamp < $candidate['ends_at']);
        if ($day && ! $day['confirmed'] && ! $includeDraft) {
            $day = null;
        }
        $legacyEnd = $this->legacyEndFor($eventId);
        if (! $day && $legacyEnd !== null && $timestamp >= $legacyEnd) {
            return null;
        }

        foreach ($assignments as $assignment) {
            if ($day && $assignment['day_id'] !== $day['id']) {
                continue;
            }
            if (! $day && $assignment['day_id'] !== null) {
                continue;
            }
            if ($timestamp < $assignment['starts_at']) {
                continue;
            }

            if ($assignment['ends_at'] === null || $timestamp < $assignment['ends_at']) {
                return $assignment['zone_id'];
            }
        }

        return null;
    }

    public function reattributeMachine(int $eventId, int $machineId, ?CarbonInterface $startsAt = null, ?CarbonInterface $endsAt = null): void
    {
        $this->forget($eventId, $machineId);
        $this->reattributeQuery(EventReportRow::query(), EventReportRow::class, $eventId, $machineId, $startsAt, $endsAt);
        $this->reattributeQuery(
            EventReportPaymentDocument::query(),
            EventReportPaymentDocument::class,
            $eventId,
            $machineId,
            $startsAt,
            $endsAt,
        );
    }

    public function forget(int $eventId, ?int $machineId = null): void
    {
        unset($this->labelCache[$eventId], $this->configuredCache[$eventId], $this->dayCache[$eventId], $this->legacyEndCache[$eventId]);

        if ($machineId !== null) {
            unset($this->assignmentCache[$eventId.'|'.$machineId]);

            return;
        }

        foreach (array_keys($this->assignmentCache) as $key) {
            if (str_starts_with($key, $eventId.'|')) {
                unset($this->assignmentCache[$key]);
            }
        }
    }

    /** @return list<array{zone_id:int,day_id:?int,starts_at:int,ends_at:?int}> */
    private function assignmentsFor(int $eventId, int $machineId): array
    {
        $key = $eventId.'|'.$machineId;

        return $this->assignmentCache[$key] ??= EventZoneAssignment::query()
            ->where('event_id', $eventId)
            ->where('machine_id', $machineId)
            ->orderBy('starts_at')
            ->with('zone:id,event_zone_day_id')
            ->get(['event_zone_id', 'starts_at', 'ends_at'])
            ->map(fn (EventZoneAssignment $assignment): array => [
                'zone_id' => $assignment->event_zone_id,
                'day_id' => $assignment->zone?->event_zone_day_id,
                'starts_at' => $assignment->starts_at->getTimestamp(),
                'ends_at' => $assignment->ends_at?->getTimestamp(),
            ])
            ->all();
    }

    /** @return list<array{id:int,starts_at:int,ends_at:int,confirmed:bool}> */
    private function daysFor(int $eventId): array
    {
        return $this->dayCache[$eventId] ??= EventZoneDay::query()->where('event_id', $eventId)
            ->orderBy('starts_at')->get()
            ->map(fn (EventZoneDay $day): array => [
                'id' => $day->id,
                'starts_at' => $day->starts_at->getTimestamp(),
                'ends_at' => $day->ends_at->getTimestamp(),
                'confirmed' => $day->confirmed_at !== null,
            ])->all();
    }

    private function legacyEndFor(int $eventId): ?int
    {
        if (! array_key_exists($eventId, $this->legacyEndCache)) {
            $value = Event::query()->find($eventId)?->legacy_zone_ends_at;
            $this->legacyEndCache[$eventId] = $value?->getTimestamp();
        }

        return $this->legacyEndCache[$eventId];
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
    private function reattributeQuery(Builder $query, string $modelClass, int $eventId, int $machineId, ?CarbonInterface $startsAt = null, ?CarbonInterface $endsAt = null): void
    {
        $query
            ->where('event_id', $eventId)
            ->where('machine_id', $machineId)
            ->when($startsAt, fn (Builder $query) => $query->where('sale_datetime', '>=', $startsAt))
            ->when($endsAt, fn (Builder $query) => $query->where('sale_datetime', '<', $endsAt))
            ->select(['id', 'event_zone_id', 'sale_datetime', 'sale_date'])
            ->orderBy('id')
            ->chunkById(500, function ($records) use ($modelClass, $eventId, $machineId): void {
                $updates = [];

                foreach ($records as $record) {
                    $zoneId = $this->zoneIdFor(
                        $eventId,
                        $machineId,
                        $record->sale_datetime,
                        $record->sale_date,
                    );

                    if (($record->event_zone_id === null ? null : (int) $record->event_zone_id) === $zoneId) {
                        continue;
                    }

                    $updates[$zoneId ?? 0][] = (int) $record->id;
                }

                foreach ($updates as $zoneKey => $ids) {
                    $modelClass::query()
                        ->whereIn('id', $ids)
                        ->update(['event_zone_id' => $zoneKey === 0 ? null : $zoneKey]);
                }
            });
    }

    private function timestamp(mixed $value, bool $startOfDay = false): ?int
    {
        if ($value instanceof CarbonInterface) {
            return ($startOfDay ? CarbonImmutable::instance($value)->startOfDay() : $value)->getTimestamp();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::parse($value);

            return ($startOfDay ? $parsed->startOfDay() : $parsed)->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
