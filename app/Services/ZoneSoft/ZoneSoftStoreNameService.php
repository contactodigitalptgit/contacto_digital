<?php

namespace App\Services\ZoneSoft;

use App\Models\ClientZoneSoftMachine;
use App\Models\Event;
use App\Models\EventZoneAssignment;
use App\Models\EventZoneDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Escreve na ZoneSoft o nome da loja de cada TPA a partir da zona e do
 * operador atribuídos no portal, no formato "Bar 1 - Ana Caldas".
 * A ZSAPI (Stores::saveInstances) só permite alterar a descrição e a morada.
 */
class ZoneSoftStoreNameService
{
    public const MAX_NAME_LENGTH = 50;

    // Antes de um dia confirmado começar, os nomes já seguem para os TPAs.
    private const PREPARE_BEFORE_DAY_HOURS = 24;

    // Depois de uma falha, a tentativa automática espera antes de repetir.
    private const RETRY_AFTER_ERROR_MINUTES = 10;

    // Os horários das zonas são guardados na hora de Lisboa, sem fuso.
    private const BUSINESS_TIMEZONE = 'Europe/Lisbon';

    public function __construct(
        private readonly ZoneSoftApiClient $client,
    ) {}

    public static function localNow(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(self::BUSINESS_TIMEZONE)->format('Y-m-d H:i:s'));
    }

    public static function nameFor(string $zoneName, ?string $operatorName): string
    {
        $zoneName = trim($zoneName);
        $operatorName = trim((string) $operatorName);

        return $operatorName === '' ? $zoneName : $zoneName.' - '.$operatorName;
    }

    /**
     * Momento cujas atribuições devem estar refletidas nos TPAs, ou null quando
     * o evento não está a decorrer nem prestes a começar.
     */
    public function automaticReferenceAt(Event $event, ?CarbonInterface $now = null): ?CarbonImmutable
    {
        $now = $now ? CarbonImmutable::instance($now) : self::localNow();
        $confirmedDays = $event->zoneDays()->whereNotNull('confirmed_at')->orderBy('starts_at')->get();

        if ($confirmedDays->isNotEmpty()) {
            if ($confirmedDays->contains(fn (EventZoneDay $day): bool => $day->starts_at->lessThanOrEqualTo($now)
                && $day->ends_at->greaterThan($now))) {
                return $now;
            }

            $next = $confirmedDays->first(fn (EventZoneDay $day): bool => $day->starts_at->greaterThan($now));

            return $next && $next->starts_at->lessThanOrEqualTo($now->addHours(self::PREPARE_BEFORE_DAY_HOURS))
                ? CarbonImmutable::instance($next->starts_at)
                : null;
        }

        if ($event->zoneDays()->exists()) {
            return null;
        }

        $startsAt = $event->report_starts_at ?? $event->event_date?->copy()->startOfDay();
        $endsAt = $event->report_ends_at;

        if (! $startsAt || $now->lessThan(CarbonImmutable::instance($startsAt)->subHours(self::PREPARE_BEFORE_DAY_HOURS))
            || ($endsAt && $now->greaterThanOrEqualTo($endsAt))) {
            return null;
        }

        return $now->lessThan($startsAt) ? CarbonImmutable::instance($startsAt) : $now;
    }

    /**
     * Nome esperado de cada TPA no momento indicado, pela atribuição em vigor.
     *
     * @param  list<int>|null  $machineIds
     * @return Collection<int, string> machine_id => nome
     */
    public function expectedNames(
        Event $event,
        CarbonInterface $at,
        ?EventZoneDay $day = null,
        ?array $machineIds = null,
    ): Collection {
        return EventZoneAssignment::query()
            ->with('zone:id,name,event_zone_day_id,archived_at')
            ->where('event_id', $event->id)
            ->when($machineIds !== null, fn ($query) => $query->whereIn('machine_id', $machineIds))
            ->where('starts_at', '<=', $at)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->whereHas('zone', function ($zones) use ($day): void {
                $zones->whereNull('archived_at');

                if ($day) {
                    $zones->where('event_zone_day_id', $day->id);

                    return;
                }

                // Sem dia escolhido, só valem planos confirmados (ou zonas antigas).
                $zones->where(fn ($query) => $query
                    ->whereNull('event_zone_day_id')
                    ->orWhereHas('day', fn ($days) => $days->whereNotNull('confirmed_at')));
            })
            ->orderBy('starts_at')
            ->get()
            ->mapWithKeys(fn (EventZoneAssignment $assignment): array => [
                (int) $assignment->machine_id => self::nameFor((string) $assignment->zone?->name, $assignment->operator_name),
            ]);
    }

    /**
     * @param  list<int>|null  $machineIds
     * @return array{pushed: int, unchanged: int, failed: int}
     */
    public function push(
        Event $event,
        CarbonInterface $at,
        ?EventZoneDay $day = null,
        ?array $machineIds = null,
        bool $force = false,
    ): array {
        $expected = $this->expectedNames($event, $at, $day, $machineIds);
        $result = ['pushed' => 0, 'unchanged' => 0, 'failed' => 0];

        if ($expected->isEmpty()) {
            return $result;
        }

        $machines = $event->zonesoftMachines()
            ->with('application')
            ->whereIn('client_zonesoft_machines.id', $expected->keys())
            ->get();

        foreach ($machines as $machine) {
            $name = $expected->get($machine->id);

            if (! $force && $this->isUpToDate($machine, $name)) {
                $result['unchanged']++;

                continue;
            }

            if (! $force && $this->isWaitingAfterError($machine)) {
                $result['failed']++;

                continue;
            }

            $this->pushMachine($machine, $name) ? $result['pushed']++ : $result['failed']++;
        }

        return $result;
    }

    public function pushMachine(ClientZoneSoftMachine $machine, string $name): bool
    {
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return $this->fail($machine, sprintf(
                'O nome "%s" tem mais de %d caracteres, o limite da ZoneSoft.',
                $name,
                self::MAX_NAME_LENGTH,
            ));
        }

        if (! $machine->application) {
            return $this->fail($machine, 'Este TPA não tem aplicação ZoneSoft associada.');
        }

        try {
            $this->client->post(
                $machine->application,
                $machine->zs_client_id,
                'stores',
                'saveInstances',
                'store',
                [['codigo' => (int) $machine->store_id, 'descricao' => $name]],
                retryUnauthorized: true,
            );
        } catch (ZoneSoftApiException $exception) {
            report($exception);

            return $this->fail($machine, in_array($exception->statusCode(), [401, 403], true)
                ? 'A ZoneSoft não autorizou alterar o nome desta loja (Stores::saveInstances). Confirme as permissões da integração na ZoneSoft.'
                : 'A ZoneSoft recusou o nome: '.$exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->fail($machine, 'A ZoneSoft recusou o nome: '.$exception->getMessage());
        }

        // O nome passa a ser o do portal. Não se usa synchronizeMachineLabel:
        // as vendas já guardadas mantêm o nome que tinham.
        $machine->forceFill([
            'store_label' => $name,
            'zonesoft_name_synced_at' => now(),
            'zonesoft_name_error' => null,
        ])->save();

        return true;
    }

    private function isUpToDate(ClientZoneSoftMachine $machine, string $name): bool
    {
        return trim((string) $machine->store_label) === $name
            && $machine->zonesoft_name_synced_at !== null
            && $machine->zonesoft_name_error === null;
    }

    private function isWaitingAfterError(ClientZoneSoftMachine $machine): bool
    {
        return $machine->zonesoft_name_error !== null
            && $machine->updated_at?->greaterThan(now()->subMinutes(self::RETRY_AFTER_ERROR_MINUTES));
    }

    private function fail(ClientZoneSoftMachine $machine, string $message): bool
    {
        $machine->forceFill(['zonesoft_name_error' => mb_substr($message, 0, 500)]);
        $machine->isDirty() ? $machine->save() : $machine->touch();

        return false;
    }
}
