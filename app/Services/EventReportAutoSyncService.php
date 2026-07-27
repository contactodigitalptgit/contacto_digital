<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventReportImport;
use Carbon\CarbonInterface;

class EventReportAutoSyncService
{
    public function enabled(): bool
    {
        return (bool) config('event-reports.automatic_sync.enabled', true);
    }

    public function intervalMinutes(): int
    {
        $configuredInterval = (int) config('event-reports.automatic_sync.interval_minutes', 15);
        $minimumInterval = (int) config('event-reports.automatic_sync.minimum_interval_minutes', 10);

        return max($minimumInterval, min(1440, $configuredInterval));
    }

    /**
     * @return array{enabled: bool, state: string, interval_minutes: int, next_sync_at: string|null}
     */
    public function status(Event $event): array
    {
        if (! $this->enabled()) {
            return $this->makeStatus(false, 'disabled');
        }

        if (! $this->isWithinReportWindow($event)) {
            return $this->makeStatus(false, 'outside_window');
        }

        if ($event->latestReportImport?->status === 'processing') {
            return $this->makeStatus(true, 'processing');
        }

        $nextSyncAt = $this->nextSyncAt($event);

        if (! $nextSyncAt) {
            return $this->makeStatus(false, 'finished');
        }

        return $this->makeStatus(
            true,
            $nextSyncAt->isFuture() ? 'scheduled' : 'due',
            $nextSyncAt,
        );
    }

    public function nextDueEvent(): ?Event
    {
        if (! $this->enabled() || EventReportImport::query()->where('status', 'processing')->exists()) {
            return null;
        }

        return Event::query()
            ->where('is_active', true)
            ->whereNotNull('report_ends_at')
            ->whereHas('client', fn ($query) => $query->where('is_active', true))
            ->whereHas('client.zonesoftMachines', fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('application', fn ($application) => $application->where('is_active', true)))
            ->with(['latestActiveReportImport', 'latestReportImport'])
            ->get()
            ->filter(fn (Event $event): bool => $this->isDue($event))
            ->sortBy(fn (Event $event): int => $this->nextSyncAt($event)?->getTimestamp() ?? 0)
            ->first();
    }

    public function isDue(Event $event): bool
    {
        if (! $this->enabled() || ! $this->isWithinReportWindow($event)) {
            return false;
        }

        if ($event->latestReportImport?->status === 'processing') {
            return false;
        }

        $nextSyncAt = $this->nextSyncAt($event);

        return $nextSyncAt !== null && ! $nextSyncAt->isFuture();
    }

    public function isWithinReportWindow(Event $event): bool
    {
        if (! $event->is_active || ! $event->event_date || ! $event->report_ends_at) {
            return false;
        }

        $startsAt = $event->report_starts_at?->copy() ?? $event->event_date->copy()->startOfDay();

        return now()->betweenIncluded($startsAt, $event->report_ends_at);
    }

    private function nextSyncAt(Event $event): ?CarbonInterface
    {
        $latestImport = $event->latestReportImport;

        $nextSyncAt = $latestImport
            ? ($latestImport->imported_at ?? $latestImport->updated_at ?? $latestImport->created_at)
                ?->copy()
                ->addMinutes($this->intervalMinutes())
            : ($event->report_starts_at?->copy() ?? $event->event_date?->copy()->startOfDay());

        if (! $nextSyncAt || $nextSyncAt->gt($event->report_ends_at)) {
            return null;
        }

        return $nextSyncAt;
    }

    /**
     * @return array{enabled: bool, state: string, interval_minutes: int, next_sync_at: string|null}
     */
    private function makeStatus(
        bool $enabled,
        string $state,
        ?CarbonInterface $nextSyncAt = null,
    ): array {
        return [
            'enabled' => $enabled,
            'state' => $state,
            'interval_minutes' => $this->intervalMinutes(),
            'next_sync_at' => $nextSyncAt?->toISOString(),
        ];
    }
}
