<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class DashboardConfigurationService
{
    private const VERSION = 1;

    private const SECTION_DEFINITIONS = [
        'summary' => ['label' => 'Resumo', 'helper' => 'Visão geral'],
        'products' => ['label' => 'Produtos', 'helper' => 'Mais vendidos'],
        'zones' => ['label' => 'Zonas', 'helper' => 'Performance'],
        'reconciliation' => ['label' => 'Conciliação', 'helper' => 'Pagamentos'],
        'comparison' => ['label' => 'Comparativo', 'helper' => 'Entre eventos'],
        'highlights' => ['label' => 'Destaques', 'helper' => 'Rankings'],
        'charts' => ['label' => 'Gráficos', 'helper' => 'Análise visual'],
    ];

    private const BLOCK_DEFINITIONS = [
        'overview' => ['area' => 'summary', 'label' => 'Total faturado', 'helper' => 'Visão geral', 'requires_zt' => false],
        'movement' => ['area' => 'summary', 'label' => 'Vendas do evento', 'helper' => 'Leitura financeira', 'requires_zt' => false],
        'payments' => ['area' => 'summary', 'label' => 'Pagamentos das vendas', 'helper' => 'Formas de pagamento', 'requires_zt' => false],
        'top_up' => ['area' => 'summary', 'label' => 'Top-Up ZT - Card', 'helper' => 'Fluxo de cartões', 'requires_zt' => true],
        'operations' => ['area' => 'summary', 'label' => 'Indicadores operacionais', 'helper' => 'Operação', 'requires_zt' => false],
        'chart_financial' => ['area' => 'charts', 'label' => 'Vendas e carregamentos ZT', 'helper' => 'Leitura financeira', 'requires_zt' => true],
        'chart_daily' => ['area' => 'charts', 'label' => 'Evolução diária da faturação', 'helper' => 'Gráfico de linha', 'requires_zt' => false],
        'chart_hourly' => ['area' => 'charts', 'label' => 'Picos de vendas por hora', 'helper' => 'Gráfico de linha', 'requires_zt' => false],
        'chart_payments' => ['area' => 'charts', 'label' => 'Formas de pagamento', 'helper' => 'Gráfico de pizza', 'requires_zt' => false],
        'chart_zones' => ['area' => 'charts', 'label' => 'Faturação por zona', 'helper' => 'Gráfico de barras', 'requires_zt' => false],
        'chart_operations' => ['area' => 'charts', 'label' => 'Indicadores operacionais', 'helper' => 'Operação', 'requires_zt' => false],
    ];

    private const METRIC_DEFINITIONS = [
        'total_without_zt' => ['group' => 'movement', 'label' => 'Total faturado', 'helper' => 'Vendas de consumo', 'requires_zt' => false],
        'top_up_count' => ['group' => 'movement', 'label' => 'Carregamentos ZT', 'helper' => 'Cartões carregados', 'requires_zt' => true],
        'top_up_value' => ['group' => 'movement', 'label' => 'Valor ZT', 'helper' => 'Total carregado', 'requires_zt' => true],
        'total_with_zt' => ['group' => 'movement', 'label' => 'Total com ZT', 'helper' => 'Vendas + carregamentos', 'requires_zt' => true],
        'other_movements' => ['group' => 'movement', 'label' => 'Outros movimentos', 'helper' => 'Fora das vendas', 'requires_zt' => false],
        'multibanco' => ['group' => 'payments', 'label' => 'Multibanco', 'helper' => '', 'requires_zt' => false],
        'zticket' => ['group' => 'payments', 'label' => 'ZT - Card', 'helper' => '', 'requires_zt' => true],
        'cash' => ['group' => 'payments', 'label' => 'Dinheiro', 'helper' => '', 'requires_zt' => false],
        'other_payments' => ['group' => 'payments', 'label' => 'Outros pagamentos', 'helper' => '', 'requires_zt' => false],
        'loaded' => ['group' => 'top_up', 'label' => 'Valor carregado', 'helper' => 'carregamentos ZT', 'requires_zt' => true],
        'spent' => ['group' => 'top_up', 'label' => 'Valor gasto', 'helper' => 'Consumo ZT - Card', 'requires_zt' => true],
        'remaining' => ['group' => 'top_up', 'label' => 'Remanescente', 'helper' => 'Saldo não utilizado', 'requires_zt' => true],
        'devices' => ['group' => 'operations', 'label' => 'Total devices', 'helper' => 'Máquinas sincronizadas', 'requires_zt' => false],
        'zones' => ['group' => 'operations', 'label' => 'Zonas', 'helper' => 'Grupos operacionais', 'requires_zt' => false],
        'average_ticket' => ['group' => 'operations', 'label' => 'Ticket médio', 'helper' => 'Por documento', 'requires_zt' => false],
        'products' => ['group' => 'operations', 'label' => 'Produtos', 'helper' => 'Referências vendidas', 'requires_zt' => false],
        'average_device' => ['group' => 'operations', 'label' => 'Média por device', 'helper' => 'Faturação por máquina', 'requires_zt' => false],
    ];

    /**
     * @return array<string, mixed>
     */
    public function resolve(Event $event): array
    {
        if (! is_array($event->dashboard_configuration)) {
            return $this->defaults($event);
        }

        try {
            return $this->normalize($event, $event->dashboard_configuration);
        } catch (ValidationException $exception) {
            report($exception);

            return $this->defaults($event);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(Event $event): array
    {
        $showZt = (bool) $event->show_zt_card;

        return [
            'version' => self::VERSION,
            'preset' => 'complete',
            'customized' => false,
            'sections' => $this->defaultItems(self::SECTION_DEFINITIONS, $showZt),
            'blocks' => $this->defaultItems(self::BLOCK_DEFINITIONS, $showZt, [
                'overview' => ['label' => $showZt ? 'Total sem ZT' : 'Total faturado'],
                'movement' => ['label' => $showZt ? 'Vendas e carregamentos ZT' : 'Vendas do evento'],
            ]),
            'metrics' => $this->defaultItems(self::METRIC_DEFINITIONS, $showZt, [
                'total_without_zt' => ['label' => $showZt ? 'Total sem ZT' : 'Total faturado'],
                'other_movements' => ['helper' => $showZt ? 'Fora de vendas e ZT' : 'Fora das vendas'],
            ]),
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, description: string, configuration: array<string, mixed>}>
     */
    public function presets(Event $event): array
    {
        return [
            [
                'key' => 'complete',
                'label' => 'Completo',
                'description' => 'Mantém todas as páginas e indicadores disponíveis.',
                'configuration' => $this->preset($event, 'complete'),
            ],
            [
                'key' => 'executive',
                'label' => 'Executivo',
                'description' => 'Resumo e gráficos para uma leitura rápida da direção.',
                'configuration' => $this->preset($event, 'executive'),
            ],
            [
                'key' => 'financial',
                'label' => 'Financeiro',
                'description' => 'Prioriza vendas, pagamentos, conciliação e comparação.',
                'configuration' => $this->preset($event, 'financial'),
            ],
            [
                'key' => 'operational',
                'label' => 'Operacional',
                'description' => 'Prioriza produtos, zonas, picos de venda e rankings.',
                'configuration' => $this->preset($event, 'operational'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function normalize(Event $event, array $configuration): array
    {
        $preset = $configuration['preset'] ?? 'custom';

        if (! is_string($preset) || ! in_array($preset, ['complete', 'executive', 'financial', 'operational', 'custom'], true)) {
            $this->invalid('O preset informado não é válido.');
        }

        $sections = $this->normalizeItems(
            $configuration['sections'] ?? null,
            self::SECTION_DEFINITIONS,
            (bool) $event->show_zt_card,
            'páginas',
        );
        $blocks = $this->normalizeItems(
            $configuration['blocks'] ?? null,
            self::BLOCK_DEFINITIONS,
            (bool) $event->show_zt_card,
            'blocos',
        );
        $metrics = $this->normalizeItems(
            $configuration['metrics'] ?? null,
            self::METRIC_DEFINITIONS,
            (bool) $event->show_zt_card,
            'indicadores',
        );

        if (! collect($sections)->contains('visible', true)) {
            $this->invalid('Mantenha pelo menos uma página visível.');
        }

        foreach (['summary', 'charts'] as $area) {
            $sectionIsVisible = collect($sections)->contains(
                fn (array $section): bool => $section['key'] === $area && $section['visible'],
            );
            $hasVisibleBlock = collect($blocks)->contains(
                fn (array $block): bool => $block['area'] === $area
                    && $block['visible']
                    && $block['available'],
            );

            if ($sectionIsVisible && ! $hasVisibleBlock) {
                $this->invalid(sprintf('A página %s precisa de pelo menos um bloco visível.', $area === 'summary' ? 'Resumo' : 'Gráficos'));
            }
        }

        foreach (['movement', 'payments', 'top_up', 'operations'] as $group) {
            $blockIsVisible = collect($blocks)->contains(
                fn (array $block): bool => $block['key'] === $group
                    && $block['visible']
                    && $block['available'],
            );
            $hasVisibleMetric = collect($metrics)->contains(
                fn (array $metric): bool => $metric['group'] === $group
                    && $metric['visible']
                    && $metric['available'],
            );

            if ($blockIsVisible && ! $hasVisibleMetric) {
                $this->invalid('Cada bloco visível precisa de pelo menos um indicador visível.');
            }
        }

        return [
            'version' => self::VERSION,
            'preset' => $preset,
            'customized' => true,
            'sections' => $sections,
            'blocks' => $blocks,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     * @param  array<string, array<string, string>>  $overrides
     * @return array<int, array<string, mixed>>
     */
    private function defaultItems(array $definitions, bool $showZt, array $overrides = []): array
    {
        $items = [];

        foreach ($definitions as $key => $definition) {
            $requiresZt = (bool) ($definition['requires_zt'] ?? false);

            $items[] = [
                'key' => $key,
                ...Arr::except($definition, ['requires_zt']),
                ...($overrides[$key] ?? []),
                'visible' => true,
                'requires_zt' => $requiresZt,
                'available' => ! $requiresZt || $showZt,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(mixed $items, array $definitions, bool $showZt, string $itemLabel): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            $this->invalid(sprintf('A lista de %s é inválida.', $itemLabel));
        }

        $receivedKeys = array_map(
            fn (mixed $item): mixed => is_array($item) ? ($item['key'] ?? null) : null,
            $items,
        );

        if (
            count($receivedKeys) !== count($definitions)
            || count(array_unique($receivedKeys, SORT_REGULAR)) !== count($definitions)
            || array_diff(array_keys($definitions), $receivedKeys) !== []
        ) {
            $this->invalid(sprintf('A lista de %s está incompleta ou contém itens desconhecidos.', $itemLabel));
        }

        return array_map(function (mixed $item) use ($definitions, $showZt, $itemLabel): array {
            if (! is_array($item)) {
                $this->invalid(sprintf('Um item de %s é inválido.', $itemLabel));
            }

            $key = $item['key'] ?? null;
            $visible = $item['visible'] ?? null;
            $label = trim((string) ($item['label'] ?? ''));
            $helper = trim((string) ($item['helper'] ?? ''));

            if (! is_string($key) || ! isset($definitions[$key]) || ! is_bool($visible)) {
                $this->invalid(sprintf('Um item de %s contém dados inválidos.', $itemLabel));
            }

            if ($label === '' || mb_strlen($label) > 80 || mb_strlen($helper) > 120) {
                $this->invalid('As labels devem ter entre 1 e 80 caracteres e os textos auxiliares até 120 caracteres.');
            }

            $definition = $definitions[$key];
            $requiresZt = (bool) ($definition['requires_zt'] ?? false);

            return [
                'key' => $key,
                ...Arr::except($definition, ['label', 'helper', 'requires_zt']),
                'label' => $label,
                'helper' => $helper,
                'visible' => $visible,
                'requires_zt' => $requiresZt,
                'available' => ! $requiresZt || $showZt,
            ];
        }, $items);
    }

    /**
     * @return array<string, mixed>
     */
    private function preset(Event $event, string $preset): array
    {
        $configuration = $this->defaults($event);
        $configuration['preset'] = $preset;

        $visibility = match ($preset) {
            'executive' => [
                'sections' => ['summary', 'charts'],
                'blocks' => ['overview', 'movement', 'payments', 'top_up', 'operations', 'chart_financial', 'chart_daily', 'chart_hourly', 'chart_payments', 'chart_zones', 'chart_operations'],
            ],
            'financial' => [
                'sections' => ['summary', 'reconciliation', 'comparison', 'charts'],
                'blocks' => ['overview', 'movement', 'payments', 'top_up', 'chart_financial', 'chart_daily', 'chart_payments'],
            ],
            'operational' => [
                'sections' => ['summary', 'products', 'zones', 'highlights', 'charts'],
                'blocks' => ['overview', 'movement', 'operations', 'chart_daily', 'chart_hourly', 'chart_zones', 'chart_operations'],
            ],
            default => [
                'sections' => array_keys(self::SECTION_DEFINITIONS),
                'blocks' => array_keys(self::BLOCK_DEFINITIONS),
            ],
        };

        foreach (['sections', 'blocks'] as $type) {
            $configuration[$type] = array_map(
                fn (array $item): array => [
                    ...$item,
                    'visible' => in_array($item['key'], $visibility[$type], true),
                ],
                $configuration[$type],
            );
        }

        return $configuration;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages([
            'configuration' => $message,
        ]);
    }
}
