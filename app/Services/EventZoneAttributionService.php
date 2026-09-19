<?php

namespace App\Services;

use App\Models\EventReportPaymentDocument;
use App\Models\EventReportRow;
use App\Models\EventZone;
use App\Models\EventZoneAssignment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;

class EventZoneAttributionService
{
    /** @var array<string, list<array{zone_id:int,starts_at:int,ends_at:?int}>> */
    private array $assignmentCache = [];

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
        return $this->labelCache[$eventId] ??= EventZone::query()
            ->where('event_id', $eventId)
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int|string $id): array => [(int) $id => $name])
            ->all();
    }

    /** @param array<int, string> $labels @return array<int, int> */
    public function zoneIdsForLabels(int $eventId, array $labels): array
    {
        $wanted = collect($labels)
            ->map(fn (string $label): string => Str::lower(trim($label)))
            ->filter()
            ->unique();

        return collect($this->labelsById($eventId))
            ->filter(fn (string $name): bool => $wanted->contains(Str::lower(trim($name))))
            ->keys()
            ->map(fn (int|string $id): int => (int) $id)
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

    public function zoneIdFor(int $eventId, int|string|null $machineId, mixed $saleDateTime, mixed $saleDate = null): ?int
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

        foreach ($assignments as $assignment) {
            if ($timestamp < $assignment['starts_at']) {
                continue;
            }

            if ($assignment['ends_at'] === null || $timestamp < $assignment['ends_at']) {
                return $assignment['zone_id'];
            }
        }

        return null;
    }

    public function reattributeMachine(int $eventId, int $machineId): void
    {
        $this->forget($eventId, $machineId);
        $this->reattributeQuery(EventReportRow::query(), EventReportRow::class, $eventId, $machineId);
        $this->reattributeQuery(
            EventReportPaymentDocument::query(),
            EventReportPaymentDocument::class,
            $eventId,
            $machineId,
        );
    }

    public function forget(int $eventId, ?int $machineId = null): void
    {
        unset($this->labelCache[$eventId], $this->configuredCache[$eventId]);

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

    /** @return list<array{zone_id:int,starts_at:int,ends_at:?int}> */
    private function assignmentsFor(int $eventId, int $machineId): array
    {
        $key = $eventId.'|'.$machineId;

        return $this->assignmentCache[$key] ??= EventZoneAssignment::query()
            ->where('event_id', $eventId)
            ->where('machine_id', $machineId)
            ->orderBy('starts_at')
            ->get(['event_zone_id', 'starts_at', 'ends_at'])
            ->map(fn (EventZoneAssignment $assignment): array => [
                'zone_id' => $assignment->event_zone_id,
                'starts_at' => $assignment->starts_at->getTimestamp(),
                'ends_at' => $assignment->ends_at?->getTimestamp(),
            ])
            ->all();
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
    private function reattributeQuery(Builder $query, string $modelClass, int $eventId, int $machineId): void
    {
        $query
            ->where('event_id', $eventId)
            ->where('machine_id', $machineId)
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
