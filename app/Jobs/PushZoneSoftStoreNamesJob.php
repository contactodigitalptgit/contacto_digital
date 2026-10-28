<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\EventZoneDay;
use App\Services\ZoneSoft\ZoneSoftStoreNameService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushZoneSoftStoreNamesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    /**
     * Sem dia, envia os nomes em vigor agora (ou no início do próximo dia
     * confirmado). Com dia, envia os nomes desse plano, mesmo em rascunho.
     *
     * @param  list<int>|null  $machineIds
     */
    public function __construct(
        public readonly int $eventId,
        public readonly ?int $dayId = null,
        public readonly ?array $machineIds = null,
        public readonly bool $force = false,
    ) {
        // The database worker owns the outbound network used by ZoneSoft.
        $this->onConnection('database');
    }

    public function handle(ZoneSoftStoreNameService $storeNames): void
    {
        $event = Event::query()->find($this->eventId);

        // Sem a opção ativa no evento, só o envio manual (force) muda nomes.
        if (! $event || (! $this->force && ! $event->zonesoft_names_enabled)) {
            return;
        }

        $day = $this->dayId ? EventZoneDay::query()->where('event_id', $event->id)->find($this->dayId) : null;

        if ($this->dayId && ! $day) {
            return;
        }

        $at = $day
            ? $this->clampToWindow($day->starts_at, $day->ends_at)
            : ($storeNames->automaticReferenceAt($event)
                ?? ($this->force ? $this->clampToWindow(
                    $event->report_starts_at ?? $event->event_date->copy()->startOfDay(),
                    $event->report_ends_at,
                ) : null));

        if (! $at) {
            return;
        }

        $storeNames->push($event, $at, $day, $this->machineIds, $this->force);
    }

    /**
     * Agora, dentro da janela; antes dela, o início; depois dela, o último
     * minuto (o estado com que o período terminou).
     */
    private function clampToWindow(CarbonInterface $startsAt, ?CarbonInterface $endsAt): CarbonImmutable
    {
        $now = ZoneSoftStoreNameService::localNow();

        if ($now->lessThan($startsAt)) {
            return CarbonImmutable::instance($startsAt);
        }

        return $endsAt && $now->greaterThanOrEqualTo($endsAt)
            ? CarbonImmutable::instance($endsAt)->subSecond()
            : $now;
    }
}
