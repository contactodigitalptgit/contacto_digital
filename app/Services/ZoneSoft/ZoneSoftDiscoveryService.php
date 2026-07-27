<?php

namespace App\Services\ZoneSoft;

use App\Models\ZoneSoftApplication;
use Illuminate\Support\Facades\Cache;

class ZoneSoftDiscoveryService
{
    private const STORE_CACHE_TTL_MINUTES = 15;

    public function __construct(
        private readonly ZoneSoftApiClient $client,
    ) {}

    /**
     * @return list<array{id: int, label: string, display_label: string, details: string|null, country: string|null}>
     */
    public function discoverStores(
        ZoneSoftApplication $application,
        string $zsClientId,
        bool $useCache = true,
    ): array {
        if (! $useCache) {
            return $this->fetchStores($application, $zsClientId);
        }

        return Cache::remember(
            $this->buildCacheKey($application, $zsClientId),
            now()->addMinutes(self::STORE_CACHE_TTL_MINUTES),
            fn (): array => $this->fetchStores($application, $zsClientId),
        );
    }

    /**
     * @return list<array{id: int, label: string, display_label: string, details: string|null, country: string|null}>
     */
    private function fetchStores(ZoneSoftApplication $application, string $zsClientId): array
    {
        $response = $this->client->post(
            $application,
            $zsClientId,
            'stores',
            'getInstances',
            'store',
            [
                'limit' => 500,
                'order' => 'codigo ASC',
            ],
        );

        return collect($response['store'] ?? [])
            ->filter(fn (mixed $store): bool => is_array($store) && isset($store['codigo']))
            ->map(function (array $store): array {
                $id = (int) $store['codigo'];
                $country = isset($store['pais']) ? trim((string) $store['pais']) : null;
                $label = $this->resolveStoreLabel($store, $id);
                $details = $this->resolveStoreDetails($store, $label);

                return [
                    'id' => $id,
                    'label' => $label,
                    'display_label' => $this->buildDisplayLabel($id, $label, $country, $details),
                    'details' => $details,
                    'country' => $country !== '' ? $country : null,
                ];
            })
            ->sortBy('id')
            ->values()
            ->all();
    }

    private function buildCacheKey(ZoneSoftApplication $application, string $zsClientId): string
    {
        return sprintf(
            'zonesoft:stores:%d:%s',
            $application->id,
            sha1(implode('|', [
                $application->base_url,
                $application->app_key,
                $zsClientId,
            ])),
        );
    }

    private function resolveStoreLabel(array $store, int $id): string
    {
        $candidates = collect([
            isset($store['designacao']) ? trim((string) $store['designacao']) : '',
            isset($store['descricao']) ? trim((string) $store['descricao']) : '',
        ])
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->sortBy(fn (string $value): int => mb_strlen($value))
            ->values();

        return $candidates->first() ?? 'Loja '.$id;
    }

    private function resolveStoreDetails(array $store, string $label): ?string
    {
        $details = collect([
            isset($store['designacao']) ? trim((string) $store['designacao']) : '',
            isset($store['descricao']) ? trim((string) $store['descricao']) : '',
        ])
            ->filter(fn (string $value): bool => $value !== '' && $value !== $label)
            ->unique()
            ->values()
            ->implode(' / ');

        return $details !== '' ? $details : null;
    }

    private function buildDisplayLabel(int $id, string $label, ?string $country, ?string $details): string
    {
        $display = sprintf('Loja %d - %s', $id, $label);

        if ($details) {
            $display .= ' / '.$details;
        }

        if ($country) {
            $display .= ' ('.$country.')';
        }

        return $display;
    }
}
