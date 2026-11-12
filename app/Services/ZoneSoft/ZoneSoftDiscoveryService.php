<?php

namespace App\Services\ZoneSoft;

use App\Models\ZoneSoftApplication;
use Illuminate\Support\Facades\Cache;

class ZoneSoftDiscoveryService
{
    private const STORE_CACHE_TTL_MINUTES = 15;

    /**
     * A ZSAPI limita stores/getInstances a 250 lojas por resposta, mesmo com
     * um "limit" maior: sem paginar, as lojas acima da 249 nunca aparecem e
     * a validacao dizia que o Store ID nao existia. Pagina-se com "offset"
     * ate vir uma pagina incompleta.
     */
    private const STORE_PAGE_SIZE = 250;

    private const STORE_MAX_PAGES = 40;

    public function __construct(
        private readonly ZoneSoftApiClient $client,
    ) {}

    /**
     * @return list<array{id: int, label: string, has_store_name: bool, display_label: string, details: string|null, country: string|null}>
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
     * @return list<array{id: int, label: string, has_store_name: bool, display_label: string, details: string|null, country: string|null}>
     */
    private function fetchStores(ZoneSoftApplication $application, string $zsClientId): array
    {
        $stores = [];

        for ($page = 0; $page < self::STORE_MAX_PAGES; $page++) {
            $response = $this->client->post(
                $application,
                $zsClientId,
                'stores',
                'getInstances',
                'store',
                [
                    'limit' => self::STORE_PAGE_SIZE,
                    'offset' => $page * self::STORE_PAGE_SIZE,
                    'order' => 'codigo ASC',
                ],
            );
            $batch = is_array($response['store'] ?? null) ? $response['store'] : [];
            $stores = [...$stores, ...$batch];

            if (count($batch) < self::STORE_PAGE_SIZE) {
                break;
            }
        }

        return collect($stores)
            ->filter(fn (mixed $store): bool => is_array($store) && isset($store['codigo']))
            ->map(function (array $store): array {
                $id = (int) $store['codigo'];
                $country = isset($store['pais']) ? trim((string) $store['pais']) : null;
                $label = $this->resolveStoreLabel($store, $id);
                $details = $this->resolveStoreDetails($store, $label);

                return [
                    'id' => $id,
                    'label' => $label,
                    'has_store_name' => trim((string) ($store['descricao'] ?? '')) !== '',
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
        $description = isset($store['descricao']) ? trim((string) $store['descricao']) : '';

        // Live ZSBMS discovery for Store 192 returned `descricao` = "Bilheteira"
        // and `designacao` = "Pausas Animadas - Lda" (Empresa).
        return $description !== '' ? $description : 'Loja '.$id;
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
            $display .= ' / Empresa: '.$details;
        }

        if ($country) {
            $display .= ' ('.$country.')';
        }

        return $display;
    }
}
