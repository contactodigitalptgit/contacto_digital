<?php

namespace App\Services\ZoneSoft;

use App\Models\Client;
use App\Models\ClientZoneSoftMachine;
use App\Models\ZoneSoftApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ZoneSoftMachineBulkImportService
{
    public const FORMAT = 'contacto-digital-zonesoft-import';

    public const VERSION = 1;

    private const DEFAULT_PERMISSIONS = 'API + All document interfaces';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(
        Client $client,
        ZoneSoftApplication $application,
        array $payload,
        bool $lockExisting = false,
    ): array {
        $sourceApplication = trim((string) data_get($payload, 'application.name'));

        if (mb_strtolower($sourceApplication) !== mb_strtolower(trim($application->name))) {
            throw ValidationException::withMessages([
                'payload.application.name' => sprintf(
                    'O lote pertence à aplicação "%s", mas a plataforma está configurada para "%s".',
                    $sourceApplication,
                    $application->name,
                ),
            ]);
        }

        $query = ClientZoneSoftMachine::query()
            ->where('client_id', $client->id)
            ->where('zonesoft_application_id', $application->id);

        if ($lockExisting) {
            $query->lockForUpdate();
        }

        $existingMachines = $query->get();
        $existingByIdentity = $existingMachines->keyBy(
            fn (ClientZoneSoftMachine $machine): string => $this->identityKey(
                $machine->zs_client_id,
                $machine->store_id,
            ),
        );
        $existingByStore = $existingMachines->keyBy(
            fn (ClientZoneSoftMachine $machine): string => $this->storeKey(
                (string) $machine->license,
                $machine->store_id,
            ),
        );

        $seenIdentities = [];
        $seenStores = [];
        $rows = [];
        $summary = [
            'total' => count($payload['machines']),
            'new' => 0,
            'existing' => 0,
            'conflicts' => 0,
            'invalid' => 0,
        ];

        foreach ($payload['machines'] as $index => $sourceRow) {
            $row = $this->normalizeRow($sourceRow);
            $identityKey = $this->identityKey($row['zs_client_id'], $row['store_id']);
            $storeKey = $this->storeKey($row['license'], $row['store_id']);
            $status = 'new';
            $message = 'Pronta para importar.';

            if (isset($seenIdentities[$identityKey]) || isset($seenStores[$storeKey])) {
                $status = 'invalid';
                $message = 'A mesma identidade ou licença/Store ID aparece mais de uma vez no lote.';
            } else {
                $existingIdentity = $existingByIdentity->get($identityKey);
                $existingStore = $existingByStore->get($storeKey);

                if ($existingIdentity && strtoupper((string) $existingIdentity->license) !== $row['license']) {
                    $status = 'conflict';
                    $message = sprintf(
                        'Este Client ID/Store ID já existe com a licença %s.',
                        $existingIdentity->license,
                    );
                } elseif ($existingStore && mb_strtolower($existingStore->zs_client_id) !== mb_strtolower($row['zs_client_id'])) {
                    $status = 'conflict';
                    $message = 'Esta licença/Store ID já está associada a outro Client ID.';
                } elseif ($existingIdentity) {
                    $status = 'existing';
                    $message = 'Já existe com os mesmos dados; não será duplicada.';
                }
            }

            $seenIdentities[$identityKey] = true;
            $seenStores[$storeKey] = true;
            $summary[$status === 'conflict' ? 'conflicts' : $status]++;
            $rows[] = [
                'line' => $index + 1,
                ...$row,
                'status' => $status,
                'message' => $message,
            ];
        }

        return [
            'source_application' => [
                'id' => (string) data_get($payload, 'application.id'),
                'name' => $sourceApplication,
            ],
            'client' => ['id' => $client->id, 'name' => $client->name],
            'summary' => $summary,
            'can_import' => $summary['conflicts'] === 0 && $summary['invalid'] === 0,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function import(Client $client, ZoneSoftApplication $application, array $payload): array
    {
        return DB::transaction(function () use ($client, $application, $payload): array {
            $preview = $this->preview($client, $application, $payload, true);

            if (! $preview['can_import']) {
                throw ValidationException::withMessages([
                    'payload' => 'O lote mudou ou contém conflitos. Faça uma nova pré-visualização antes de importar.',
                ]);
            }

            $created = 0;

            foreach ($preview['rows'] as $row) {
                if ($row['status'] !== 'new') {
                    continue;
                }

                ClientZoneSoftMachine::query()->create([
                    'client_id' => $client->id,
                    'zonesoft_application_id' => $application->id,
                    'zs_client_id' => $row['zs_client_id'],
                    'license' => $row['license'],
                    'store_id' => $row['store_id'],
                    'store_label' => sprintf('Store %d', $row['store_id']),
                    'permissions' => $row['permissions'],
                    'is_active' => true,
                    'last_validated_at' => null,
                    'last_error' => null,
                ]);
                $created++;
            }

            return [
                'message' => sprintf(
                    '%d integração(ões) importadas e %d já existentes.',
                    $created,
                    $preview['summary']['existing'],
                ),
                'created' => $created,
                'existing' => $preview['summary']['existing'],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $sourceRow
     * @return array{zs_client_id: string, license: string, store_id: int, permissions: string}
     */
    private function normalizeRow(array $sourceRow): array
    {
        return [
            'zs_client_id' => trim((string) $sourceRow['zs_client_id']),
            'license' => strtoupper(trim((string) $sourceRow['license'])),
            'store_id' => (int) $sourceRow['store_id'],
            'permissions' => $this->normalizePermissions($sourceRow['permissions'] ?? null),
        ];
    }

    private function normalizePermissions(mixed $permissions): string
    {
        $value = trim((string) $permissions);

        if ($value === '') {
            return self::DEFAULT_PERMISSIONS;
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded) && (int) ($decoded['api'] ?? 0) === 3) {
            return self::DEFAULT_PERMISSIONS;
        }

        return $value;
    }

    private function identityKey(string $zsClientId, int $storeId): string
    {
        return mb_strtolower(trim($zsClientId)).'|'.$storeId;
    }

    private function storeKey(string $license, int $storeId): string
    {
        return mb_strtolower(trim($license)).'|'.$storeId;
    }
}
