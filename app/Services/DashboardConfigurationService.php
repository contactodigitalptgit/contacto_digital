<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Arr;

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
        'top_up' => ['area' => 'summary', 'label' => 'Top up', 'helper' => 'Fluxo de cartões', 'requires_zt' => true],
        'operations' => ['area' => 'summary', 'label' => 'Indicadores operacionais', 'helper' => 'Operação', 'requires_zt' => false],
        'chart_financial' => ['area' => 'charts', 'label' => 'Vendas e carregamentos Top up', 'helper' => 'Leitura financeira', 'requires_zt' => true],
        'chart_daily' => ['area' => 'charts', 'label' => 'Evolução diária da faturação', 'helper' => 'Gráfico de linha', 'requires_zt' => false],
        'chart_hourly' => ['area' => 'charts', 'label' => 'Picos de vendas por hora', 'helper' => 'Gráfico de linha', 'requires_zt' => false],
        'chart_payments' => ['area' => 'charts', 'label' => 'Formas de pagamento', 'helper' => 'Gráfico de pizza', 'requires_zt' => false],
        'chart_zones' => ['area' => 'charts', 'label' => 'Faturação por zona', 'helper' => 'Gráfico de barras', 'requires_zt' => false],
        'chart_operations' => ['area' => 'charts', 'label' => 'Indicadores operacionais', 'helper' => 'Operação', 'requires_zt' => false],
    ];

    private const METRIC_DEFINITIONS = [
        'total_without_zt' => ['group' => 'movement', 'label' => 'Total faturado', 'helper' => 'Vendas de consumo', 'requires_zt' => false],
        'top_up_count' => ['group' => 'movement', 'label' => 'Carregamentos Top up', 'helper' => 'Cartões carregados', 'requires_zt' => true],
        'top_up_value' => ['group' => 'movement', 'label' => 'Valor Top up', 'helper' => 'Total carregado', 'requires_zt' => true],
        'total_with_zt' => ['group' => 'movement', 'label' => 'Total com Top up', 'helper' => 'Vendas + carregamentos', 'requires_zt' => true],
        'other_movements' => ['group' => 'movement', 'label' => 'Outros movimentos', 'helper' => 'Fora das vendas', 'requires_zt' => false],
        'multibanco' => ['group' => 'payments', 'label' => 'Multibanco', 'helper' => '', 'requires_zt' => false],
        'zticket' => ['group' => 'payments', 'label' => 'Top up', 'helper' => '', 'requires_zt' => true],
        'cash' => ['group' => 'payments', 'label' => 'Dinheiro', 'helper' => '', 'requires_zt' => false],
        'other_payments' => ['group' => 'payments', 'label' => 'Outros pagamentos', 'helper' => '', 'requires_zt' => false],
        'loaded' => ['group' => 'top_up', 'label' => 'Valor carregado', 'helper' => 'carregamentos Top up', 'requires_zt' => true],
        'spent' => ['group' => 'top_up', 'label' => 'Valor gasto', 'helper' => 'Consumo Top up', 'requires_zt' => true],
        'remaining' => ['group' => 'top_up', 'label' => 'Remanescente', 'helper' => 'Saldo não utilizado', 'requires_zt' => true],
        'devices' => ['group' => 'operations', 'label' => 'Total devices', 'helper' => 'Máquinas sincronizadas', 'requires_zt' => false],
        'zones' => ['group' => 'operations', 'label' => 'Zonas', 'helper' => 'Grupos operacionais', 'requires_zt' => false],
        'average_ticket' => ['group' => 'operations', 'label' => 'Ticket médio', 'helper' => 'Por documento', 'requires_zt' => false],
        'products' => ['group' => 'operations', 'label' => 'Produtos', 'helper' => 'Referências vendidas', 'requires_zt' => false],
        'average_device' => ['group' => 'operations', 'label' => 'Média por device', 'helper' => 'Faturação por máquina', 'requires_zt' => false],
    ];

    /**
     * The dashboard layout is no longer editable per event: every event uses
     * the complete default layout, ignoring any older saved configuration.
     *
     * @return array<string, mixed>
     */
    public function resolve(Event $event): array
    {
        return $this->defaults($event);
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
                'overview' => ['label' => $showZt ? 'Total sem Top up' : 'Total faturado'],
                'movement' => ['label' => $showZt ? 'Vendas e carregamentos Top up' : 'Vendas do evento'],
            ]),
            'metrics' => $this->defaultItems(self::METRIC_DEFINITIONS, $showZt, [
                'total_without_zt' => ['label' => $showZt ? 'Total sem Top up' : 'Total faturado'],
                'other_movements' => ['helper' => $showZt ? 'Fora de vendas e Top up' : 'Fora das vendas'],
            ]),
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
}
