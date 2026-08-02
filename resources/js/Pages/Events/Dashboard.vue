<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { showErrorToast, showSuccessToast } from '@/lib/swal';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

type DashboardSection = 'summary' | 'products' | 'zones' | 'reconciliation' | 'comparison' | 'highlights' | 'charts';
type ProductSort = 'quantity' | 'sales';
type ViewMode = 'list' | 'chart';
type HighlightScope = 'zones' | 'devices' | 'products';
type DetailModal = 'payments' | 'topup' | 'ticket' | null;

interface EventMeta {
    id: number;
    title: string;
    description: string | null;
    event_date: string;
    client_name: string;
    client_business_name: string | null;
    processing_imports_count: number;
    last_synced_at: string | null;
    show_zt_card: boolean;
}

interface EventOption {
    id: number;
    title: string;
    event_date: string;
    url: string;
    is_current: boolean;
}

interface EventSummary {
    processing_imports_count: number;
    total_rows: number;
    filtered_rows: number;
    bar_groups_count: number;
    total_sales: number;
    total_value: number;
    total_discount: number;
    total_quantity: number;
    stores_count: number;
    tickets_count: number;
    products_count: number;
    document_types_count: number;
    average_ticket: number;
    last_synced_at: string | null;
    machines_count: number;
}

interface AutoSyncMeta {
    enabled: boolean;
    state: 'disabled' | 'outside_window' | 'finished' | 'processing' | 'scheduled' | 'due';
    interval_minutes: number;
    next_sync_at: string | null;
}

interface BreakdownItem {
    label: string;
    code: string | null;
    rows_count: number;
    quantity_total: number;
    sales_total: number;
}

interface BarGroupItem {
    label: string;
    stores_count: number;
    members: string[];
    rows_count: number;
    quantity_total: number;
    sales_total: number;
}

interface ZoneDeviceGroup {
    label: string;
    devices_count: number;
    total_sales: number;
    items: BreakdownItem[];
}

interface PaymentSummary {
    available: boolean;
    source: string;
    documents_count: number;
    movement_documents_count: number;
    multibanco: number;
    cash: number;
    zticket: number;
    other: number;
    sales_total: number;
    total_without_zt: number;
    total_with_zt: number;
    movement_total: number;
    other_movements: number;
    top_up_documents_count: number;
    top_up_loaded: number;
    top_up_spent: number;
    top_up_remaining: number;
    scope_note: string;
}

interface DailySale {
    date: string;
    label: string;
    sales_total: number;
    quantity_total: number;
    tickets_count: number;
}

interface DailyBreakdown extends DailySale {
    average_ticket: number;
    multibanco: number;
    cash: number;
    zticket: number;
    other: number;
    top_up_documents_count: number;
    top_up_loaded: number;
    top_up_spent: number;
    top_up_remaining: number;
    total_with_zt: number;
    other_movements: number;
}

interface HourlySale {
    date: string;
    label: string;
    hour: number;
    hour_label: string;
    sales_total: number;
    tickets_count: number;
}

interface ProductDay {
    date: string;
    label: string;
    items: BreakdownItem[];
}

interface ProductBreakdowns {
    total: BreakdownItem[];
    days: ProductDay[];
}

interface ReconciliationItem {
    store_code: string | null;
    store_name: string;
    documents_count: number;
    multibanco: number;
    cash: number;
    zticket: number;
    other: number;
    payments_total: number;
    sales_total: number;
    difference: number | null;
}

interface ReconciliationData {
    available: boolean;
    comparable: boolean;
    documents_count: number;
    scope_note: string;
    totals: Omit<ReconciliationItem, 'store_code' | 'store_name' | 'documents_count'>;
    items: ReconciliationItem[];
}

interface ComparisonSnapshot {
    event_id: number;
    title: string;
    event_date: string;
    total_sales: number;
    machines_count: number;
    zones_count: number;
    tickets_count: number;
    average_ticket: number;
    average_per_device: number;
    payments: Record<'multibanco' | 'cash' | 'zticket' | 'other', number>;
}

interface ComparisonMetric {
    key: string;
    label: string;
    format?: 'currency' | 'number';
    current: number;
    previous: number;
    variation: number | null;
}

interface ComparisonData {
    available: boolean;
    message?: string;
    current?: ComparisonSnapshot;
    previous?: ComparisonSnapshot;
    total_variation?: number | null;
    metrics?: ComparisonMetric[];
    payments?: ComparisonMetric[];
}

interface DashboardFilters {
    bar_group: string;
    store: string;
    product: string;
    date_from: string;
    date_to: string;
    total_min: string;
    total_max: string;
}

interface FilterOption {
    value: string;
    label: string;
    rows_count: number;
}

interface FilterOptions {
    barGroups: FilterOption[];
    stores: FilterOption[];
    products: FilterOption[];
}

interface MetricCard {
    label: string;
    value: string;
    helper: string;
}

interface ZonePerformanceRow {
    label: string;
    totalSales: number;
    devicesCount: number;
    averageSales: number;
    performanceWidth: string;
}

interface HighlightItem {
    key: string;
    label: string;
    helper: string;
    value: number;
}

interface ChartPaymentItem {
    key: string;
    label: string;
    value: number;
    percentage: number;
    color: string;
}

interface ChartZoneItem {
    label: string;
    value: number;
    devicesCount: number;
    height: string;
}

interface ChartDailyPoint extends DailySale {
    x: number;
    y: number;
}

interface ChartHourlyPoint extends HourlySale {
    x: number;
    y: number;
}

interface ChartOperationalItem {
    label: string;
    value: number;
    helper: string;
    height: string;
}

const props = defineProps<{
    event: EventMeta;
    eventOptions: EventOption[];
    summary: EventSummary;
    barGroups: BarGroupItem[];
    topStores: BreakdownItem[];
    topProducts: BreakdownItem[];
    productBreakdowns: ProductBreakdowns;
    dailySales: DailySale[];
    dailyBreakdowns: DailyBreakdown[];
    hourlySales: HourlySale[];
    paymentSummary: PaymentSummary;
    reconciliation: ReconciliationData;
    comparison: ComparisonData;
    zoneDevices: ZoneDeviceGroup[];
    filters: DashboardFilters;
    filterOptions: FilterOptions;
    autoSync: AutoSyncMeta;
    previewMode?: boolean;
    backUrl: string;
    backLabel: string;
}>();

const dashboardSections: Array<{
    key: DashboardSection;
    number: string;
    label: string;
    helper: string;
}> = [
    { key: 'summary', number: '01', label: 'Resumo', helper: 'Visão geral' },
    { key: 'products', number: '02', label: 'Produtos', helper: 'Mais vendidos' },
    { key: 'zones', number: '03', label: 'Zonas', helper: 'Performance' },
    { key: 'reconciliation', number: '04', label: 'Conciliação', helper: 'Pagamentos' },
    { key: 'comparison', number: '05', label: 'Comparativo', helper: 'Entre eventos' },
    { key: 'highlights', number: '06', label: 'Destaques', helper: 'Rankings' },
    { key: 'charts', number: '07', label: 'Gráficos', helper: 'Análise visual' },
];

const activeSection = ref<DashboardSection>('summary');
const selectedProductView = ref('total');
const productSearch = ref('');
const productSort = ref<ProductSort>('quantity');
const productViewMode = ref<ViewMode>('list');
const zoneSearch = ref('');
const reconciliationSearch = ref('');
const highlightScope = ref<HighlightScope>('zones');
const highlightSearch = ref('');
const highlightViewMode = ref<ViewMode>('list');
const selectedHourlyDate = ref('all');
const detailModal = ref<DetailModal>(null);
const filtersOpen = ref(false);
const eventSwitcherOpen = ref(false);
const isSyncingReport = ref(false);
const syncIntegrationError = ref('');
const dashboardPollerId = ref<number | null>(null);
const autoSyncClockId = ref<number | null>(null);
const currentTimestamp = ref(Date.now());
const lastAutoSyncRefreshAt = ref(0);
const isRefreshingAutoSync = ref(false);
const localFilters = ref<DashboardFilters>({ ...props.filters });
const showZtCard = computed(() => props.event.show_zt_card);
const whatsappSupportUrl = 'https://api.whatsapp.com/send/?phone=351910918377&text=Ol%C3%A1%2C+preciso+de+ajuda+com+o+relat%C3%B3rio+Contacto+Digital.&type=phone_number&app_absent=0';

const hasImportedData = computed(
    () => props.summary.total_rows > 0 || props.paymentSummary.movement_documents_count > 0,
);
const hasProcessingSync = computed(
    () => props.summary.processing_imports_count > 0 || props.autoSync.state === 'processing',
);
const nextSyncSeconds = computed(() => {
    if (!props.autoSync.enabled || !props.autoSync.next_sync_at) {
        return null;
    }

    return Math.max(0, Math.ceil((Date.parse(props.autoSync.next_sync_at) - currentTimestamp.value) / 1000));
});
const autoSyncCountdown = computed(() => {
    if (hasProcessingSync.value) {
        return 'A sincronizar';
    }

    if (!props.autoSync.enabled || nextSyncSeconds.value === null) {
        return 'Encerrada';
    }

    if (nextSyncSeconds.value === 0) {
        return '00:00';
    }

    const hours = Math.floor(nextSyncSeconds.value / 3600);
    const minutes = Math.floor((nextSyncSeconds.value % 3600) / 60);
    const seconds = nextSyncSeconds.value % 60;
    const parts = [minutes, seconds].map((part) => String(part).padStart(2, '0'));

    return hours > 0
        ? `${String(hours).padStart(2, '0')}:${parts.join(':')}`
        : parts.join(':');
});
const autoSyncStatusLabel = computed(() => {
    if (hasProcessingSync.value) {
        return 'Sincronização em curso';
    }

    return props.autoSync.enabled
        ? `Automática a cada ${props.autoSync.interval_minutes} min`
        : 'Sincronização automática encerrada';
});
const activeFilterCount = computed(
    () => Object.values(props.filters).filter((value) => value !== '').length,
);
const brandSubtitle = computed(() => {
    const parts = [props.event.client_name, formatDate(props.event.event_date)].filter(Boolean);

    return parts.join(' - ');
});
const hasEventOptions = computed(() => props.eventOptions.length > 1);
const currentEventOption = computed(() => props.eventOptions.find((event) => event.is_current));
const eventSwitcherTitle = computed(() => currentEventOption.value?.title ?? props.event.title);
const showZtPaymentDetails = computed(() => showZtCard.value);

const paymentCards = computed<MetricCard[]>(() => {
    const cards: MetricCard[] = [
        {
            label: 'Multibanco',
            value: formatMoney(props.paymentSummary.multibanco),
            helper: getPaymentShare(props.paymentSummary.multibanco),
        },
    ];

    if (showZtPaymentDetails.value) {
        cards.push({
            label: 'ZT - Card',
            value: formatMoney(props.paymentSummary.zticket),
            helper: getPaymentShare(props.paymentSummary.zticket),
        });
    }

    cards.push(
        {
            label: 'Dinheiro',
            value: formatMoney(props.paymentSummary.cash),
            helper: getPaymentShare(props.paymentSummary.cash),
        },
        {
            label: 'Outros pagamentos',
            value: formatMoney(props.paymentSummary.other),
            helper: getPaymentShare(props.paymentSummary.other),
        },
    );

    return cards;
});

const movementCards = computed<MetricCard[]>(() => {
    if (!showZtCard.value) {
        return [
            {
                label: 'Total faturado',
                value: formatMoney(props.paymentSummary.total_without_zt),
                helper: 'Vendas de consumo',
            },
            {
                label: 'Outros movimentos',
                value: formatMoney(props.paymentSummary.other_movements),
                helper: 'Fora das vendas',
            },
        ];
    }

    return [
        {
            label: 'Total sem ZT',
            value: formatMoney(props.paymentSummary.total_without_zt),
            helper: 'Vendas de consumo',
        },
        {
            label: 'Carregamentos ZT',
            value: formatNumber(props.paymentSummary.top_up_documents_count),
            helper: 'Cartões carregados',
        },
        {
            label: 'Valor ZT',
            value: formatMoney(props.paymentSummary.top_up_loaded),
            helper: 'Total carregado',
        },
        {
            label: 'Total com ZT',
            value: formatMoney(props.paymentSummary.total_with_zt),
            helper: 'Vendas + carregamentos',
        },
        {
            label: 'Outros movimentos',
            value: formatMoney(props.paymentSummary.other_movements),
            helper: 'Fora de vendas e ZT',
        },
    ];
});

const topUpCards = computed<MetricCard[]>(() => [
    {
        label: 'Valor carregado',
        value: formatMoney(props.paymentSummary.top_up_loaded),
        helper: `${formatNumber(props.paymentSummary.top_up_documents_count)} carregamentos ZT`,
    },
    {
        label: 'Valor gasto',
        value: formatMoney(props.paymentSummary.top_up_spent),
        helper: 'Consumo ZT - Card',
    },
    {
        label: 'Remanescente',
        value: formatMoney(props.paymentSummary.top_up_remaining),
        helper: 'Saldo não utilizado',
    },
]);

const movementGridClass = computed(() => showZtCard.value
    ? 'report-dashboard-grid-5'
    : 'report-dashboard-grid-2');
const paymentGridClass = computed(() => showZtPaymentDetails.value
    ? 'report-dashboard-grid-4'
    : 'report-dashboard-grid-3');
const reconciliationGridClass = computed(() => showZtPaymentDetails.value
    ? 'report-dashboard-grid-4'
    : 'report-dashboard-grid-3');
const visibleComparisonPayments = computed(() => (props.comparison.payments ?? [])
    .filter((payment) => showZtCard.value || payment.key !== 'zticket'));

const summaryCards = computed<MetricCard[]>(() => {
    const averagePerDevice = props.summary.machines_count > 0
        ? props.summary.total_sales / props.summary.machines_count
        : 0;

    return [
        {
            label: 'Total devices',
            value: formatNumber(props.summary.machines_count),
            helper: 'Máquinas sincronizadas',
        },
        {
            label: 'Zonas',
            value: formatNumber(props.summary.bar_groups_count),
            helper: 'Grupos operacionais',
        },
        {
            label: 'Ticket médio',
            value: formatMoney(props.summary.average_ticket),
            helper: 'Por documento',
        },
        {
            label: 'Produtos',
            value: formatNumber(props.summary.products_count),
            helper: 'Referências vendidas',
        },
        {
            label: 'Média por device',
            value: formatMoney(averagePerDevice),
            helper: 'Faturação por máquina',
        },
    ];
});

const productTabs = computed(() => [
    { key: 'total', label: 'Total' },
    ...props.productBreakdowns.days.map((day) => ({ key: day.date, label: day.label })),
]);
const activeProducts = computed(() => {
    if (selectedProductView.value === 'total') {
        return props.productBreakdowns.total;
    }

    return props.productBreakdowns.days.find(
        (day) => day.date === selectedProductView.value,
    )?.items ?? [];
});
const visibleProducts = computed(() => {
    const search = productSearch.value.trim().toLocaleLowerCase('pt-PT');
    const products = search === ''
        ? [...activeProducts.value]
        : activeProducts.value.filter((product) =>
            `${product.label} ${product.code ?? ''}`.toLocaleLowerCase('pt-PT').includes(search),
        );

    return products.sort((left, right) => productSort.value === 'sales'
        ? right.sales_total - left.sales_total
        : right.quantity_total - left.quantity_total);
});
const productColumns = computed(() => {
    const midpoint = Math.ceil(visibleProducts.value.length / 2);

    return [
        visibleProducts.value.slice(0, midpoint),
        visibleProducts.value.slice(midpoint),
    ].filter((column) => column.length > 0);
});
const maxProductQuantity = computed(() =>
    visibleProducts.value.reduce((max, item) => Math.max(max, item.quantity_total), 0),
);
const maxProductSales = computed(() =>
    visibleProducts.value.reduce((max, item) => Math.max(max, item.sales_total), 0),
);
const maxZoneSales = computed(() =>
    props.barGroups.reduce((max, group) => Math.max(max, group.sales_total), 0),
);
const zonePerformanceRows = computed<ZonePerformanceRow[]>(() =>
    props.barGroups.map((group) => ({
        label: group.label,
        totalSales: group.sales_total,
        devicesCount: group.stores_count,
        averageSales: group.stores_count > 0 ? group.sales_total / group.stores_count : 0,
        performanceWidth: getRatioWidth(group.sales_total, maxZoneSales.value),
    })),
);
const visibleZonePerformanceRows = computed(() => {
    const search = zoneSearch.value.trim().toLocaleLowerCase('pt-PT');

    return search === ''
        ? zonePerformanceRows.value
        : zonePerformanceRows.value.filter((zone) => zone.label.toLocaleLowerCase('pt-PT').includes(search));
});
const visibleZoneDevices = computed(() => {
    const search = zoneSearch.value.trim().toLocaleLowerCase('pt-PT');

    if (search === '') {
        return props.zoneDevices;
    }

    return props.zoneDevices
        .map((zone) => ({
            ...zone,
            items: zone.items.filter((item) =>
                `${item.label} ${item.code ?? ''}`.toLocaleLowerCase('pt-PT').includes(search)
                || zone.label.toLocaleLowerCase('pt-PT').includes(search),
            ),
        }))
        .filter((zone) => zone.items.length > 0);
});
const visibleReconciliationItems = computed(() => {
    const search = reconciliationSearch.value.trim().toLocaleLowerCase('pt-PT');

    return search === ''
        ? props.reconciliation.items
        : props.reconciliation.items.filter((item) =>
            `${item.store_code ?? ''} ${item.store_name}`.toLocaleLowerCase('pt-PT').includes(search),
        );
});
const highlightItems = computed<HighlightItem[]>(() => {
    if (highlightScope.value === 'products') {
        return props.topProducts.map((item) => ({
            key: `product-${item.code ?? item.label}`,
            label: item.label,
            helper: `${formatNumber(item.quantity_total)} unidades`,
            value: item.sales_total,
        }));
    }

    if (highlightScope.value === 'devices') {
        return props.topStores.map((item) => ({
            key: `device-${item.code ?? item.label}`,
            label: getDeviceLabel(item),
            helper: `${formatNumber(item.rows_count)} linhas`,
            value: item.sales_total,
        }));
    }

    return props.barGroups.map((group) => ({
        key: `zone-${group.label}`,
        label: group.label,
        helper: `${formatNumber(group.stores_count)} devices`,
        value: group.sales_total,
    }));
});
const visibleHighlightItems = computed(() => {
    const search = highlightSearch.value.trim().toLocaleLowerCase('pt-PT');

    return (search === ''
        ? highlightItems.value
        : highlightItems.value.filter((item) => item.label.toLocaleLowerCase('pt-PT').includes(search)))
        .sort((left, right) => right.value - left.value);
});
const highlightTotal = computed(() => visibleHighlightItems.value.reduce((total, item) => total + item.value, 0));
const highlightMaxValue = computed(() => visibleHighlightItems.value.reduce((max, item) => Math.max(max, item.value), 0));
const chartDailyPoints = computed<ChartDailyPoint[]>(() => {
    if (props.dailySales.length === 0) {
        return [];
    }

    const max = Math.max(...props.dailySales.map((day) => day.sales_total), 1);
    const denominator = Math.max(props.dailySales.length - 1, 1);

    return props.dailySales
        .map((day, index) => {
            const x = props.dailySales.length === 1 ? 50 : (index / denominator) * 100;
            const y = 88 - ((day.sales_total / max) * 70);

            return { ...day, x, y };
        });
});
const summaryChartPoints = computed(() => {
    if (chartDailyPoints.value.length === 0) {
        return '';
    }

    if (chartDailyPoints.value.length === 1) {
        return `0,${chartDailyPoints.value[0].y.toFixed(2)} 100,${chartDailyPoints.value[0].y.toFixed(2)}`;
    }

    return chartDailyPoints.value
        .map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`)
        .join(' ');
});
const hourlyDateOptions = computed(() => Array.from(
    new Map(props.hourlySales.map((sale) => [sale.date, sale.label])),
    ([date, label]) => ({ date, label }),
));
const selectedHourlySales = computed(() => selectedHourlyDate.value === 'all'
    ? props.hourlySales
    : props.hourlySales.filter((sale) => sale.date === selectedHourlyDate.value));
const hourlySeries = computed<HourlySale[]>(() => {
    const totalsByHour = new Map<number, { salesTotal: number; ticketsCount: number }>();

    selectedHourlySales.value.forEach((sale) => {
        const current = totalsByHour.get(sale.hour) ?? { salesTotal: 0, ticketsCount: 0 };
        current.salesTotal += sale.sales_total;
        current.ticketsCount += sale.tickets_count;
        totalsByHour.set(sale.hour, current);
    });

    return Array.from({ length: 24 }, (_, hour) => {
        const totals = totalsByHour.get(hour) ?? { salesTotal: 0, ticketsCount: 0 };

        return {
            date: selectedHourlyDate.value,
            label: selectedHourlyDate.value === 'all'
                ? 'Todos os dias'
                : (hourlyDateOptions.value.find((option) => option.date === selectedHourlyDate.value)?.label ?? ''),
            hour,
            hour_label: `${String(hour).padStart(2, '0')}:00`,
            sales_total: totals.salesTotal,
            tickets_count: totals.ticketsCount,
        };
    });
});
const hourlyChartPoints = computed<ChartHourlyPoint[]>(() => {
    const max = Math.max(...hourlySeries.value.map((sale) => sale.sales_total), 1);

    return hourlySeries.value.map((sale, index) => ({
        ...sale,
        x: (index / 23) * 100,
        y: 88 - ((Math.max(0, sale.sales_total) / max) * 70),
    }));
});
const hourlyChartLinePoints = computed(() => hourlyChartPoints.value
    .map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`)
    .join(' '));
const hourlyChartActivePoints = computed(() => hourlyChartPoints.value.filter((point) => point.sales_total > 0));
const hourlyChartAreaPoints = computed(() => hourlyChartLinePoints.value
    ? `0,100 ${hourlyChartLinePoints.value} 100,100`
    : '');
const hourlyAxisLabels = computed(() => hourlySeries.value.filter((sale) => sale.hour % 3 === 0));
const hourlyPeakItems = computed(() => [...selectedHourlySales.value]
    .filter((sale) => sale.sales_total > 0)
    .sort((left, right) => right.sales_total - left.sales_total)
    .slice(0, 3));
const hourlySelectedTotal = computed(() => selectedHourlySales.value.reduce(
    (total, sale) => total + sale.sales_total,
    0,
));
const hourlyChartAriaLabel = computed(() => hourlyPeakItems.value
    .map((sale) => `${sale.label}, ${sale.hour_label}: ${formatMoney(sale.sales_total)}`)
    .join(', '));
const chartPaymentItems = computed<ChartPaymentItem[]>(() => {
    const palette = ['var(--accent)', '#25b9a7', '#f0b44d', '#ef6b74'];
    const payments = [
        { key: 'multibanco', label: 'Multibanco', value: props.paymentSummary.multibanco },
        ...(showZtPaymentDetails.value
            ? [{ key: 'zticket', label: 'ZT - Card', value: props.paymentSummary.zticket }]
            : []),
        { key: 'cash', label: 'Dinheiro', value: props.paymentSummary.cash },
        { key: 'other', label: 'Outros', value: props.paymentSummary.other },
    ].filter((payment) => payment.value > 0);
    const total = payments.reduce((sum, payment) => sum + payment.value, 0);

    return payments.map((payment, index) => ({
        ...payment,
        percentage: total > 0 ? (payment.value / total) * 100 : 0,
        color: palette[index % palette.length],
    }));
});
const chartPaymentTotal = computed(() => chartPaymentItems.value.reduce((sum, payment) => sum + payment.value, 0));
const chartPaymentDonutStyle = computed(() => {
    if (chartPaymentTotal.value <= 0) {
        return { background: 'color-mix(in srgb, var(--line-color) 70%, transparent)' };
    }

    let cursor = 0;
    const segments = chartPaymentItems.value.map((payment) => {
        const start = cursor;
        cursor += payment.percentage;

        return `${payment.color} ${start.toFixed(2)}% ${cursor.toFixed(2)}%`;
    });

    return { background: `conic-gradient(${segments.join(', ')})` };
});
const chartZoneItems = computed<ChartZoneItem[]>(() => {
    const groups = [...props.barGroups]
        .sort((left, right) => right.sales_total - left.sales_total)
        .slice(0, 8);
    const max = groups.reduce((highest, group) => Math.max(highest, group.sales_total), 0);

    return groups.map((group) => ({
        label: group.label,
        value: group.sales_total,
        devicesCount: group.stores_count,
        height: `${max > 0 ? Math.max(6, (group.sales_total / max) * 100) : 0}%`,
    }));
});
const chartLineAreaPoints = computed(() => summaryChartPoints.value
    ? `0,100 ${summaryChartPoints.value} 100,100`
    : '');
const chartPaymentAriaLabel = computed(() => chartPaymentItems.value
    .map((payment) => `${payment.label}: ${formatMoney(payment.value)}`)
    .join(', '));
const chartZoneAriaLabel = computed(() => chartZoneItems.value
    .map((zone) => `${zone.label}: ${formatMoney(zone.value)}`)
    .join(', '));
const chartDailyAriaLabel = computed(() => props.dailySales
    .map((day) => `${day.label}: ${formatMoney(day.sales_total)}`)
    .join(', '));
const chartFinancialTotal = computed(() => Math.max(props.paymentSummary.total_with_zt, 0));
const chartFinancialSalesPercentage = computed(() => chartFinancialTotal.value > 0
    ? Math.min(100, Math.max(0, (props.paymentSummary.total_without_zt / chartFinancialTotal.value) * 100))
    : 0);
const chartFinancialZtPercentage = computed(() => chartFinancialTotal.value > 0
    ? Math.min(100 - chartFinancialSalesPercentage.value, Math.max(0, (props.paymentSummary.top_up_loaded / chartFinancialTotal.value) * 100))
    : 0);
const chartFinancialDonutStyle = computed(() => ({
    background: chartFinancialTotal.value > 0
        ? `conic-gradient(var(--accent) 0% ${chartFinancialSalesPercentage.value.toFixed(2)}%, #f0b44d ${chartFinancialSalesPercentage.value.toFixed(2)}% ${(chartFinancialSalesPercentage.value + chartFinancialZtPercentage.value).toFixed(2)}%, color-mix(in srgb, var(--line-color) 70%, transparent) 0)`
        : 'color-mix(in srgb, var(--line-color) 70%, transparent)',
}));
const chartTopUpTotal = computed(() => Math.max(props.paymentSummary.top_up_loaded, 0));
const chartTopUpSpentPercentage = computed(() => chartTopUpTotal.value > 0
    ? Math.min(100, Math.max(0, (props.paymentSummary.top_up_spent / chartTopUpTotal.value) * 100))
    : 0);
const chartTopUpRemainingPercentage = computed(() => chartTopUpTotal.value > 0
    ? Math.min(100 - chartTopUpSpentPercentage.value, Math.max(0, (props.paymentSummary.top_up_remaining / chartTopUpTotal.value) * 100))
    : 0);
const chartOperationalItems = computed<ChartOperationalItem[]>(() => {
    const items = [
        { label: 'Total devices', value: props.summary.machines_count, helper: 'Máquinas sincronizadas' },
        { label: 'Zonas', value: props.summary.bar_groups_count, helper: 'Grupos operacionais' },
        { label: 'Produtos', value: props.summary.products_count, helper: 'Referências vendidas' },
    ];
    const max = items.reduce((highest, item) => Math.max(highest, item.value), 0);

    return items.map((item) => ({
        ...item,
        height: `${max > 0 ? Math.max(8, (item.value / max) * 100) : 0}%`,
    }));
});
const chartAveragePerDevice = computed(() => props.summary.machines_count > 0
    ? props.summary.total_sales / props.summary.machines_count
    : 0);
const detailModalTitle = computed(() => {
    if (detailModal.value === 'ticket') {
        return `${props.event.title} — Ticket médio por dia`;
    }

    if (detailModal.value === 'topup') {
        return `${props.event.title} — ZT - Card por dia`;
    }

    return `${props.event.title} — Detalhe por dia`;
});
const dailyGrandTotal = computed(() => props.dailyBreakdowns.reduce(
    (total, day) => total + (detailModal.value === 'topup' ? day.top_up_loaded : day.sales_total),
    0,
));
const comparisonMaxTotal = computed(() => Math.max(
    props.comparison.current?.total_sales ?? 0,
    props.comparison.previous?.total_sales ?? 0,
));

watch(
    () => props.filters,
    (filters) => {
        localFilters.value = { ...filters };
    },
    { deep: true },
);

watch(
    () => props.hourlySales,
    () => {
        if (selectedHourlyDate.value !== 'all'
            && !hourlyDateOptions.value.some((option) => option.date === selectedHourlyDate.value)) {
            selectedHourlyDate.value = 'all';
        }
    },
    { deep: true },
);

const startDashboardPolling = () => {
    if (dashboardPollerId.value !== null || typeof window === 'undefined') {
        return;
    }

    dashboardPollerId.value = window.setInterval(() => {
        if (!hasProcessingSync.value) {
            return;
        }

        router.visit(getCurrentDashboardUrl(), {
            method: 'get',
            only: ['event', 'summary', 'autoSync'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 2500);
};

const refreshAutoSyncStatus = () => {
    if (isRefreshingAutoSync.value) {
        return;
    }

    isRefreshingAutoSync.value = true;
    lastAutoSyncRefreshAt.value = Date.now();

    router.visit(getCurrentDashboardUrl(), {
        method: 'get',
        only: ['event', 'summary', 'autoSync'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            isRefreshingAutoSync.value = false;
        },
    });
};

const updateAutoSyncClock = () => {
    currentTimestamp.value = Date.now();

    if (
        props.autoSync.enabled
        && !hasProcessingSync.value
        && nextSyncSeconds.value === 0
        && currentTimestamp.value - lastAutoSyncRefreshAt.value >= 5000
    ) {
        refreshAutoSyncStatus();
    }
};

const refreshAutoSyncOnFocus = () => {
    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
        return;
    }

    updateAutoSyncClock();

    if (props.autoSync.enabled) {
        refreshAutoSyncStatus();
    }
};

const closeEventSwitcher = () => {
    eventSwitcherOpen.value = false;
};

const openDetailModal = (modal: Exclude<DetailModal, null>) => {
    detailModal.value = modal;
};

const closeDetailModal = () => {
    detailModal.value = null;
};

const handleEscapeKey = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        closeDetailModal();
        filtersOpen.value = false;
        eventSwitcherOpen.value = false;
    }
};

const stopDashboardPolling = () => {
    if (dashboardPollerId.value === null || typeof window === 'undefined') {
        return;
    }

    window.clearInterval(dashboardPollerId.value);
    dashboardPollerId.value = null;
};

const getCurrentDashboardUrl = () => {
    if (typeof window === 'undefined') {
        return route('admin.events.dashboard', props.event.id);
    }

    return `${window.location.pathname}${window.location.search}`;
};

const getDashboardPath = () => {
    if (typeof window === 'undefined') {
        return route('admin.events.dashboard', props.event.id);
    }

    return window.location.pathname;
};

const submitReportSync = () => {
    if (!props.previewMode || isSyncingReport.value || hasProcessingSync.value) {
        return;
    }

    isSyncingReport.value = true;
    syncIntegrationError.value = '';

    router.post(
        route('admin.events.reports.store', props.event.id),
        { redirect_to: getCurrentDashboardUrl() },
        {
            preserveScroll: true,
            onSuccess: () => {
                void showSuccessToast('Sincronização iniciada. O dashboard vai atualizar automaticamente.');
            },
            onError: (errors) => {
                syncIntegrationError.value = (errors.integration as string | undefined) ?? '';

                if (!syncIntegrationError.value) {
                    void showErrorToast('Não foi possível iniciar a sincronização.');
                }
            },
            onFinish: () => {
                isSyncingReport.value = false;
            },
        },
    );
};

const applyFilters = () => {
    const query = Object.fromEntries(
        Object.entries(localFilters.value).filter(([, value]) => value !== ''),
    );

    router.get(getDashboardPath(), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onSuccess: () => {
            filtersOpen.value = false;
        },
    });
};

const applyBarGroupFilter = (barGroup: string) => {
    localFilters.value.bar_group = localFilters.value.bar_group === barGroup ? '' : barGroup;
    applyFilters();
};

const clearFilters = () => {
    localFilters.value = {
        bar_group: '',
        store: '',
        product: '',
        date_from: '',
        date_to: '',
        total_min: '',
        total_max: '',
    };
    router.get(getDashboardPath(), {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onSuccess: () => {
            filtersOpen.value = false;
        },
    });
};

watch(
    hasProcessingSync,
    (processing, wasProcessing) => {
        if (processing) {
            startDashboardPolling();

            return;
        }

        stopDashboardPolling();

        if (wasProcessing) {
            router.visit(getCurrentDashboardUrl(), {
                method: 'get',
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }
    },
    { immediate: true },
);

onMounted(() => {
    updateAutoSyncClock();

    if (typeof window !== 'undefined') {
        autoSyncClockId.value = window.setInterval(updateAutoSyncClock, 1000);
        window.addEventListener('focus', refreshAutoSyncOnFocus);
        window.addEventListener('keydown', handleEscapeKey);
        document.addEventListener('visibilitychange', refreshAutoSyncOnFocus);
    }
});

onBeforeUnmount(() => {
    stopDashboardPolling();

    if (autoSyncClockId.value !== null && typeof window !== 'undefined') {
        window.clearInterval(autoSyncClockId.value);
        window.removeEventListener('focus', refreshAutoSyncOnFocus);
        window.removeEventListener('keydown', handleEscapeKey);
        document.removeEventListener('visibilitychange', refreshAutoSyncOnFocus);
    }
});

function formatDateTime(value: string | null) {
    return value
        ? new Intl.DateTimeFormat('pt-PT', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : 'Sem data';
}

function formatDate(value: string | null) {
    return value
        ? new Intl.DateTimeFormat('pt-PT', { dateStyle: 'long' }).format(new Date(value))
        : 'Sem data';
}

function formatMoney(value: number) {
    return new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR' }).format(value);
}

function formatNumber(value: number) {
    return new Intl.NumberFormat('pt-PT', {
        maximumFractionDigits: value % 1 === 0 ? 0 : 2,
    }).format(value);
}

function formatMetric(value: number, format: ComparisonMetric['format']) {
    return format === 'number' ? formatNumber(value) : formatMoney(value);
}

function formatVariation(value: number | null | undefined) {
    if (value === null || value === undefined) {
        return 'Sem base';
    }

    return `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;
}

function getPaymentShare(value: number) {
    const total = props.paymentSummary.multibanco
        + (showZtCard.value ? props.paymentSummary.zticket : 0)
        + props.paymentSummary.cash
        + props.paymentSummary.other;

    if (total <= 0) {
        return 'Sem pagamentos';
    }

    return `${((value / total) * 100).toFixed(1).replace('.', ',')}% dos pagamentos`;
}

function getRatioWidth(value: number, maxValue: number) {
    if (value <= 0 || maxValue <= 0) {
        return '0%';
    }

    return `${Math.max(5, Math.round((value / maxValue) * 100))}%`;
}

function getProductRatioWidth(product: BreakdownItem) {
    return getRatioWidth(
        productSort.value === 'sales' ? product.sales_total : product.quantity_total,
        productSort.value === 'sales' ? maxProductSales.value : maxProductQuantity.value,
    );
}

function getHighlightShare(value: number) {
    if (highlightTotal.value <= 0) {
        return '0,0%';
    }

    return `${((value / highlightTotal.value) * 100).toFixed(1).replace('.', ',')}%`;
}

function getDeviceLabel(item: BreakdownItem) {
    return item.code ? `${item.code} - ${item.label}` : item.label;
}

function getDifferenceClass(value: number | null) {
    if (value === null || Math.abs(value) < 0.01) {
        return 'is-neutral';
    }

    return value > 0 ? 'is-positive' : 'is-negative';
}
</script>

<template>
    <Head :title="`Evento - ${props.event.title}`" />

    <AuthenticatedLayout>
        <template #sidebar-navigation>
            <section class="app-report-navigation" aria-label="Menu do relatório">
                <header class="app-report-navigation-header">
                    <span>Reporting</span>
                    <strong>{{ props.event.title }}</strong>
                </header>

                <div class="app-report-navigation-list">
                    <button
                        v-for="section in dashboardSections"
                        :key="section.key"
                        type="button"
                        class="app-report-navigation-item"
                        :class="{ 'is-active': activeSection === section.key }"
                        @click="activeSection = section.key"
                    >
                        <span class="app-report-navigation-number">{{ section.number }}</span>
                        <span>
                            <strong>{{ section.label }}</strong>
                            <small>{{ section.helper }}</small>
                        </span>
                    </button>
                </div>

                <div class="app-report-navigation-status">
                    <span :class="{ 'is-syncing': hasProcessingSync }" />
                    <div>
                        <strong>{{ autoSyncStatusLabel }}</strong>
                        <small v-if="props.autoSync.enabled">Próxima em {{ autoSyncCountdown }}</small>
                    </div>
                </div>

                <a
                    :href="whatsappSupportUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="app-report-help"
                >
                    <span class="app-report-help-icon">?</span>
                    <span>
                        <strong>Precisas de ajuda?</strong>
                        <small>Fala connosco sobre o relatório.</small>
                    </span>
                    <em>WhatsApp</em>
                </a>
            </section>
        </template>

        <template #header>
            <div class="report-dashboard-toolbar">
                <p class="report-dashboard-toolbar-tagline">Relatórios inteligentes para decisões com impacto.</p>

                <div class="report-dashboard-toolbar-actions">
                    <button
                        v-if="props.previewMode"
                        type="button"
                        class="dash-action-button dash-action-button-inline justify-center"
                        :class="{ 'cursor-not-allowed opacity-70': isSyncingReport || hasProcessingSync }"
                        :disabled="isSyncingReport || hasProcessingSync"
                        @click="submitReportSync"
                    >
                        {{
                            hasProcessingSync
                                ? 'Sincronização em andamento'
                                : isSyncingReport
                                  ? 'A sincronizar...'
                                  : 'Sincronizar agora'
                        }}
                    </button>

                    <div class="report-dashboard-event-switcher" :class="{ 'is-open': eventSwitcherOpen }">
                        <div class="report-dashboard-event-pill">
                            <span class="report-dashboard-event-kicker">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M7 3v4M17 3v4M4 9h16M6 5h12a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                                </svg>
                                Evento:
                            </span>
                            <strong>{{ eventSwitcherTitle }}</strong>
                            <button
                                type="button"
                                class="report-dashboard-event-toggle"
                                :disabled="!hasEventOptions"
                                :aria-expanded="eventSwitcherOpen"
                                @click="eventSwitcherOpen = !eventSwitcherOpen"
                            >
                                Trocar
                                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                    <path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>

                        <div v-if="eventSwitcherOpen && hasEventOptions" class="report-dashboard-event-menu">
                            <Link
                                v-for="eventOption in props.eventOptions"
                                :key="eventOption.id"
                                :href="eventOption.url"
                                class="report-dashboard-event-option"
                                :class="{ 'is-current': eventOption.is_current }"
                                preserve-scroll
                                @click="closeEventSwitcher"
                            >
                                <span>{{ eventOption.title }}</span>
                                <small>{{ formatDate(eventOption.event_date) }}</small>
                                <svg v-if="eventOption.is_current" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                    <path d="m4 10 4 4 8-8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </Link>
                        </div>
                    </div>

                    <div v-if="props.autoSync.enabled || hasProcessingSync" class="report-dashboard-sync-countdown report-dashboard-header-sync">
                        <span>{{ hasProcessingSync ? 'Sincronização em curso' : 'Próxima sincronização' }}</span>
                        <strong>{{ autoSyncCountdown }}</strong>
                    </div>

                    <button
                        type="button"
                        class="report-dashboard-header-action"
                        :class="{ 'is-active': filtersOpen || activeFilterCount > 0 }"
                        @click="filtersOpen = !filtersOpen"
                    >
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M4 5h16l-6 7v5l-4 2v-7L4 5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                        </svg>
                        Filtrar bares
                        <span v-if="activeFilterCount > 0">{{ activeFilterCount }}</span>
                    </button>

                    <a
                        :href="whatsappSupportUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="report-dashboard-header-action is-primary"
                    >
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="m21 3-8.2 18-2.4-7.4L3 11.2 21 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                        </svg>
                        Ajuda
                    </a>
                </div>
            </div>
        </template>

        <div class="dash-page">
            <section v-if="!hasImportedData" class="dash-card report-dashboard-empty">
                Nenhum relatório sincronizado para este evento.
            </section>

            <div v-else class="report-dashboard-shell">
                <aside class="report-dashboard-navigation lg:hidden" aria-label="Menu do relatório">
                    <div class="report-dashboard-navigation-brand">
                        <span>Contacto Digital</span>
                        <strong>Reporting</strong>
                    </div>

                    <nav class="report-dashboard-navigation-list">
                        <button
                            v-for="section in dashboardSections"
                            :key="section.key"
                            type="button"
                            class="report-dashboard-navigation-item"
                            :class="{ 'is-active': activeSection === section.key }"
                            @click="activeSection = section.key"
                        >
                            <span class="report-dashboard-navigation-number">{{ section.number }}</span>
                            <span>
                                <strong>{{ section.label }}</strong>
                                <small>{{ section.helper }}</small>
                            </span>
                        </button>
                    </nav>

                    <div class="report-dashboard-navigation-status">
                        <span :class="{ 'is-syncing': hasProcessingSync }" />
                        <div>
                            <strong>{{ autoSyncStatusLabel }}</strong>
                            <small v-if="props.autoSync.enabled">Próxima em {{ autoSyncCountdown }}</small>
                        </div>
                    </div>
                </aside>

                <main class="report-dashboard-content">
                    <form
                        v-if="filtersOpen"
                        class="report-dashboard-filter-panel"
                        @submit.prevent="applyFilters"
                    >
                        <div class="report-dashboard-filter-panel-header">
                            <div>
                                <strong>Refinar relatório</strong>
                                <span>As opções são geradas pelos dados sincronizados.</span>
                            </div>
                            <button type="button" aria-label="Fechar filtros" @click="filtersOpen = false">×</button>
                        </div>

                        <div class="report-dashboard-filter-bar-options">
                            <button
                                v-for="option in props.filterOptions.barGroups"
                                :key="option.value"
                                type="button"
                                :class="{ 'is-active': localFilters.bar_group === option.value }"
                                @click="applyBarGroupFilter(option.value)"
                            >
                                <span>{{ option.label }}</span>
                                <small>{{ formatNumber(option.rows_count) }} linhas</small>
                            </button>
                        </div>

                        <div class="report-dashboard-filter-grid">
                            <label>
                                <span>Zona</span>
                                <select v-model="localFilters.bar_group" class="dash-input">
                                    <option value="">Todas</option>
                                    <option v-for="option in props.filterOptions.barGroups" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </label>
                            <label>
                                <span>Device</span>
                                <select v-model="localFilters.store" class="dash-input">
                                    <option value="">Todos</option>
                                    <option v-for="option in props.filterOptions.stores" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </label>
                            <label>
                                <span>Produto</span>
                                <select v-model="localFilters.product" class="dash-input">
                                    <option value="">Todos</option>
                                    <option v-for="option in props.filterOptions.products" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </label>
                            <label>
                                <span>Data inicial</span>
                                <input v-model="localFilters.date_from" type="date" class="dash-input" />
                            </label>
                            <label>
                                <span>Data final</span>
                                <input v-model="localFilters.date_to" type="date" class="dash-input" />
                            </label>
                            <label>
                                <span>Total mínimo</span>
                                <input v-model="localFilters.total_min" inputmode="decimal" class="dash-input" placeholder="0,00" />
                            </label>
                            <label>
                                <span>Total máximo</span>
                                <input v-model="localFilters.total_max" inputmode="decimal" class="dash-input" placeholder="0,00" />
                            </label>
                        </div>

                        <div class="report-dashboard-filter-actions">
                            <button type="button" class="dash-link-button" @click="clearFilters">Limpar</button>
                            <button type="submit" class="dash-action-button dash-action-button-inline">Aplicar filtros</button>
                        </div>
                    </form>

                    <p v-if="props.previewMode && hasProcessingSync" class="report-dashboard-inline-note">
                        Sincronização em curso. O dashboard atualiza automaticamente.
                    </p>
                    <p v-else-if="syncIntegrationError" class="dash-modal-error">
                        {{ syncIntegrationError }}
                    </p>

                    <div
                        v-if="activeSection === 'summary' || activeSection === 'charts'"
                        class="report-dashboard-summary-view-switcher"
                        aria-label="Modo de visualização do resumo"
                    >
                        <div class="report-dashboard-view-toggle report-dashboard-summary-view-toggle">
                            <button
                                type="button"
                                :class="{ 'is-active': activeSection === 'summary' }"
                                :aria-pressed="activeSection === 'summary'"
                                @click="activeSection = 'summary'"
                            >
                                Lista
                            </button>
                            <button
                                type="button"
                                :class="{ 'is-active': activeSection === 'charts' }"
                                :aria-pressed="activeSection === 'charts'"
                                @click="activeSection = 'charts'"
                            >
                                Gráfico
                            </button>
                        </div>
                    </div>

                    <div v-if="activeSection === 'summary'" class="report-dashboard-view">
                        <button type="button" class="report-dashboard-overview-hero" @click="openDetailModal('payments')">
                            <div class="report-dashboard-overview-copy">
                                <span>{{ showZtCard ? 'Total sem ZT' : 'Total faturado' }}</span>
                                <strong>{{ formatMoney(props.paymentSummary.total_without_zt) }}</strong>
                                <div class="report-dashboard-day-list">
                                    <span v-for="day in props.dailySales" :key="day.date">
                                        {{ day.label }}
                                        <strong>{{ formatMoney(day.sales_total) }}</strong>
                                    </span>
                                </div>
                            </div>

                            <div class="report-dashboard-overview-chart" aria-label="Evolução da faturação por dia">
                                <svg viewBox="0 0 100 100" preserveAspectRatio="none" role="img">
                                    <defs>
                                        <linearGradient id="dashboard-chart-area" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stop-color="currentColor" stop-opacity="0.34" />
                                            <stop offset="100%" stop-color="currentColor" stop-opacity="0" />
                                        </linearGradient>
                                    </defs>
                                    <polyline v-if="summaryChartPoints" :points="summaryChartPoints" class="report-dashboard-chart-line" />
                                    <polygon v-if="summaryChartPoints" :points="`0,100 ${summaryChartPoints} 100,100`" class="report-dashboard-chart-area" />
                                </svg>
                            </div>

                            <div class="report-dashboard-overview-meta">
                                <span>Última sincronização</span>
                                <strong>{{ formatDateTime(props.summary.last_synced_at) }}</strong>
                                <small>{{ formatNumber(props.summary.filtered_rows) }} linhas analisadas</small>
                            </div>
                        </button>

                        <section>
                            <div class="report-dashboard-section-heading">
                                <span>Leitura financeira</span>
                                <h3>{{ showZtCard ? 'Vendas e carregamentos ZT' : 'Vendas do evento' }}</h3>
                            </div>
                            <div class="report-dashboard-grid" :class="movementGridClass">
                                <button
                                    v-for="card in movementCards"
                                    :key="card.label"
                                    type="button"
                                    class="report-dashboard-movement-card"
                                    :class="{ 'is-total': card.label === 'Total com ZT' }"
                                    @click="openDetailModal(card.label === 'Carregamentos ZT' || card.label === 'Valor ZT' ? 'topup' : 'payments')"
                                >
                                    <span>{{ card.label }}</span>
                                    <strong>{{ card.value }}</strong>
                                    <small>{{ card.helper }}</small>
                                </button>
                            </div>
                        </section>

                        <section>
                            <div class="report-dashboard-section-heading">
                                <span>Formas de pagamento</span>
                                <h3>Pagamentos das vendas</h3>
                            </div>
                            <div class="report-dashboard-grid" :class="paymentGridClass">
                                <button
                                    v-for="(card, index) in paymentCards"
                                    :key="card.label"
                                    type="button"
                                    class="report-dashboard-metric-card"
                                    :class="{ 'is-featured': index === 0 }"
                                    @click="openDetailModal('payments')"
                                >
                                    <span>{{ card.label }}</span>
                                    <strong>{{ card.value }}</strong>
                                    <small>{{ card.helper }}</small>
                                </button>
                            </div>
                        </section>

                        <section v-if="showZtCard" class="report-dashboard-topup">
                            <div class="report-dashboard-section-heading">
                                <span>Fluxo de cartões</span>
                                <h3>Top-Up ZT - Card</h3>
                            </div>
                            <div class="report-dashboard-topup-flow">
                                <button v-for="(card, index) in topUpCards" :key="card.label" type="button" @click="openDetailModal('topup')">
                                    <span>{{ String(index + 1).padStart(2, '0') }}</span>
                                    <div>
                                        <small>{{ card.label }}</small>
                                        <strong>{{ card.value }}</strong>
                                        <p>{{ card.helper }}</p>
                                    </div>
                                </button>
                            </div>
                        </section>

                        <section>
                            <div class="report-dashboard-section-heading">
                                <span>Operação</span>
                                <h3>Indicadores operacionais</h3>
                            </div>
                            <div class="report-dashboard-grid report-dashboard-grid-5">
                                <button
                                    v-for="card in summaryCards"
                                    :key="card.label"
                                    type="button"
                                    class="report-dashboard-summary-card"
                                    :class="{ 'is-clickable': card.label === 'Ticket médio' }"
                                    :disabled="card.label !== 'Ticket médio'"
                                    @click="openDetailModal('ticket')"
                                >
                                    <span>{{ card.label }}</span>
                                    <strong>{{ card.value }}</strong>
                                    <small>{{ card.helper }}</small>
                                </button>
                            </div>
                        </section>
                    </div>

                    <div v-else-if="activeSection === 'charts'" class="report-dashboard-view report-dashboard-analytics-view">
                        <div class="report-dashboard-analytics-grid">
                            <section v-if="showZtCard" class="dash-card report-dashboard-analytics-card report-dashboard-analytics-financial-card">
                                <header>
                                    <div>
                                        <span>Leitura financeira</span>
                                        <h4>Vendas e carregamentos ZT</h4>
                                    </div>
                                </header>

                                <div class="report-dashboard-analytics-financial-donut-layout">
                                    <div
                                        class="report-dashboard-analytics-donut report-dashboard-analytics-financial-donut"
                                        :style="chartFinancialDonutStyle"
                                        role="img"
                                        :aria-label="`Vendas de consumo: ${formatMoney(props.paymentSummary.total_without_zt)}, carregamentos ZT: ${formatMoney(props.paymentSummary.top_up_loaded)}`"
                                    >
                                        <div>
                                            <small>Total com ZT</small>
                                            <strong>{{ formatMoney(props.paymentSummary.total_with_zt) }}</strong>
                                        </div>
                                    </div>
                                    <div class="report-dashboard-analytics-financial-legend">
                                        <article>
                                            <i class="is-sales" />
                                            <span><small>Total sem ZT</small><strong>{{ formatMoney(props.paymentSummary.total_without_zt) }}</strong></span>
                                            <em>{{ chartFinancialSalesPercentage.toFixed(1).replace('.', ',') }}%</em>
                                        </article>
                                        <article>
                                            <i class="is-zt" />
                                            <span><small>Valor ZT</small><strong>{{ formatMoney(props.paymentSummary.top_up_loaded) }}</strong></span>
                                            <em>{{ chartFinancialZtPercentage.toFixed(1).replace('.', ',') }}%</em>
                                        </article>
                                        <article class="is-outside-total">
                                            <i class="is-other" />
                                            <span><small>Outros movimentos</small><strong>{{ formatMoney(props.paymentSummary.other_movements) }}</strong></span>
                                            <em>Fora do total</em>
                                        </article>
                                        <div class="report-dashboard-analytics-financial-zt-flow">
                                            <header>
                                                <span>Distribuição do valor ZT</span>
                                                <small>{{ formatNumber(props.paymentSummary.top_up_documents_count) }} carregamentos</small>
                                            </header>
                                            <div>
                                                <i class="is-spent" :style="{ width: `${chartTopUpSpentPercentage}%` }" />
                                                <i class="is-remaining" :style="{ width: `${chartTopUpRemainingPercentage}%` }" />
                                            </div>
                                            <footer>
                                                <span><i class="is-spent" /> Gasto <strong>{{ formatMoney(props.paymentSummary.top_up_spent) }}</strong></span>
                                                <span><i class="is-remaining" /> Remanescente <strong>{{ formatMoney(props.paymentSummary.top_up_remaining) }}</strong></span>
                                            </footer>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="dash-card report-dashboard-analytics-card report-dashboard-analytics-line-card">
                                <header>
                                    <div>
                                        <span>Gráfico de linha</span>
                                        <h4>Evolução diária da faturação</h4>
                                    </div>
                                </header>

                                <div v-if="chartDailyPoints.length" class="report-dashboard-analytics-line-layout">
                                    <div>
                                        <div class="report-dashboard-analytics-line" role="img" :aria-label="chartDailyAriaLabel">
                                            <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                                                <defs>
                                                    <linearGradient id="analytics-chart-area" x1="0" y1="0" x2="0" y2="1">
                                                        <stop offset="0%" stop-color="currentColor" stop-opacity="0.4" />
                                                        <stop offset="100%" stop-color="currentColor" stop-opacity="0" />
                                                    </linearGradient>
                                                </defs>
                                                <polygon :points="chartLineAreaPoints" class="report-dashboard-analytics-line-area" />
                                                <polyline :points="summaryChartPoints" class="report-dashboard-analytics-line-path" />
                                                <circle
                                                    v-for="point in chartDailyPoints"
                                                    :key="`line-point-${point.date}`"
                                                    :cx="point.x"
                                                    :cy="point.y"
                                                    r="1.8"
                                                    class="report-dashboard-analytics-line-point"
                                                />
                                            </svg>
                                        </div>
                                        <div class="report-dashboard-analytics-line-labels">
                                            <span v-for="point in chartDailyPoints" :key="`line-label-${point.date}`">
                                                <small>{{ point.label }}</small>
                                                <strong>{{ formatMoney(point.sales_total) }}</strong>
                                            </span>
                                        </div>
                                    </div>
                                    <aside>
                                        <span v-for="point in chartDailyPoints" :key="`line-aside-${point.date}`">
                                            <small>{{ point.label }}</small>
                                            <strong>{{ formatMoney(point.sales_total) }}</strong>
                                            <em>{{ formatNumber(point.tickets_count) }} tickets</em>
                                        </span>
                                    </aside>
                                </div>
                                <p v-else class="report-dashboard-analytics-empty">Sem vendas diárias para apresentar.</p>
                            </section>

                            <section class="dash-card report-dashboard-analytics-card report-dashboard-analytics-hourly-card">
                                <header>
                                    <div>
                                        <span>Gráfico de linha</span>
                                        <h4>Picos de vendas por hora</h4>
                                    </div>
                                    <label v-if="hourlyDateOptions.length > 1" class="report-dashboard-analytics-period-select">
                                        <span>Período</span>
                                        <select v-model="selectedHourlyDate">
                                            <option value="all">Todos os dias</option>
                                            <option v-for="option in hourlyDateOptions" :key="option.date" :value="option.date">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </label>
                                </header>

                                <div v-if="props.hourlySales.length" class="report-dashboard-analytics-hourly-layout">
                                    <div>
                                        <div
                                            class="report-dashboard-analytics-line report-dashboard-analytics-hourly-line"
                                            role="img"
                                            :aria-label="hourlyChartAriaLabel"
                                        >
                                            <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                                                <defs>
                                                    <linearGradient id="analytics-hourly-area" x1="0" y1="0" x2="0" y2="1">
                                                        <stop offset="0%" stop-color="currentColor" stop-opacity="0.45" />
                                                        <stop offset="100%" stop-color="currentColor" stop-opacity="0" />
                                                    </linearGradient>
                                                </defs>
                                                <polygon :points="hourlyChartAreaPoints" class="report-dashboard-analytics-hourly-area" />
                                                <polyline :points="hourlyChartLinePoints" class="report-dashboard-analytics-line-path" />
                                                <circle
                                                    v-for="point in hourlyChartActivePoints"
                                                    :key="`hourly-point-${point.hour}`"
                                                    :cx="point.x"
                                                    :cy="point.y"
                                                    r="1.8"
                                                    class="report-dashboard-analytics-line-point"
                                                />
                                            </svg>
                                        </div>
                                        <div class="report-dashboard-analytics-hourly-axis" aria-hidden="true">
                                            <span v-for="hour in hourlyAxisLabels" :key="`hour-label-${hour.hour}`">
                                                {{ hour.hour_label }}
                                            </span>
                                        </div>
                                    </div>

                                    <aside>
                                        <div class="report-dashboard-analytics-hourly-total">
                                            <small>Total do período</small>
                                            <strong>{{ formatMoney(hourlySelectedTotal) }}</strong>
                                        </div>
                                        <span v-for="(peak, index) in hourlyPeakItems" :key="`${peak.date}-${peak.hour}`">
                                            <i>{{ String(index + 1).padStart(2, '0') }}</i>
                                            <small>{{ peak.label }} · {{ peak.hour_label }}</small>
                                            <strong>{{ formatMoney(peak.sales_total) }}</strong>
                                            <em>{{ formatNumber(peak.tickets_count) }} tickets</em>
                                        </span>
                                    </aside>
                                </div>
                                <p v-else class="report-dashboard-analytics-empty">Sem horários de venda para apresentar.</p>
                            </section>

                            <section class="dash-card report-dashboard-analytics-card report-dashboard-analytics-donut-card">
                                <header>
                                    <div>
                                        <span>Gráfico de pizza</span>
                                        <h4>Formas de pagamento</h4>
                                    </div>
                                </header>

                                <div v-if="chartPaymentItems.length" class="report-dashboard-analytics-donut-layout">
                                    <div
                                        class="report-dashboard-analytics-donut"
                                        :style="chartPaymentDonutStyle"
                                        role="img"
                                        :aria-label="chartPaymentAriaLabel"
                                    >
                                        <div>
                                            <small>Pagamentos visíveis</small>
                                            <strong>{{ formatMoney(chartPaymentTotal) }}</strong>
                                        </div>
                                    </div>
                                    <div class="report-dashboard-analytics-legend">
                                        <article v-for="payment in chartPaymentItems" :key="payment.key">
                                            <i :style="{ background: payment.color }" />
                                            <span>
                                                <small>{{ payment.label }}</small>
                                                <strong>{{ payment.percentage.toFixed(1).replace('.', ',') }}%</strong>
                                            </span>
                                            <em>{{ formatMoney(payment.value) }}</em>
                                            <div>
                                                <i :style="{ width: `${payment.percentage}%`, background: payment.color }" />
                                            </div>
                                        </article>
                                        <p>Percentuais calculados somente sobre as formas de pagamento apresentadas.</p>
                                    </div>
                                </div>
                                <p v-else class="report-dashboard-analytics-empty">Sem pagamentos para apresentar.</p>
                            </section>

                            <section class="dash-card report-dashboard-analytics-card report-dashboard-analytics-bars-card">
                                <header>
                                    <div>
                                        <span>Gráfico de barras</span>
                                        <h4>Faturação por zona</h4>
                                    </div>
                                    <small>Top {{ formatNumber(chartZoneItems.length) }} zonas</small>
                                </header>

                                <div
                                    v-if="chartZoneItems.length"
                                    class="report-dashboard-analytics-bars"
                                    role="img"
                                    :aria-label="chartZoneAriaLabel"
                                >
                                    <article v-for="(zone, index) in chartZoneItems" :key="zone.label">
                                        <strong>{{ formatMoney(zone.value) }}</strong>
                                        <div>
                                            <i :style="{ height: zone.height, animationDelay: `${index * 70}ms` }" />
                                        </div>
                                        <span>{{ zone.label }}</span>
                                        <small>{{ formatNumber(zone.devicesCount) }} {{ zone.devicesCount === 1 ? 'device' : 'devices' }}</small>
                                    </article>
                                </div>
                                <p v-else class="report-dashboard-analytics-empty">Sem zonas para apresentar.</p>
                            </section>

                            <section class="dash-card report-dashboard-analytics-card report-dashboard-analytics-operational-card">
                                <header>
                                    <div>
                                        <span>Operação</span>
                                        <h4>Indicadores operacionais</h4>
                                    </div>
                                </header>

                                <div class="report-dashboard-analytics-operational-layout">
                                    <div class="report-dashboard-analytics-operational-columns" role="img" aria-label="Devices, zonas e produtos">
                                        <article v-for="(item, index) in chartOperationalItems" :key="item.label">
                                            <strong>{{ formatNumber(item.value) }}</strong>
                                            <div>
                                                <i :style="{ height: item.height, animationDelay: `${index * 90}ms` }" />
                                            </div>
                                            <span>{{ item.label }}</span>
                                            <small>{{ item.helper }}</small>
                                        </article>
                                    </div>
                                    <div class="report-dashboard-analytics-operational-money">
                                        <article>
                                            <span>Ticket médio</span>
                                            <strong>{{ formatMoney(props.summary.average_ticket) }}</strong>
                                            <small>Por documento</small>
                                        </article>
                                        <article>
                                            <span>Média por device</span>
                                            <strong>{{ formatMoney(chartAveragePerDevice) }}</strong>
                                            <small>Faturação por máquina</small>
                                        </article>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>

                    <div v-else-if="activeSection === 'products'" class="report-dashboard-view">
                        <header class="report-dashboard-view-header">
                            <div>
                                <span>Mix de consumo</span>
                                <h3>Produtos mais vendidos</h3>
                                <p>Ranking por quantidade, com leitura total e por dia.</p>
                            </div>
                            <div class="report-dashboard-segmented">
                                <button
                                    v-for="tab in productTabs"
                                    :key="tab.key"
                                    type="button"
                                    :class="{ 'is-active': selectedProductView === tab.key }"
                                    @click="selectedProductView = tab.key"
                                >
                                    {{ tab.label }}
                                </button>
                            </div>
                        </header>

                        <section class="dash-card report-dashboard-products-panel">
                            <div class="report-dashboard-analytics-toolbar">
                                <label class="report-dashboard-search">
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" />
                                        <path d="m16 16 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                                    </svg>
                                    <input v-model="productSearch" type="search" placeholder="Pesquisar produto..." />
                                </label>

                                <button
                                    type="button"
                                    class="report-dashboard-sort-button"
                                    @click="productSort = productSort === 'quantity' ? 'sales' : 'quantity'"
                                >
                                    {{ productSort === 'quantity' ? 'Quantidade ↓' : 'Valor € ↓' }}
                                </button>

                                <div class="report-dashboard-view-toggle" aria-label="Visualização dos produtos">
                                    <button type="button" :class="{ 'is-active': productViewMode === 'list' }" @click="productViewMode = 'list'">Lista</button>
                                    <button type="button" :class="{ 'is-active': productViewMode === 'chart' }" @click="productViewMode = 'chart'">Gráfico</button>
                                </div>
                            </div>

                            <div v-if="visibleProducts.length && productViewMode === 'list'" class="report-dashboard-products-grid">
                                <div v-for="(column, columnIndex) in productColumns" :key="columnIndex" class="space-y-4">
                                    <article
                                        v-for="(product, productIndex) in column"
                                        :key="`${columnIndex}-${product.code ?? product.label}`"
                                        class="report-dashboard-product-row"
                                    >
                                        <span class="report-dashboard-rank" :class="{ 'is-top': columnIndex * Math.ceil(visibleProducts.length / 2) + productIndex < 3 }">
                                            {{ columnIndex * Math.ceil(visibleProducts.length / 2) + productIndex + 1 }}
                                        </span>
                                        <div>
                                            <div class="report-dashboard-product-head">
                                                <span>{{ product.label }}</span>
                                                <strong>{{ productSort === 'quantity' ? formatNumber(product.quantity_total) : formatMoney(product.sales_total) }}</strong>
                                            </div>
                                            <div class="report-dashboard-product-track">
                                                <span class="report-dashboard-product-fill" :style="{ width: getProductRatioWidth(product) }" />
                                            </div>
                                            <small>{{ formatMoney(product.sales_total) }} faturados</small>
                                        </div>
                                    </article>
                                </div>
                            </div>

                            <div v-else-if="visibleProducts.length" class="report-dashboard-product-chart">
                                <article v-for="product in visibleProducts" :key="`chart-${product.code ?? product.label}`">
                                    <span>{{ product.label }}</span>
                                    <div><i :style="{ width: getProductRatioWidth(product) }" /></div>
                                    <strong>{{ productSort === 'quantity' ? formatNumber(product.quantity_total) : formatMoney(product.sales_total) }}</strong>
                                </article>
                            </div>

                            <p v-else class="report-dashboard-empty">Sem produtos para esta pesquisa.</p>
                        </section>
                    </div>

                    <div v-else-if="activeSection === 'zones'" class="report-dashboard-view">
                        <header class="report-dashboard-view-header">
                            <div>
                                <span>Operação no recinto</span>
                                <h3>Resumo e performance por zona</h3>
                                <p>Faturação e detalhe dos devices agrupados pelas zonas reais do relatório.</p>
                            </div>
                            <label class="report-dashboard-search">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" />
                                    <path d="m16 16 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                                </svg>
                                <input v-model="zoneSearch" type="search" placeholder="Pesquisar zona ou device..." />
                            </label>
                        </header>

                        <section class="dash-card report-dashboard-table-shell">
                            <table class="dash-table report-dashboard-table">
                                <thead>
                                    <tr>
                                        <th>Zona</th>
                                        <th class="text-right">Total</th>
                                        <th class="text-right">Devices</th>
                                        <th class="text-right">Média</th>
                                        <th>Performance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="zone in visibleZonePerformanceRows" :key="zone.label">
                                        <td class="report-dashboard-table-title">{{ zone.label }}</td>
                                        <td class="text-right">{{ formatMoney(zone.totalSales) }}</td>
                                        <td class="text-right">{{ formatNumber(zone.devicesCount) }}</td>
                                        <td class="text-right">{{ formatMoney(zone.averageSales) }}</td>
                                        <td>
                                            <div class="report-dashboard-performance-track">
                                                <span class="report-dashboard-performance-fill" :style="{ width: zone.performanceWidth }" />
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>

                        <section>
                            <div class="report-dashboard-section-heading">
                                <span>Detalhe</span>
                                <h3>Vendas por device</h3>
                            </div>
                            <div class="report-dashboard-zone-grid">
                                <article v-for="zone in visibleZoneDevices" :key="zone.label" class="report-dashboard-zone-card">
                                    <div class="report-dashboard-zone-header">
                                        <span>{{ zone.label }}</span>
                                        <strong>{{ formatNumber(zone.devices_count) }} devices</strong>
                                    </div>
                                    <div class="report-dashboard-zone-body">
                                        <div v-for="item in zone.items" :key="`${zone.label}-${item.code ?? item.label}`" class="report-dashboard-zone-row">
                                            <span>{{ getDeviceLabel(item) }}</span>
                                            <strong>{{ formatMoney(item.sales_total) }}</strong>
                                        </div>
                                    </div>
                                    <div class="report-dashboard-zone-footer">
                                        <span>Total</span>
                                        <strong>{{ formatMoney(zone.total_sales) }}</strong>
                                    </div>
                                </article>
                            </div>
                        </section>
                    </div>

                    <div v-else-if="activeSection === 'reconciliation'" class="report-dashboard-view">
                        <header class="report-dashboard-view-header">
                            <div>
                                <span>Controlo financeiro</span>
                                <h3>Conciliação de pagamentos</h3>
                                <p>{{ props.reconciliation.scope_note }}</p>
                            </div>
                            <label class="report-dashboard-search">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" />
                                    <path d="m16 16 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                                </svg>
                                <input v-model="reconciliationSearch" type="search" placeholder="Procurar device..." />
                            </label>
                        </header>

                        <section class="report-dashboard-grid" :class="reconciliationGridClass">
                            <article class="report-dashboard-summary-card">
                                <span>Pagamentos</span>
                                <strong>{{ formatMoney(props.reconciliation.totals.payments_total) }}</strong>
                                <small>{{ formatNumber(props.reconciliation.documents_count) }} documentos</small>
                            </article>
                            <article class="report-dashboard-summary-card">
                                <span>Vendas</span>
                                <strong>{{ formatMoney(props.reconciliation.totals.sales_total) }}</strong>
                                <small>Linhas sincronizadas</small>
                            </article>
                            <article v-if="showZtPaymentDetails" class="report-dashboard-summary-card">
                                <span>ZT - Card</span>
                                <strong>{{ formatMoney(props.reconciliation.totals.zticket) }}</strong>
                                <small>Pagamento tipo cartão</small>
                            </article>
                            <article class="report-dashboard-summary-card">
                                <span>Diferença</span>
                                <strong :class="['report-dashboard-difference', getDifferenceClass(props.reconciliation.totals.difference)]">
                                    {{ props.reconciliation.totals.difference === null ? '—' : formatMoney(props.reconciliation.totals.difference) }}
                                </strong>
                                <small>Pagamentos menos vendas</small>
                            </article>
                        </section>

                        <section v-if="props.reconciliation.available" class="dash-card report-dashboard-table-shell report-dashboard-reconciliation-shell">
                            <table class="dash-table report-dashboard-table report-dashboard-reconciliation-table">
                                <thead>
                                    <tr>
                                        <th>Device</th>
                                        <th class="text-right">Multibanco</th>
                                        <th v-if="showZtPaymentDetails" class="text-right">ZT - Card</th>
                                        <th class="text-right">Dinheiro</th>
                                        <th class="text-right">Outros</th>
                                        <th class="text-right">Pagamentos</th>
                                        <th class="text-right">Vendas</th>
                                        <th class="text-right">Diferença</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in visibleReconciliationItems" :key="item.store_code ?? item.store_name">
                                        <td>
                                            <strong>{{ item.store_code || '—' }}</strong>
                                            <small>{{ item.store_name }}</small>
                                        </td>
                                        <td class="text-right">{{ formatMoney(item.multibanco) }}</td>
                                        <td v-if="showZtPaymentDetails" class="text-right">{{ formatMoney(item.zticket) }}</td>
                                        <td class="text-right">{{ formatMoney(item.cash) }}</td>
                                        <td class="text-right">{{ formatMoney(item.other) }}</td>
                                        <td class="text-right font-semibold">{{ formatMoney(item.payments_total) }}</td>
                                        <td class="text-right">{{ formatMoney(item.sales_total) }}</td>
                                        <td class="text-right">
                                            <span :class="['report-dashboard-difference-pill', getDifferenceClass(item.difference)]">
                                                {{ item.difference === null ? '—' : formatMoney(item.difference) }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>
                        <p v-else class="dash-card report-dashboard-empty">
                            A sincronização atual não contém documentos de pagamento.
                        </p>
                    </div>

                    <div v-else-if="activeSection === 'comparison'" class="report-dashboard-view">
                        <header class="report-dashboard-view-header">
                            <div>
                                <span>Evolução</span>
                                <h3>Comparativo entre eventos</h3>
                                <p>Leitura de faturação, pagamentos e indicadores operacionais.</p>
                            </div>
                        </header>

                        <p v-if="!props.comparison.available" class="dash-card report-dashboard-empty">
                            {{ props.comparison.message }}
                        </p>

                        <template v-else-if="props.comparison.current && props.comparison.previous">
                            <section class="report-dashboard-comparison-hero">
                                <div>
                                    <span>Variação total</span>
                                    <strong :class="getDifferenceClass(props.comparison.total_variation ?? null)">
                                        {{ formatVariation(props.comparison.total_variation) }}
                                    </strong>
                                    <small>face ao evento anterior</small>
                                </div>
                                <div class="report-dashboard-comparison-bars">
                                    <article>
                                        <span>{{ props.comparison.previous.title }}</span>
                                        <strong>{{ formatMoney(props.comparison.previous.total_sales) }}</strong>
                                        <i :style="{ width: getRatioWidth(props.comparison.previous.total_sales, comparisonMaxTotal) }" />
                                    </article>
                                    <article class="is-current">
                                        <span>{{ props.comparison.current.title }}</span>
                                        <strong>{{ formatMoney(props.comparison.current.total_sales) }}</strong>
                                        <i :style="{ width: getRatioWidth(props.comparison.current.total_sales, comparisonMaxTotal) }" />
                                    </article>
                                </div>
                            </section>

                            <section>
                                <div class="report-dashboard-section-heading">
                                    <span>Indicadores</span>
                                    <h3>Performance operacional</h3>
                                </div>
                                <div class="report-dashboard-comparison-grid">
                                    <article v-for="metric in props.comparison.metrics" :key="metric.key">
                                        <span>{{ metric.label }}</span>
                                        <div>
                                            <small>{{ formatMetric(metric.previous, metric.format) }}</small>
                                            <strong>{{ formatMetric(metric.current, metric.format) }}</strong>
                                        </div>
                                        <em :class="getDifferenceClass(metric.variation)">{{ formatVariation(metric.variation) }}</em>
                                    </article>
                                </div>
                            </section>

                            <section class="dash-card report-dashboard-table-shell">
                                <table class="dash-table report-dashboard-table">
                                    <thead>
                                        <tr>
                                            <th>Método</th>
                                            <th class="text-right">{{ props.comparison.previous.title }}</th>
                                            <th class="text-right">{{ props.comparison.current.title }}</th>
                                            <th class="text-right">Variação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="payment in visibleComparisonPayments" :key="payment.key">
                                            <td class="report-dashboard-table-title">{{ payment.label }}</td>
                                            <td class="text-right">{{ formatMoney(payment.previous) }}</td>
                                            <td class="text-right">{{ formatMoney(payment.current) }}</td>
                                            <td class="text-right">
                                                <span :class="['report-dashboard-difference-pill', getDifferenceClass(payment.variation)]">
                                                    {{ formatVariation(payment.variation) }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </section>

                            <section class="dash-card report-dashboard-comparison-chart">
                                <div class="report-dashboard-section-heading">
                                    <span>Gráfico</span>
                                    <h3>Métodos de pagamento</h3>
                                </div>
                                <div class="report-dashboard-comparison-chart-grid">
                                    <article v-for="payment in visibleComparisonPayments" :key="`chart-${payment.key}`">
                                        <div class="report-dashboard-comparison-columns">
                                            <i :style="{ height: getRatioWidth(payment.previous, Math.max(payment.previous, payment.current)) }" />
                                            <i class="is-current" :style="{ height: getRatioWidth(payment.current, Math.max(payment.previous, payment.current)) }" />
                                        </div>
                                        <strong>{{ payment.label }}</strong>
                                        <small>{{ formatMoney(payment.previous) }} · {{ formatMoney(payment.current) }}</small>
                                    </article>
                                </div>
                            </section>
                        </template>
                    </div>

                    <div v-else class="report-dashboard-view">
                        <header class="report-dashboard-highlights-toolbar">
                            <div class="report-dashboard-highlights-title">
                                <span>06</span>
                                <h3>Destaques</h3>
                            </div>

                            <div class="report-dashboard-highlight-tabs" aria-label="Tipo de destaque">
                                <button type="button" :class="{ 'is-active': highlightScope === 'zones' }" @click="highlightScope = 'zones'">Zonas</button>
                                <button type="button" :class="{ 'is-active': highlightScope === 'devices' }" @click="highlightScope = 'devices'">Devices</button>
                                <button type="button" :class="{ 'is-active': highlightScope === 'products' }" @click="highlightScope = 'products'">Produtos</button>
                            </div>

                            <label class="report-dashboard-search">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" />
                                    <path d="m16 16 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                                </svg>
                                <input v-model="highlightSearch" type="search" placeholder="Pesquisar..." />
                            </label>

                            <div class="report-dashboard-view-toggle" aria-label="Visualização dos destaques">
                                <button type="button" :class="{ 'is-active': highlightViewMode === 'list' }" @click="highlightViewMode = 'list'">Lista</button>
                                <button type="button" :class="{ 'is-active': highlightViewMode === 'chart' }" @click="highlightViewMode = 'chart'">Gráfico</button>
                            </div>
                        </header>

                        <div v-if="visibleHighlightItems.length" class="report-dashboard-podium">
                            <article v-for="(item, index) in visibleHighlightItems.slice(0, 3)" :key="`podium-${item.key}`" :class="{ 'is-first': index === 0 }">
                                <span>{{ index + 1 }}º</span>
                                <em>{{ getHighlightShare(item.value) }}</em>
                                <strong>{{ item.label }}</strong>
                                <b>{{ formatMoney(item.value) }}</b>
                            </article>
                        </div>

                        <section v-if="visibleHighlightItems.length" class="dash-card report-dashboard-highlights-panel">
                            <div v-if="highlightViewMode === 'list'" class="report-dashboard-highlights-list">
                                <article v-for="(item, index) in visibleHighlightItems" :key="item.key">
                                    <span>{{ index + 1 }}º</span>
                                    <div>
                                        <strong>{{ item.label }}</strong>
                                        <small>{{ item.helper }}</small>
                                    </div>
                                    <b>{{ formatMoney(item.value) }}</b>
                                    <em>{{ getHighlightShare(item.value) }}</em>
                                </article>
                            </div>

                            <div v-else class="report-dashboard-highlights-chart">
                                <article v-for="item in visibleHighlightItems" :key="`highlight-chart-${item.key}`">
                                    <span>{{ item.label }}</span>
                                    <div><i :style="{ width: getRatioWidth(item.value, highlightMaxValue) }" /></div>
                                    <strong>{{ formatMoney(item.value) }}</strong>
                                </article>
                            </div>

                            <footer>
                                <span>{{ formatNumber(visibleHighlightItems.length) }} resultados</span>
                                <strong>{{ formatMoney(highlightTotal) }}</strong>
                            </footer>
                        </section>
                        <p v-else class="dash-card report-dashboard-empty">Sem resultados para esta pesquisa.</p>
                    </div>
                </main>
            </div>
        </div>

        <div v-if="detailModal" class="report-dashboard-modal-overlay" @click.self="closeDetailModal">
            <section class="report-dashboard-detail-modal" role="dialog" aria-modal="true" :aria-label="detailModalTitle">
                <header>
                    <h3>{{ detailModalTitle }}</h3>
                    <button type="button" aria-label="Fechar detalhe" @click="closeDetailModal">×</button>
                </header>

                <div v-if="props.dailyBreakdowns.length" class="report-dashboard-detail-modal-body">
                    <template v-if="detailModal === 'payments'">
                        <article v-for="day in props.dailyBreakdowns" :key="`payments-${day.date}`" class="report-dashboard-detail-day">
                            <h4>{{ day.label }}</h4>
                            <div class="report-dashboard-detail-grid">
                                <span>Multibanco</span><strong>{{ formatMoney(day.multibanco) }}</strong>
                                <span v-if="showZtPaymentDetails">ZT - Card</span><strong v-if="showZtPaymentDetails">{{ formatMoney(day.zticket) }}</strong>
                                <span>Dinheiro</span><strong>{{ formatMoney(day.cash) }}</strong>
                                <span v-if="day.other !== 0">Outros pagamentos</span><strong v-if="day.other !== 0">{{ formatMoney(day.other) }}</strong>
                            </div>
                            <footer><span>Total</span><strong>{{ formatMoney(day.sales_total) }}</strong></footer>
                        </article>

                        <div class="report-dashboard-detail-total">
                            <span>Total de {{ formatNumber(props.dailyBreakdowns.length) }} {{ props.dailyBreakdowns.length === 1 ? 'dia' : 'dias' }}</span>
                            <strong>{{ formatMoney(dailyGrandTotal) }}</strong>
                        </div>
                    </template>

                    <template v-else-if="detailModal === 'topup'">
                        <article v-for="day in props.dailyBreakdowns" :key="`topup-${day.date}`" class="report-dashboard-detail-day">
                            <h4>{{ day.label }}</h4>
                            <div class="report-dashboard-detail-grid">
                                <span>Valor carregado</span><strong>{{ formatMoney(day.top_up_loaded) }}</strong>
                                <span>Valor gasto</span><strong>{{ formatMoney(day.top_up_spent) }}</strong>
                                <span>Remanescente</span><strong>{{ formatMoney(day.top_up_remaining) }}</strong>
                                <span>Carregamentos ZT</span><strong>{{ formatNumber(day.top_up_documents_count) }}</strong>
                            </div>
                        </article>

                        <div class="report-dashboard-detail-total">
                            <span>Total carregado</span>
                            <strong>{{ formatMoney(dailyGrandTotal) }}</strong>
                        </div>
                    </template>

                    <template v-else>
                        <article v-for="day in props.dailyBreakdowns" :key="`ticket-${day.date}`" class="report-dashboard-ticket-day">
                            <div>
                                <h4>{{ day.label }}</h4>
                                <small>{{ formatNumber(day.tickets_count) }} documentos</small>
                            </div>
                            <strong>{{ formatMoney(day.average_ticket) }}</strong>
                        </article>

                        <div class="report-dashboard-detail-total">
                            <span>Ticket médio geral</span>
                            <strong>{{ formatMoney(props.summary.average_ticket) }}</strong>
                        </div>
                    </template>
                </div>

                <p v-else class="report-dashboard-detail-empty">Não existem dados diários sincronizados para este evento.</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
