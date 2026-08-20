<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppSidebarIcon from '@/Components/AppSidebarIcon.vue';
import { showErrorToast, showSuccessToast } from '@/lib/swal';
import type {
    DashboardConfiguration,
    DashboardConfigurationItem,
    DashboardEditorMeta,
    DashboardMetricGroup,
    DashboardSectionKey,
} from '@/types/dashboard-configuration';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

type DashboardSection = DashboardSectionKey;
type ProductSort = 'quantity' | 'sales';
type ViewMode = 'list' | 'chart';
type HighlightScope = 'zones' | 'devices' | 'products';
type DetailModal = 'payments' | 'topup' | 'ticket' | null;

interface EventMeta {
    id: number;
    title: string;
    description: string | null;
    event_date: string;
    report_starts_at: string | null;
    report_ends_at: string | null;
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
    event_total_sales: number;
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

interface SyncStatusMeta {
    status: 'idle' | 'processing' | 'completed' | 'failed';
    is_stale: boolean;
    latest_attempt_at: string | null;
    last_success_at: string | null;
    stage: string;
    machines_total: number;
    machines_processed: number;
    documents_processed: number;
    message: string | null;
}

interface BreakdownItem {
    label: string;
    code: string | null;
    rows_count: number;
    quantity_total: number;
    sales_total: number;
    offered_quantity?: number;
    sold_quantity?: number;
    category?: string;
}

interface BarGroupItem {
    label: string;
    stores_count: number;
    members: string[];
    rows_count: number;
    tickets_count: number;
    quantity_total: number;
    sales_total: number;
    average_ticket: number;
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
    hour_from: string;
    hour_to: string;
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
    key: string;
    label: string;
    value: string;
    helper: string;
}

interface ZonePerformanceRow {
    label: string;
    totalSales: number;
    devicesCount: number;
    ticketsCount: number;
    averageTicket: number;
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

interface SummaryHourlyPoint extends HourlySale {
    x: number;
    bar_x: number;
    bar_width: number;
    sales_y: number;
    sales_height: number;
    transaction_y: number;
    is_peak: boolean;
}

interface SidebarSectionItem {
    key: DashboardSection;
    label: string;
    icon: 'dashboard' | 'products' | 'payments' | 'zones' | 'performance' | 'compare';
}

interface ChartOperationalItem {
    label: string;
    value: number;
    helper: string;
    height: string;
}

const props = withDefaults(defineProps<{
    event: EventMeta;
    eventOptions: EventOption[];
    summary: EventSummary;
    barGroups?: BarGroupItem[];
    topStores?: BreakdownItem[];
    topProducts?: BreakdownItem[];
    productBreakdowns?: ProductBreakdowns;
    dailySales: DailySale[];
    dailyBreakdowns: DailyBreakdown[];
    hourlySales?: HourlySale[];
    paymentSummary: PaymentSummary;
    reconciliation?: ReconciliationData;
    comparison?: ComparisonData;
    zoneDevices?: ZoneDeviceGroup[];
    filters: DashboardFilters;
    filterOptions?: FilterOptions;
    autoSync: AutoSyncMeta;
    syncStatus: SyncStatusMeta;
    dashboardConfiguration: DashboardConfiguration;
    dashboardEditor?: DashboardEditorMeta | null;
    previewMode?: boolean;
    initialSection?: DashboardSection;
    backUrl: string;
    backLabel: string;
}>(), {
    barGroups: () => [],
    topStores: () => [],
    topProducts: () => [],
    productBreakdowns: () => ({ total: [], days: [] }),
    hourlySales: () => [],
    reconciliation: () => ({
        available: false,
        comparable: false,
        documents_count: 0,
        scope_note: 'A carregar dados de conciliação.',
        totals: {
            multibanco: 0,
            cash: 0,
            zticket: 0,
            other: 0,
            payments_total: 0,
            sales_total: 0,
            difference: 0,
        },
        items: [],
    }),
    comparison: () => ({
        available: false,
        message: 'A carregar comparação.',
    }),
    zoneDevices: () => [],
    filterOptions: () => ({ barGroups: [], stores: [], products: [] }),
    dashboardEditor: null,
    initialSection: 'summary',
});

const activeSection = ref<DashboardSection>(props.initialSection);
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
const hourlyPanelExpanded = ref(true);
const zonePanelExpanded = ref(true);
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

const activeDashboardConfiguration = computed(() => props.dashboardConfiguration);
const dashboardUsesCustomLayout = computed(() => activeDashboardConfiguration.value.customized);
const dashboardSections = computed(() => activeDashboardConfiguration.value.sections
    .filter((section) => section.visible && section.available)
    .map((section, index) => ({
        key: section.key as DashboardSection,
        number: String(index + 1).padStart(2, '0'),
        label: section.label,
        helper: section.helper,
    })));

const sidebarSectionDefinitions: SidebarSectionItem[] = [
    { key: 'summary', label: 'Dashboard', icon: 'dashboard' },
    { key: 'products', label: 'Produtos', icon: 'products' },
    { key: 'reconciliation', label: 'Pagamentos', icon: 'payments' },
    { key: 'zones', label: 'Zonas', icon: 'zones' },
    { key: 'highlights', label: 'Performance', icon: 'performance' },
    { key: 'comparison', label: 'Comparar edições', icon: 'compare' },
];

const sidebarSections = computed<SidebarSectionItem[]>(() => sidebarSectionDefinitions
    .filter((definition) => dashboardSections.value.some((section) => section.key === definition.key))
    .map((definition) => {
        const configuredSection = dashboardSections.value.find((section) => section.key === definition.key);

        return {
            ...definition,
            label: dashboardUsesCustomLayout.value
                ? configuredSection?.label || definition.label
                : definition.label,
        };
    }));
const mainSidebarSections = computed(() => sidebarSections.value.filter((section) => section.key !== 'comparison'));
const comparisonSidebarSection = computed(() => sidebarSections.value.find((section) => section.key === 'comparison') ?? null);
const quickZoneOptions = computed(() => props.filterOptions.barGroups.slice(0, 6));
const hiddenQuickZoneCount = computed(() => Math.max(0, props.filterOptions.barGroups.length - quickZoneOptions.value.length));
const hourOptions = Array.from({ length: 24 }, (_, hour) => ({
    value: String(hour),
    label: `${String(hour).padStart(2, '0')}:00`,
}));

watch(dashboardSections, (sections) => {
    if (!sections.some((section) => section.key === activeSection.value)) {
        activeSection.value = sections[0]?.key ?? 'summary';
    }
}, { immediate: true });

watch(() => props.initialSection, (section) => {
    activeSection.value = section;
});

function sidebarSectionUrl(section: DashboardSection): string | null {
    const routeSuffix = {
        summary: 'dashboard',
        products: 'products',
        reconciliation: 'payments',
        zones: 'zones',
        highlights: 'performance',
        comparison: 'comparison',
    }[section];

    if (!routeSuffix) {
        return null;
    }

    return route(`${props.previewMode ? 'admin.events' : 'events'}.${routeSuffix}`, props.event.id);
}

const reportSectionTitle = computed(() => ({
    products: 'Produtos',
    reconciliation: 'Pagamentos',
    zones: 'Zonas',
    highlights: 'Performance',
    comparison: 'Comparar edições',
}[activeSection.value] ?? 'Dashboard'));

function configuredBlock(key: string): DashboardConfigurationItem | undefined {
    return activeDashboardConfiguration.value.blocks.find((block) => block.key === key);
}

function isBlockVisible(key: string): boolean {
    const block = configuredBlock(key);

    return Boolean(block?.visible && block.available);
}

function blockOrder(key: string): number {
    const block = configuredBlock(key);

    if (!block?.area) {
        return 0;
    }

    return activeDashboardConfiguration.value.blocks
        .filter((item) => item.area === block.area)
        .findIndex((item) => item.key === key);
}

function blockLabel(key: string, fallback: string): string {
    return configuredBlock(key)?.label || fallback;
}

function blockHelper(key: string, fallback: string): string {
    return configuredBlock(key)?.helper || fallback;
}

function configuredMetrics(group: DashboardMetricGroup): DashboardConfigurationItem[] {
    return activeDashboardConfiguration.value.metrics.filter(
        (metric) => metric.group === group && metric.visible && metric.available,
    );
}

function configuredMetric(key: string): DashboardConfigurationItem | undefined {
    return activeDashboardConfiguration.value.metrics.find((metric) => metric.key === key);
}

function metricIsVisible(key: string): boolean {
    const metric = configuredMetric(key);

    return Boolean(metric?.visible && metric.available);
}

function metricLabel(key: string, fallback: string): string {
    return configuredMetric(key)?.label || fallback;
}

function sectionIsVisible(key: DashboardSection): boolean {
    return dashboardSections.value.some((section) => section.key === key);
}

function sidebarSectionIsActive(key: DashboardSection): boolean {
    if (key === 'summary') {
        return activeSection.value === 'summary' || activeSection.value === 'charts';
    }

    return activeSection.value === key;
}

const metricGridClasses: Record<number, string> = {
    1: 'report-dashboard-grid-1',
    2: 'report-dashboard-grid-2',
    3: 'report-dashboard-grid-3',
    4: 'report-dashboard-grid-4',
    5: 'report-dashboard-grid-5',
};

function metricGridClass(count: number): string {
    return metricGridClasses[Math.min(5, Math.max(1, count))];
}

function blockStyle(key: string): { order: number } | undefined {
    return dashboardUsesCustomLayout.value
        ? { order: blockOrder(key) }
        : undefined;
}

const hasImportedData = computed(
    () => props.summary.total_rows > 0 || props.paymentSummary.movement_documents_count > 0,
);
const hasProcessingSync = computed(
    () => props.summary.processing_imports_count > 0
        || props.autoSync.state === 'processing'
        || props.syncStatus.status === 'processing',
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

    if (props.syncStatus.is_stale) {
        return 'Última tentativa falhou';
    }

    return props.autoSync.enabled
        ? `Automática a cada ${props.autoSync.interval_minutes} min`
        : 'Sincronização automática encerrada';
});
const syncProgressLabel = computed(() => {
    if (props.syncStatus.status !== 'processing') {
        return `Tentativa em ${formatDateTime(props.syncStatus.latest_attempt_at)}`;
    }

    if (props.syncStatus.machines_total > 0) {
        return `${formatNumber(props.syncStatus.machines_processed)} de ${formatNumber(props.syncStatus.machines_total)} máquinas · ${formatNumber(props.syncStatus.documents_processed)} documentos`;
    }

    return 'A preparar a leitura das máquinas';
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

const paymentCards = computed<MetricCard[]>(() => configuredMetrics('payments').map((metric) => {
    const value = {
        multibanco: props.paymentSummary.multibanco,
        zticket: props.paymentSummary.zticket,
        cash: props.paymentSummary.cash,
        other_payments: props.paymentSummary.other,
    }[metric.key] ?? 0;

    return {
        key: metric.key,
        label: metric.label,
        value: formatMoney(value),
        helper: getPaymentShare(value),
    };
}));

const movementCards = computed<MetricCard[]>(() => {
    const values: Record<string, string> = {
        total_without_zt: formatMoney(props.paymentSummary.total_without_zt),
        top_up_count: formatNumber(props.paymentSummary.top_up_documents_count),
        top_up_value: formatMoney(props.paymentSummary.top_up_loaded),
        total_with_zt: formatMoney(props.paymentSummary.total_with_zt),
        other_movements: formatMoney(props.paymentSummary.other_movements),
    };

    return configuredMetrics('movement').map((metric) => ({
        key: metric.key,
        label: metric.label,
        value: values[metric.key] ?? formatMoney(0),
        helper: metric.helper,
    }));
});

const topUpCards = computed<MetricCard[]>(() => configuredMetrics('top_up').map((metric) => {
    const values: Record<string, string> = {
        loaded: formatMoney(props.paymentSummary.top_up_loaded),
        spent: formatMoney(props.paymentSummary.top_up_spent),
        remaining: formatMoney(props.paymentSummary.top_up_remaining),
    };
    const helper = metric.key === 'loaded'
        ? `${formatNumber(props.paymentSummary.top_up_documents_count)} ${metric.helper}`
        : metric.helper;

    return {
        key: metric.key,
        label: metric.label,
        value: values[metric.key] ?? formatMoney(0),
        helper,
    };
}));

const movementGridClass = computed(() => metricGridClass(movementCards.value.length));
const paymentGridClass = computed(() => metricGridClass(paymentCards.value.length));
const reconciliationGridClass = computed(() => showZtPaymentDetails.value && metricIsVisible('zticket')
    ? 'report-dashboard-grid-4'
    : 'report-dashboard-grid-3');
const visibleComparisonPayments = computed(() => (props.comparison.payments ?? [])
    .filter((payment) => metricIsVisible(payment.key === 'other' ? 'other_payments' : payment.key))
    .map((payment) => ({
        ...payment,
        label: metricLabel(payment.key === 'other' ? 'other_payments' : payment.key, payment.label),
    })));

const summaryCards = computed<MetricCard[]>(() => {
    const averagePerDevice = props.summary.machines_count > 0
        ? props.summary.total_sales / props.summary.machines_count
        : 0;
    const values: Record<string, string> = {
        devices: formatNumber(props.summary.machines_count),
        zones: formatNumber(props.summary.bar_groups_count),
        average_ticket: formatMoney(props.summary.average_ticket),
        products: formatNumber(props.summary.products_count),
        average_device: formatMoney(averagePerDevice),
    };

    return configuredMetrics('operations').map((metric) => ({
        key: metric.key,
        label: metric.label,
        value: values[metric.key] ?? '0',
        helper: metric.helper,
    }));
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
const productSoldQuantity = computed(() => activeProducts.value.reduce(
    (total, item) => total + (item.sold_quantity ?? item.quantity_total),
    0,
));
const productOfferedQuantity = computed(() => activeProducts.value.reduce(
    (total, item) => total + (item.offered_quantity ?? 0),
    0,
));
const productServedQuantity = computed(() => productSoldQuantity.value + productOfferedQuantity.value);
const productOfferWeight = computed(() => productServedQuantity.value > 0
    ? (productOfferedQuantity.value / productServedQuantity.value) * 100
    : 0);
const averageProductValue = computed(() => props.summary.total_quantity !== 0
    ? props.summary.total_sales / props.summary.total_quantity
    : 0);
const averageTopUp = computed(() => props.paymentSummary.top_up_documents_count > 0
    ? props.paymentSummary.top_up_loaded / props.paymentSummary.top_up_documents_count
    : 0);
const paymentMethodsTotal = computed(() => props.paymentSummary.multibanco
    + props.paymentSummary.cash
    + props.paymentSummary.other
    + (showZtCard.value ? props.paymentSummary.zticket : 0));
const selectedZoneShare = computed(() => props.summary.event_total_sales > 0
    ? (props.summary.total_sales / props.summary.event_total_sales) * 100
    : 0);
const selectedZoneLabel = computed(() => localFilters.value.bar_group || 'Todas as zonas');
const maxZoneSales = computed(() =>
    props.barGroups.reduce((max, group) => Math.max(max, group.sales_total), 0),
);
const zonePerformanceRows = computed<ZonePerformanceRow[]>(() =>
    props.barGroups.map((group) => ({
        label: group.label,
        totalSales: group.sales_total,
        devicesCount: group.stores_count,
        ticketsCount: group.tickets_count,
        averageTicket: group.average_ticket,
        averageSales: group.stores_count > 0 ? group.sales_total / group.stores_count : 0,
        performanceWidth: getRatioWidth(group.sales_total, maxZoneSales.value),
    })),
);
const leadingZoneRows = computed(() => [...zonePerformanceRows.value]
    .sort((left, right) => right.totalSales - left.totalSales)
    .slice(0, 6));
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
const summaryHourlySeries = computed<HourlySale[]>(() => {
    const activeHours = hourlySeries.value
        .filter((sale) => sale.sales_total > 0 || sale.tickets_count > 0)
        .map((sale) => sale.hour)
        .sort((left, right) => left - right);

    if (activeHours.length === 0) {
        return [];
    }

    let largestGap = -1;
    let startHour = activeHours[0];

    activeHours.forEach((hour, index) => {
        const nextHour = index === activeHours.length - 1
            ? activeHours[0] + 24
            : activeHours[index + 1];
        const gap = nextHour - hour;

        if (gap > largestGap) {
            largestGap = gap;
            startHour = nextHour % 24;
        }
    });

    const endHour = (startHour + 23 - (largestGap - 1)) % 24;
    const operatingHourCount = ((endHour - startHour + 24) % 24) + 1;
    const totalsByHour = new Map(hourlySeries.value.map((sale) => [sale.hour, sale]));

    return Array.from({ length: operatingHourCount }, (_, index) => {
        const hour = (startHour + index) % 24;

        return totalsByHour.get(hour) as HourlySale;
    });
});
const summaryHourlySalesMax = computed(() => niceChartAxisMax(
    Math.max(...summaryHourlySeries.value.map((sale) => Math.max(0, sale.sales_total)), 0),
));
const summaryHourlyTicketsMax = computed(() => niceChartAxisMax(
    Math.max(...summaryHourlySeries.value.map((sale) => Math.max(0, sale.tickets_count)), 0),
));
const summaryHourlyChartPoints = computed<SummaryHourlyPoint[]>(() => {
    const chartWidth = 760;
    const left = 52;
    const right = 48;
    const top = 16;
    const bottom = 215;
    const plotWidth = chartWidth - left - right;
    const plotHeight = bottom - top;
    const slotWidth = plotWidth / Math.max(summaryHourlySeries.value.length, 1);
    const barWidth = Math.min(22, Math.max(5, slotWidth * 0.44));
    const peakSales = Math.max(...summaryHourlySeries.value.map((sale) => sale.sales_total), 0);

    return summaryHourlySeries.value.map((sale, index) => {
        const x = left + (slotWidth * (index + 0.5));
        const salesRatio = Math.max(0, sale.sales_total) / summaryHourlySalesMax.value;
        const transactionRatio = Math.max(0, sale.tickets_count) / summaryHourlyTicketsMax.value;
        const salesY = bottom - (salesRatio * plotHeight);

        return {
            ...sale,
            x,
            bar_x: x - (barWidth / 2),
            bar_width: barWidth,
            sales_y: salesY,
            sales_height: bottom - salesY,
            transaction_y: bottom - (transactionRatio * plotHeight),
            is_peak: peakSales > 0 && sale.sales_total === peakSales,
        };
    });
});
const summaryHourlyTransactionPath = computed(() => buildSmoothChartPath(
    summaryHourlyChartPoints.value.map((point) => ({ x: point.x, y: point.transaction_y })),
));
const summaryHourlySalesTicks = computed(() => Array.from({ length: 5 }, (_, index) => {
    const ratio = index / 4;

    return {
        y: 215 - (ratio * 199),
        value: summaryHourlySalesMax.value * ratio,
    };
}).reverse());
const summaryHourlyTicketTicks = computed(() => Array.from({ length: 5 }, (_, index) => {
    const ratio = index / 4;

    return {
        y: 215 - (ratio * 199),
        value: Math.round(summaryHourlyTicketsMax.value * ratio),
    };
}).reverse());
const summaryHourlyAxisLabels = computed(() => summaryHourlyChartPoints.value.filter(
    (_, index, points) => index % 2 === 0 || index === points.length - 1,
));
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
const primaryHourlyPeak = computed(() => [...hourlySeries.value]
    .filter((sale) => sale.sales_total > 0)
    .sort((left, right) => right.sales_total - left.sales_total)[0] ?? null);
const hourlySelectedTotal = computed(() => selectedHourlySales.value.reduce(
    (total, sale) => total + sale.sales_total,
    0,
));
const activeOperatingHours = computed(() => hourlySeries.value.filter((sale) => sale.sales_total > 0).length);
const averageSalesPerHour = computed(() => activeOperatingHours.value > 0
    ? hourlySelectedTotal.value / activeOperatingHours.value
    : 0);
const leadingZone = computed(() => [...props.barGroups]
    .sort((left, right) => right.sales_total - left.sales_total)[0] ?? null);
const leadingZoneShare = computed(() => {
    if (!leadingZone.value || props.summary.total_sales <= 0) {
        return 0;
    }

    return Math.min(100, Math.max(0, (leadingZone.value.sales_total / props.summary.total_sales) * 100));
});
const bestProductBySales = computed(() => [...props.topProducts]
    .sort((left, right) => right.sales_total - left.sales_total)[0] ?? null);
const mostServedProduct = computed(() => [...props.topProducts]
    .sort((left, right) => right.quantity_total - left.quantity_total)[0] ?? null);
const bestEventDay = computed(() => [...props.dailySales]
    .sort((left, right) => right.sales_total - left.sales_total)[0] ?? null);
const summaryTopProducts = computed(() => [...props.topProducts]
    .sort((left, right) => right.sales_total - left.sales_total)
    .slice(0, 5));
const eventPeriodLabel = computed(() => {
    const firstDate = props.event.report_starts_at || props.dailySales[0]?.date || props.event.event_date;
    const lastDate = props.event.report_ends_at || props.dailySales[props.dailySales.length - 1]?.date;
    const firstDay = formatPeriodDate(firstDate);
    const lastDay = lastDate ? formatPeriodDate(lastDate) : null;

    return lastDay && lastDay !== firstDay ? `${firstDay} - ${lastDay}` : firstDay;
});
const eventStatusLabel = computed(() => hasProcessingSync.value || props.autoSync.enabled
    ? 'Em curso'
    : 'Concluído');
const syncMachinesTotal = computed(() => props.syncStatus.machines_total > 0
    ? props.syncStatus.machines_total
    : props.summary.machines_count);
const syncMachinesProcessed = computed(() => (hasProcessingSync.value || props.syncStatus.is_stale) && props.syncStatus.machines_total > 0
    ? Math.min(props.syncStatus.machines_processed, syncMachinesTotal.value)
    : props.summary.machines_count);
const syncMachinesPending = computed(() => Math.max(0, syncMachinesTotal.value - syncMachinesProcessed.value));
const syncCompletionPercentage = computed(() => syncMachinesTotal.value > 0
    ? Math.min(100, Math.max(0, (syncMachinesProcessed.value / syncMachinesTotal.value) * 100))
    : 0);
const filteredCoveragePercentage = computed(() => props.summary.total_rows > 0
    ? Math.min(100, Math.max(0, (props.summary.filtered_rows / props.summary.total_rows) * 100))
    : 0);
const hourlyChartAriaLabel = computed(() => hourlyPeakItems.value
    .map((sale) => `${sale.label}, ${sale.hour_label}: ${formatMoney(sale.sales_total)}`)
    .join(', '));
const chartPaymentItems = computed<ChartPaymentItem[]>(() => {
    const palette = ['var(--accent)', '#25b9a7', '#f0b44d', '#ef6b74'];
    const values: Record<string, number> = {
        multibanco: props.paymentSummary.multibanco,
        zticket: props.paymentSummary.zticket,
        cash: props.paymentSummary.cash,
        other_payments: props.paymentSummary.other,
    };
    const payments = configuredMetrics('payments')
        .map((metric) => ({
            key: metric.key,
            label: metric.label,
            value: values[metric.key] ?? 0,
        }))
        .filter((payment) => payment.value > 0);
    const total = payments.reduce((sum, payment) => sum + payment.value, 0);

    return payments.map((payment, index) => ({
        ...payment,
        percentage: total > 0 ? (payment.value / total) * 100 : 0,
        color: palette[index % palette.length],
    }));
});
const bestPaymentMethod = computed(() => [...chartPaymentItems.value]
    .sort((left, right) => right.value - left.value)[0] ?? null);
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
    const values: Record<string, number> = {
        devices: props.summary.machines_count,
        zones: props.summary.bar_groups_count,
        products: props.summary.products_count,
    };
    const items = configuredMetrics('operations')
        .filter((metric) => ['devices', 'zones', 'products'].includes(metric.key))
        .map((metric) => ({
            label: metric.label,
            value: values[metric.key] ?? 0,
            helper: metric.helper,
        }));
    const max = items.reduce((highest, item) => Math.max(highest, item.value), 0);

    return items.map((item) => ({
        ...item,
        height: `${max > 0 ? Math.max(8, (item.value / max) * 100) : 0}%`,
    }));
});
const chartAveragePerDevice = computed(() => props.summary.machines_count > 0
    ? props.summary.total_sales / props.summary.machines_count
    : 0);
const chartOperationalMoneyMetrics = computed(() => configuredMetrics('operations')
    .filter((metric) => ['average_ticket', 'average_device'].includes(metric.key)));
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
        if (!hasProcessingSync.value || isRefreshingAutoSync.value) {
            return;
        }

        isRefreshingAutoSync.value = true;
        router.visit(getCurrentDashboardUrl(), {
            method: 'get',
            only: ['event', 'summary', 'autoSync', 'syncStatus'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                isRefreshingAutoSync.value = false;
            },
        });
    }, 5000);
};

const refreshAutoSyncStatus = () => {
    if (isRefreshingAutoSync.value) {
        return;
    }

    isRefreshingAutoSync.value = true;
    lastAutoSyncRefreshAt.value = Date.now();

    router.visit(getCurrentDashboardUrl(), {
        method: 'get',
        only: ['event', 'summary', 'autoSync', 'syncStatus'],
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

const printDashboard = () => {
    if (typeof window === 'undefined') {
        return;
    }

    eventSwitcherOpen.value = false;
    filtersOpen.value = false;
    window.print();
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
        hour_from: '',
        hour_to: '',
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

function formatPeriodDate(value: string) {
    return new Intl.DateTimeFormat('pt-PT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(value));
}

function niceChartAxisMax(value: number): number {
    if (!Number.isFinite(value) || value <= 0) {
        return 1;
    }

    const roughStep = value / 4;
    const magnitude = 10 ** Math.floor(Math.log10(roughStep));
    const step = Math.ceil(roughStep / magnitude) * magnitude;

    return step * 4;
}

function buildSmoothChartPath(points: Array<{ x: number; y: number }>): string {
    if (points.length === 0) {
        return '';
    }

    if (points.length === 1) {
        return `M ${points[0].x.toFixed(2)} ${points[0].y.toFixed(2)}`;
    }

    return points.reduce((path, point, index) => {
        if (index === 0) {
            return `M ${point.x.toFixed(2)} ${point.y.toFixed(2)}`;
        }

        const previous = points[index - 1];
        const beforePrevious = points[index - 2] ?? previous;
        const next = points[index + 1] ?? point;
        const firstControlX = previous.x + ((point.x - beforePrevious.x) / 6);
        const firstControlY = previous.y + ((point.y - beforePrevious.y) / 6);
        const secondControlX = point.x - ((next.x - previous.x) / 6);
        const secondControlY = point.y - ((next.y - previous.y) / 6);

        return `${path} C ${firstControlX.toFixed(2)} ${firstControlY.toFixed(2)}, ${secondControlX.toFixed(2)} ${secondControlY.toFixed(2)}, ${point.x.toFixed(2)} ${point.y.toFixed(2)}`;
    }, '');
}

function formatChartMoneyAxis(value: number): string {
    if (Math.abs(value) >= 1000) {
        return `${new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 0 }).format(value / 1000)}k €`;
    }

    return `${formatNumber(value)} €`;
}

function formatChartCountAxis(value: number): string {
    return new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 0 }).format(value);
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

function getSalesShare(value: number) {
    if (props.summary.total_sales <= 0) {
        return '0,0%';
    }

    return `${((value / props.summary.total_sales) * 100).toFixed(1).replace('.', ',')}%`;
}

function getDeviceLabel(item: BreakdownItem) {
    return item.code ? `${item.code} - ${item.label}` : item.label;
}

function getZoneFilterValue(label: string) {
    return props.filterOptions.barGroups.find((option) => option.label === label)?.value ?? label;
}

function getDifferenceClass(value: number | null) {
    if (value === null || Math.abs(value) < 0.01) {
        return 'is-neutral';
    }

    return value > 0 ? 'is-positive' : 'is-negative';
}
</script>

<template>
    <Head :title="`${reportSectionTitle} - ${props.event.title}`" />

    <AuthenticatedLayout>
        <template #sidebar-navigation="{ isAdmin }">
            <section class="contacto-sidebar-navigation" aria-label="Navegação principal">
                <div class="contacto-sidebar-menu">
                    <Link
                        v-for="section in mainSidebarSections"
                        :key="section.key"
                        :href="sidebarSectionUrl(section.key) || '#'"
                        class="contacto-sidebar-menu-item"
                        :class="{
                            'is-active': sidebarSectionIsActive(section.key),
                            'is-dashboard': section.key === 'summary',
                        }"
                    >
                        <AppSidebarIcon :name="section.icon" />
                        <span>{{ section.label }}</span>
                    </Link>

                    <Link
                        v-if="isAdmin"
                        :href="route('admin.events.index')"
                        class="contacto-sidebar-menu-item"
                    >
                        <AppSidebarIcon name="events" />
                        <span>Eventos</span>
                    </Link>

                    <Link
                        v-if="isAdmin"
                        :href="route('admin.clients.index')"
                        class="contacto-sidebar-menu-item"
                    >
                        <AppSidebarIcon name="clients" />
                        <span>Clientes</span>
                    </Link>

                    <Link
                        v-if="comparisonSidebarSection"
                        :href="sidebarSectionUrl(comparisonSidebarSection.key) || '#'"
                        class="contacto-sidebar-menu-item"
                        :class="{ 'is-active': sidebarSectionIsActive(comparisonSidebarSection.key) }"
                    >
                        <AppSidebarIcon :name="comparisonSidebarSection.icon" />
                        <span>{{ comparisonSidebarSection.label }}</span>
                    </Link>

                    <Link
                        v-if="props.dashboardEditor?.enabled"
                        :href="props.dashboardEditor.edit_url"
                        class="contacto-sidebar-menu-item contacto-sidebar-editor-link"
                    >
                        <AppSidebarIcon name="edit" />
                        <span>Editar página</span>
                    </Link>
                </div>

                <div class="contacto-sidebar-sync">
                    <div>
                        <span :class="{ 'is-syncing': hasProcessingSync, 'is-failed': props.syncStatus.is_stale }" />
                        <strong>{{ autoSyncStatusLabel }}</strong>
                    </div>
                    <small>Última atualização: {{ formatDateTime(props.summary.last_synced_at) }}</small>
                    <em>Cashless by Contacto Digital</em>
                </div>
            </section>
        </template>

        <template #header>
            <div class="contacto-dashboard-header">
                <div class="contacto-dashboard-event" :class="{ 'is-open': eventSwitcherOpen }">
                    <span>{{ props.event.client_business_name || props.event.client_name }}</span>
                    <button
                        type="button"
                        class="contacto-dashboard-event-trigger"
                        :disabled="!hasEventOptions"
                        :aria-expanded="eventSwitcherOpen"
                        @click="eventSwitcherOpen = !eventSwitcherOpen"
                    >
                        <strong>{{ eventSwitcherTitle }}</strong>
                        <em>{{ eventStatusLabel }}</em>
                        <svg v-if="hasEventOptions" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>

                    <div v-if="eventSwitcherOpen && hasEventOptions" class="report-dashboard-event-menu contacto-dashboard-event-menu">
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

                <div class="contacto-dashboard-actions">
                    <span class="contacto-header-period">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M7 3v3m10-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                        {{ eventPeriodLabel }}
                    </span>

                    <button type="button" class="contacto-header-button" @click="printDashboard">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M12 3v11m0 0 4-4m-4 4-4-4M5 14v5h14v-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Exportar relatório
                    </button>

                    <a
                        :href="whatsappSupportUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="contacto-header-button"
                    >
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M12 3 4.5 6v5.2c0 4.5 3 8.6 7.5 9.8 4.5-1.2 7.5-5.3 7.5-9.8V6L12 3Zm-3 9 2 2 4-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Equipa Contacto Digital
                    </a>
                </div>
            </div>
        </template>

        <div class="dash-page">
            <section
                v-if="hasProcessingSync || props.syncStatus.is_stale"
                class="report-dashboard-sync-status"
                :class="{ 'is-processing': hasProcessingSync, 'is-failed': props.syncStatus.is_stale }"
                role="status"
            >
                <span class="report-dashboard-sync-status-indicator" aria-hidden="true" />
                <div>
                    <strong>{{ hasProcessingSync ? 'Atualização dos dados em curso' : 'Atenção à última sincronização' }}</strong>
                    <p>{{ props.syncStatus.message }}</p>
                    <small>{{ syncProgressLabel }}</small>
                </div>
            </section>

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
                        <Link
                            v-for="section in sidebarSections"
                            :key="section.key"
                            :href="sidebarSectionUrl(section.key) || '#'"
                            class="report-dashboard-navigation-item"
                            :class="{ 'is-active': sidebarSectionIsActive(section.key) }"
                        >
                            <span class="report-dashboard-navigation-number">
                                <AppSidebarIcon :name="section.icon" />
                            </span>
                            <strong>{{ section.label }}</strong>
                        </Link>
                    </nav>

                    <div class="report-dashboard-navigation-status">
                        <span :class="{ 'is-syncing': hasProcessingSync, 'is-failed': props.syncStatus.is_stale }" />
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
                                <span>Hora inicial</span>
                                <select v-model="localFilters.hour_from" class="dash-input">
                                    <option value="">Início</option>
                                    <option v-for="option in hourOptions" :key="`from-${option.value}`" :value="option.value">{{ option.label }}</option>
                                </select>
                            </label>
                            <label>
                                <span>Hora final</span>
                                <select v-model="localFilters.hour_to" class="dash-input">
                                    <option value="">Fim</option>
                                    <option v-for="option in hourOptions" :key="`to-${option.value}`" :value="option.value">{{ option.label }}</option>
                                </select>
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

                    <section
                        v-if="!['summary', 'comparison', 'charts'].includes(activeSection)"
                        class="contacto-report-filterbar"
                        aria-label="Filtros do relatório"
                    >
                        <div class="contacto-report-filterbar-zones">
                            <span class="contacto-label">Zona</span>
                            <button
                                type="button"
                                :class="{ 'is-active': localFilters.bar_group === '' }"
                                @click="applyBarGroupFilter('')"
                            >
                                Todas
                            </button>
                            <button
                                v-for="option in quickZoneOptions"
                                :key="`section-zone-${option.value}`"
                                type="button"
                                :class="{ 'is-active': localFilters.bar_group === option.value }"
                                @click="applyBarGroupFilter(option.value)"
                            >
                                {{ option.label }}
                            </button>
                            <button
                                v-if="hiddenQuickZoneCount > 0"
                                type="button"
                                class="contacto-report-more"
                                @click="filtersOpen = true"
                            >
                                +{{ hiddenQuickZoneCount }}
                            </button>
                        </div>

                        <div class="contacto-report-filterbar-period">
                            <span class="contacto-label">Período</span>
                            <input v-model="localFilters.date_from" type="date" class="contacto-report-select" @change="applyFilters" />
                            <span>até</span>
                            <input v-model="localFilters.date_to" type="date" class="contacto-report-select" @change="applyFilters" />
                            <span class="contacto-label contacto-report-hour-label">Hora</span>
                            <select v-model="localFilters.hour_from" class="contacto-report-select" @change="applyFilters">
                                <option value="">Início</option>
                                <option v-for="option in hourOptions" :key="`inline-from-${option.value}`" :value="option.value">{{ option.label }}</option>
                            </select>
                            <span>até</span>
                            <select v-model="localFilters.hour_to" class="contacto-report-select" @change="applyFilters">
                                <option value="">Fim</option>
                                <option v-for="option in hourOptions" :key="`inline-to-${option.value}`" :value="option.value">{{ option.label }}</option>
                            </select>
                            <button type="button" class="contacto-report-refine" @click="filtersOpen = true">Ajustar filtros</button>
                            <button v-if="activeFilterCount > 0" type="button" class="contacto-report-clear" @click="clearFilters">Limpar</button>
                        </div>
                    </section>

                    <div
                        v-if="activeSection === 'summary'"
                        class="contacto-live-dashboard"
                    >
                        <section class="contacto-zone-filter" aria-label="Filtrar por zona">
                            <div class="contacto-zone-filter-row">
                                <span class="contacto-label">Zonas</span>
                                <button
                                    type="button"
                                    :class="{ 'is-active': localFilters.bar_group === '' }"
                                    @click="applyBarGroupFilter('')"
                                >
                                    Todas
                                </button>
                                <button
                                    v-for="option in quickZoneOptions"
                                    :key="`quick-${option.value}`"
                                    type="button"
                                    :class="{ 'is-active': localFilters.bar_group === option.value }"
                                    @click="applyBarGroupFilter(option.value)"
                                >
                                    {{ option.label }}
                                </button>
                                <button
                                    v-if="hiddenQuickZoneCount > 0"
                                    type="button"
                                    class="contacto-zone-more"
                                    @click="filtersOpen = true"
                                >
                                    +{{ hiddenQuickZoneCount }} zonas
                                </button>
                                <div class="contacto-zone-filter-actions">
                                    <button
                                        v-if="sectionIsVisible('comparison')"
                                        type="button"
                                        @click="activeSection = 'comparison'"
                                    >
                                        Comparar
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="activeFilterCount === 0"
                                        @click="clearFilters"
                                    >
                                        Limpar
                                    </button>
                                </div>
                            </div>
                            <div class="contacto-zone-total">
                                <span>{{ localFilters.bar_group || 'Todas as zonas' }}</span>
                                <strong>{{ formatMoney(props.summary.total_sales) }}</strong>
                            </div>
                            <div class="contacto-zone-period">
                                <span class="contacto-label">Período</span>
                                <strong>{{ eventPeriodLabel }}</strong>
                                <span class="contacto-zone-hours">Todas as horas do evento</span>
                                <button type="button" @click="filtersOpen = true">Ajustar filtros</button>
                            </div>
                        </section>

                        <section class="contacto-stream-bar">
                            <div>
                                <span :class="{ 'is-processing': hasProcessingSync, 'is-failed': props.syncStatus.is_stale }" />
                                <strong>{{ hasProcessingSync ? 'Sincronização em curso' : 'Dados atualizados' }}</strong>
                                <small>Última atualização: {{ formatDateTime(props.summary.last_synced_at) }}</small>
                            </div>
                            <div class="contacto-stream-meta">
                                <span>{{ formatNumber(props.summary.filtered_rows) }} linhas analisadas</span>
                                <em v-if="props.autoSync.enabled">Próxima: {{ autoSyncCountdown }}</em>
                                <button
                                    v-if="props.previewMode"
                                    type="button"
                                    :disabled="isSyncingReport || hasProcessingSync"
                                    @click="submitReportSync"
                                >
                                    {{ hasProcessingSync ? 'A sincronizar' : isSyncingReport ? 'A iniciar' : 'Sincronizar agora' }}
                                </button>
                            </div>
                        </section>

                        <section class="contacto-kpi-mesh">
                            <button
                                v-if="isBlockVisible('overview')"
                                type="button"
                                class="contacto-kpi contacto-kpi-hero"
                                @click="openDetailModal('payments')"
                            >
                                <span>{{ blockLabel('overview', showZtCard ? 'Total sem ZT' : 'Faturação do evento') }}</span>
                                <strong>{{ formatMoney(props.paymentSummary.total_without_zt) }}</strong>
                                <small>Total do evento · dados reais sincronizados</small>
                            </button>

                            <button type="button" class="contacto-kpi contacto-kpi-ticket" @click="openDetailModal('ticket')">
                                <span>{{ metricLabel('average_ticket', 'Ticket médio') }}</span>
                                <strong>{{ formatMoney(props.summary.average_ticket) }}</strong>
                                <i><b :style="{ width: `${Math.min(100, Math.max(8, props.summary.average_ticket * 4))}%` }" /></i>
                                <small>Por transação</small>
                            </button>

                            <article class="contacto-kpi contacto-kpi-transactions">
                                <span>Transações</span>
                                <strong>{{ formatNumber(props.summary.tickets_count) }}</strong>
                                <small>{{ formatNumber(props.summary.total_quantity) }} unidades registadas</small>
                            </article>

                            <article class="contacto-leader-banner">
                                <div>
                                    <span class="contacto-leader-mark" />
                                    <p>
                                        <small>Zona líder</small>
                                        <strong>{{ leadingZone?.label || 'Sem dados' }}</strong>
                                    </p>
                                </div>
                                <p>
                                    <strong>{{ leadingZoneShare.toFixed(1).replace('.', ',') }}%</strong>
                                    <small>{{ leadingZone ? formatMoney(leadingZone.sales_total) : formatMoney(0) }} do total</small>
                                </p>
                            </article>
                        </section>

                        <section class="contacto-mini-stats">
                            <article>
                                <span>Total servido</span>
                                <strong>{{ formatNumber(props.summary.total_quantity) }} <small>un</small></strong>
                                <p>{{ formatNumber(props.summary.products_count) }} referências vendidas</p>
                            </article>
                            <article>
                                <span>Pico horário</span>
                                <strong>{{ primaryHourlyPeak?.hour_label || '—' }}</strong>
                                <p>{{ primaryHourlyPeak ? formatMoney(primaryHourlyPeak.sales_total) : 'Sem dados horários' }}</p>
                            </article>
                            <article>
                                <span>Média por hora</span>
                                <strong>{{ formatMoney(averageSalesPerHour) }}</strong>
                                <p>{{ formatNumber(activeOperatingHours) }} horas com vendas</p>
                            </article>
                            <article class="is-lime">
                                <span>{{ metricLabel('devices', 'Máquinas sincronizadas') }}</span>
                                <strong>{{ formatNumber(props.summary.machines_count) }}</strong>
                                <p>{{ formatNumber(props.summary.bar_groups_count) }} zonas operacionais</p>
                            </article>
                        </section>

                        <section class="contacto-analysis-grid">
                            <article class="contacto-panel contacto-hourly-panel">
                                <header>
                                    <div>
                                        <span class="contacto-label">Vendas por hora</span>
                                    </div>
                                    <div class="contacto-panel-controls">
                                        <span>Faturação e transações</span>
                                        <label v-if="hourlyDateOptions.length > 1">
                                            <span class="sr-only">Dia do evento</span>
                                            <select v-model="selectedHourlyDate">
                                                <option value="all">Todos os dias</option>
                                                <option v-for="option in hourlyDateOptions" :key="`summary-${option.date}`" :value="option.date">
                                                    {{ option.label }}
                                                </option>
                                            </select>
                                        </label>
                                        <button type="button" @click="hourlyPanelExpanded = !hourlyPanelExpanded">
                                            {{ hourlyPanelExpanded ? 'Ocultar' : 'Mostrar' }}
                                            <svg viewBox="0 0 12 8" fill="none" aria-hidden="true" :class="{ 'is-collapsed': !hourlyPanelExpanded }">
                                                <path d="m1 1 5 5 5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </button>
                                    </div>
                                </header>
                                <div
                                    v-if="props.hourlySales.length && hourlyPanelExpanded"
                                    class="contacto-hourly-chart"
                                    role="img"
                                    :aria-label="hourlyChartAriaLabel"
                                >
                                    <div class="contacto-hourly-legend" aria-hidden="true">
                                        <span class="is-sales">Faturação (€)</span>
                                        <span class="is-transactions">Transações</span>
                                    </div>
                                    <svg viewBox="0 0 760 250" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
                                        <g class="contacto-hourly-grid">
                                            <line
                                                v-for="tick in summaryHourlySalesTicks"
                                                :key="`sales-grid-${tick.y}`"
                                                x1="52"
                                                x2="712"
                                                :y1="tick.y"
                                                :y2="tick.y"
                                            />
                                        </g>
                                        <g class="contacto-hourly-y-axis is-sales-axis">
                                            <text
                                                v-for="tick in summaryHourlySalesTicks"
                                                :key="`sales-tick-${tick.y}`"
                                                x="45"
                                                :y="tick.y + 3"
                                                text-anchor="end"
                                            >{{ formatChartMoneyAxis(tick.value) }}</text>
                                        </g>
                                        <g class="contacto-hourly-y-axis is-ticket-axis">
                                            <text
                                                v-for="tick in summaryHourlyTicketTicks"
                                                :key="`ticket-tick-${tick.y}`"
                                                x="719"
                                                :y="tick.y + 3"
                                            >{{ formatChartCountAxis(tick.value) }}</text>
                                        </g>
                                        <g class="contacto-hourly-bars">
                                            <rect
                                                v-for="point in summaryHourlyChartPoints"
                                                :key="`summary-bar-${point.hour}`"
                                                :class="{ 'is-peak': point.is_peak }"
                                                :x="point.bar_x"
                                                :y="point.sales_y"
                                                :width="point.bar_width"
                                                :height="Math.max(point.sales_height, 1)"
                                                rx="2"
                                            >
                                                <title>{{ point.hour_label }} · {{ formatMoney(point.sales_total) }} · {{ formatNumber(point.tickets_count) }} transações</title>
                                            </rect>
                                        </g>
                                        <path :d="summaryHourlyTransactionPath" class="contacto-hourly-transaction-line" />
                                        <g class="contacto-hourly-transaction-points">
                                            <circle
                                                v-for="point in summaryHourlyChartPoints"
                                                :key="`summary-transaction-${point.hour}`"
                                                :cx="point.x"
                                                :cy="point.transaction_y"
                                                r="3"
                                            >
                                                <title>{{ point.hour_label }} · {{ formatNumber(point.tickets_count) }} transações</title>
                                            </circle>
                                        </g>
                                        <g class="contacto-hourly-x-axis">
                                            <text
                                                v-for="point in summaryHourlyAxisLabels"
                                                :key="`summary-hour-${point.hour}`"
                                                :x="point.x"
                                                y="239"
                                                text-anchor="middle"
                                            >{{ point.hour_label }}</text>
                                        </g>
                                    </svg>
                                </div>
                                <p v-else-if="!props.hourlySales.length" class="contacto-empty">Sem dados horários disponíveis.</p>
                            </article>

                            <article class="contacto-panel contacto-zone-ranking">
                                <header>
                                    <div>
                                        <span class="contacto-label">Desempenho por zona</span>
                                    </div>
                                    <div class="contacto-panel-controls">
                                        <span>Clique para filtrar</span>
                                        <button type="button" @click="zonePanelExpanded = !zonePanelExpanded">
                                            {{ zonePanelExpanded ? 'Ocultar' : 'Mostrar' }}
                                            <svg viewBox="0 0 12 8" fill="none" aria-hidden="true" :class="{ 'is-collapsed': !zonePanelExpanded }">
                                                <path d="m1 1 5 5 5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </button>
                                    </div>
                                </header>
                                <div v-if="zonePanelExpanded" class="contacto-zone-ranking-head" aria-hidden="true">
                                    <span>Zona</span><span>Faturação</span><span>Transações</span><span>Ticket médio</span><span>% total</span>
                                </div>
                                <button
                                    v-for="(zone, index) in leadingZoneRows"
                                    v-show="zonePanelExpanded"
                                    :key="`leader-${zone.label}`"
                                    type="button"
                                    class="contacto-zone-ranking-row"
                                    :class="{ 'is-active': localFilters.bar_group === getZoneFilterValue(zone.label) }"
                                    @click="applyBarGroupFilter(getZoneFilterValue(zone.label))"
                                >
                                    <span class="contacto-zone-ranking-name"><em>{{ index + 1 }}</em><i />{{ zone.label }}</span>
                                    <strong class="contacto-zone-ranking-metric" data-label="Faturação">{{ formatMoney(zone.totalSales) }}</strong>
                                    <span class="contacto-zone-ranking-metric" data-label="Transações">{{ formatNumber(zone.ticketsCount) }}</span>
                                    <span class="contacto-zone-ranking-metric" data-label="Ticket médio">{{ formatMoney(zone.averageTicket) }}</span>
                                    <b class="contacto-zone-ranking-share" data-label="% total">{{ props.summary.total_sales > 0 ? `${((zone.totalSales / props.summary.total_sales) * 100).toFixed(1).replace('.', ',')}%` : '0,0%' }}</b>
                                </button>
                            </article>
                        </section>

                        <section class="contacto-event-bests" aria-labelledby="contacto-event-bests-title">
                            <header>
                                <span class="contacto-label" id="contacto-event-bests-title">Os melhores do evento</span>
                                <small>Produto · zona · hora · pagamento</small>
                            </header>
                            <div>
                                <article>
                                    <span>Melhor produto</span>
                                    <strong>{{ bestProductBySales?.label || 'Sem dados' }}</strong>
                                    <b>{{ bestProductBySales ? formatMoney(bestProductBySales.sales_total) : formatMoney(0) }}</b>
                                    <small>Maior faturação por produto</small>
                                </article>
                                <article>
                                    <span>Mais servido</span>
                                    <strong>{{ mostServedProduct?.label || 'Sem dados' }}</strong>
                                    <b>{{ mostServedProduct ? `${formatNumber(mostServedProduct.quantity_total)} un` : '0 un' }}</b>
                                    <small>Maior quantidade registada</small>
                                </article>
                                <article>
                                    <span>Melhor zona</span>
                                    <strong>{{ leadingZone?.label || 'Sem dados' }}</strong>
                                    <b>{{ leadingZone ? formatMoney(leadingZone.sales_total) : formatMoney(0) }}</b>
                                    <small>{{ leadingZoneShare.toFixed(1).replace('.', ',') }}% do total</small>
                                </article>
                                <article>
                                    <span>Melhor hora</span>
                                    <strong>{{ primaryHourlyPeak?.hour_label || 'Sem dados' }}</strong>
                                    <b>{{ primaryHourlyPeak ? formatMoney(primaryHourlyPeak.sales_total) : formatMoney(0) }}</b>
                                    <small>{{ primaryHourlyPeak ? `${formatNumber(primaryHourlyPeak.tickets_count)} transações` : 'Sem vendas horárias' }}</small>
                                </article>
                                <article>
                                    <span>Melhor método</span>
                                    <strong>{{ bestPaymentMethod?.label || 'Sem dados' }}</strong>
                                    <b>{{ bestPaymentMethod ? formatMoney(bestPaymentMethod.value) : formatMoney(0) }}</b>
                                    <small>{{ bestPaymentMethod ? `${bestPaymentMethod.percentage.toFixed(1).replace('.', ',')}% dos pagamentos` : 'Sem pagamentos' }}</small>
                                </article>
                                <article>
                                    <span>Melhor dia</span>
                                    <strong>{{ bestEventDay?.label || 'Sem dados' }}</strong>
                                    <b>{{ bestEventDay ? formatMoney(bestEventDay.sales_total) : formatMoney(0) }}</b>
                                    <small>{{ bestEventDay ? `${formatNumber(bestEventDay.tickets_count)} transações` : 'Sem vendas diárias' }}</small>
                                </article>
                            </div>
                        </section>

                        <section class="contacto-summary-insights">
                            <article class="contacto-panel contacto-top-products-summary">
                                <header>
                                    <span class="contacto-label">Produtos mais vendidos</span>
                                    <small>Top 5 por faturação</small>
                                </header>
                                <div v-if="summaryTopProducts.length" class="contacto-top-products-table">
                                    <div class="contacto-top-products-head" aria-hidden="true">
                                        <span>Produto</span><span>Quantidade</span><span>Faturação</span><span>% total</span>
                                    </div>
                                    <article v-for="(product, index) in summaryTopProducts" :key="`summary-product-${product.code || product.label}`">
                                        <span class="contacto-top-product-name"><em>{{ index + 1 }}</em><strong>{{ product.label }}</strong><small>{{ product.code }}</small></span>
                                        <span data-label="Quantidade">{{ formatNumber(product.quantity_total) }}</span>
                                        <strong data-label="Faturação">{{ formatMoney(product.sales_total) }}</strong>
                                        <b data-label="% total">{{ props.summary.total_sales > 0 ? `${((product.sales_total / props.summary.total_sales) * 100).toFixed(1).replace('.', ',')}%` : '0,0%' }}</b>
                                    </article>
                                </div>
                                <p v-else class="contacto-empty">Sem produtos sincronizados.</p>
                            </article>

                            <article v-if="isBlockVisible('payments')" class="contacto-panel contacto-payment-distribution">
                                <header>
                                    <span class="contacto-label">{{ blockHelper('payments', 'Distribuição das vendas') }}</span>
                                    <small>Por método</small>
                                </header>
                                <div v-if="chartPaymentItems.length" class="contacto-payment-distribution-layout">
                                    <button
                                        type="button"
                                        class="contacto-payment-donut"
                                        :style="chartPaymentDonutStyle"
                                        :aria-label="chartPaymentAriaLabel"
                                        @click="openDetailModal('payments')"
                                    >
                                        <span>
                                            <small>Total</small>
                                            <strong>{{ formatMoney(chartPaymentTotal) }}</strong>
                                        </span>
                                    </button>
                                    <div class="contacto-payment-legend">
                                        <article v-for="payment in chartPaymentItems" :key="`summary-payment-${payment.key}`">
                                            <i :style="{ backgroundColor: payment.color }" />
                                            <span><strong>{{ payment.label }}</strong><small>{{ formatMoney(payment.value) }}</small></span>
                                            <b>{{ payment.percentage.toFixed(1).replace('.', ',') }}%</b>
                                        </article>
                                    </div>
                                </div>
                                <p v-else class="contacto-empty">Sem pagamentos sincronizados.</p>
                            </article>
                        </section>

                        <section class="contacto-summary-operations">
                            <article class="contacto-panel contacto-sync-feed">
                                <header>
                                    <span class="contacto-label">Atividade da sincronização</span>
                                    <small>Dados reais</small>
                                </header>
                                <div>
                                    <article>
                                        <i :class="{ 'is-failed': props.syncStatus.is_stale }" />
                                        <span>
                                            <strong>Última sincronização válida</strong>
                                            <small>{{ formatDateTime(props.syncStatus.last_success_at || props.summary.last_synced_at) }}</small>
                                        </span>
                                    </article>
                                    <article v-if="props.syncStatus.latest_attempt_at">
                                        <i :class="{ 'is-processing': hasProcessingSync, 'is-failed': props.syncStatus.is_stale }" />
                                        <span>
                                            <strong>{{ hasProcessingSync ? 'Tentativa em processamento' : 'Última tentativa registada' }}</strong>
                                            <small>{{ formatDateTime(props.syncStatus.latest_attempt_at) }}</small>
                                        </span>
                                    </article>
                                    <article>
                                        <i />
                                        <span>
                                            <strong>{{ autoSyncStatusLabel }}</strong>
                                            <small v-if="props.autoSync.enabled">Próxima verificação em {{ autoSyncCountdown }}</small>
                                            <small v-else>Sem nova sincronização agendada</small>
                                        </span>
                                    </article>
                                </div>
                            </article>

                            <article class="contacto-panel contacto-operational-alerts" :class="{ 'is-warning': props.syncStatus.is_stale }">
                                <header>
                                    <span class="contacto-label">Alertas operacionais</span>
                                    <small>Sincronização</small>
                                </header>
                                <div>
                                    <i :class="{ 'is-processing': hasProcessingSync, 'is-failed': props.syncStatus.is_stale }" />
                                    <span>
                                        <strong v-if="props.syncStatus.is_stale">A última tentativa não foi concluída</strong>
                                        <strong v-else-if="hasProcessingSync">A sincronização está em curso</strong>
                                        <strong v-else>Nenhum alerta crítico</strong>
                                        <small v-if="props.syncStatus.message">{{ props.syncStatus.message }}</small>
                                        <small v-else>Os valores apresentados pertencem à última importação válida.</small>
                                    </span>
                                </div>
                                <footer>
                                    <span>{{ formatNumber(props.summary.filtered_rows) }} linhas visíveis</span>
                                    <strong>{{ filteredCoveragePercentage.toFixed(0) }}% da seleção</strong>
                                </footer>
                            </article>
                        </section>

                        <section class="contacto-summary-details">

                            <article class="contacto-panel contacto-event-sheet">
                                <header>
                                    <span class="contacto-label">Ficha do evento</span>
                                    <small>Resumo</small>
                                </header>
                                <dl>
                                    <div><dt>Evento</dt><dd>{{ props.event.title }}</dd></div>
                                    <div><dt>Cliente</dt><dd>{{ props.event.client_business_name || props.event.client_name }}</dd></div>
                                    <div><dt>Período</dt><dd>{{ eventPeriodLabel }}</dd></div>
                                    <div><dt>Devices</dt><dd>{{ formatNumber(props.summary.machines_count) }}</dd></div>
                                    <div><dt>Zonas</dt><dd>{{ formatNumber(props.summary.bar_groups_count) }}</dd></div>
                                    <div><dt>Estado</dt><dd>{{ eventStatusLabel }}</dd></div>
                                </dl>
                            </article>

                            <article class="contacto-panel contacto-operational-state">
                                <header>
                                    <span class="contacto-label">Estado operacional</span>
                                    <small>Frota sincronizada</small>
                                </header>
                                <div>
                                    <article>
                                        <span>Sincronizadas</span>
                                        <strong>{{ formatNumber(props.summary.machines_count) }}</strong>
                                    </article>
                                    <article>
                                        <span>Processadas</span>
                                        <strong>{{ formatNumber(syncMachinesProcessed) }}</strong>
                                    </article>
                                    <article>
                                        <span>Pendentes</span>
                                        <strong>{{ formatNumber(syncMachinesPending) }}</strong>
                                    </article>
                                </div>
                                <footer>
                                    <span><i :style="{ width: `${syncCompletionPercentage}%` }" /></span>
                                    <small>{{ syncCompletionPercentage.toFixed(0) }}% das máquinas da última tentativa processadas</small>
                                </footer>
                            </article>
                        </section>
                    </div>

                    <div v-else-if="activeSection === 'charts'" class="report-dashboard-view report-dashboard-analytics-view">
                        <div class="report-dashboard-analytics-grid">
                            <section
                                v-if="showZtCard && isBlockVisible('chart_financial')"
                                class="dash-card report-dashboard-analytics-card report-dashboard-analytics-financial-card"
                                :style="blockStyle('chart_financial')"
                            >
                                <header>
                                    <div>
                                        <span>{{ blockHelper('chart_financial', 'Leitura financeira') }}</span>
                                        <h4>{{ blockLabel('chart_financial', 'Vendas e carregamentos ZT') }}</h4>
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
                                            <small>{{ metricLabel('total_with_zt', 'Total com ZT') }}</small>
                                            <strong>{{ formatMoney(props.paymentSummary.total_with_zt) }}</strong>
                                        </div>
                                    </div>
                                    <div class="report-dashboard-analytics-financial-legend">
                                        <article v-if="metricIsVisible('total_without_zt')">
                                            <i class="is-sales" />
                                            <span><small>{{ metricLabel('total_without_zt', 'Total sem ZT') }}</small><strong>{{ formatMoney(props.paymentSummary.total_without_zt) }}</strong></span>
                                            <em>{{ chartFinancialSalesPercentage.toFixed(1).replace('.', ',') }}%</em>
                                        </article>
                                        <article v-if="metricIsVisible('top_up_value')">
                                            <i class="is-zt" />
                                            <span><small>{{ metricLabel('top_up_value', 'Valor ZT') }}</small><strong>{{ formatMoney(props.paymentSummary.top_up_loaded) }}</strong></span>
                                            <em>{{ chartFinancialZtPercentage.toFixed(1).replace('.', ',') }}%</em>
                                        </article>
                                        <article v-if="metricIsVisible('other_movements')" class="is-outside-total">
                                            <i class="is-other" />
                                            <span><small>{{ metricLabel('other_movements', 'Outros movimentos') }}</small><strong>{{ formatMoney(props.paymentSummary.other_movements) }}</strong></span>
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
                                                <span v-if="metricIsVisible('spent')"><i class="is-spent" /> {{ metricLabel('spent', 'Valor gasto') }} <strong>{{ formatMoney(props.paymentSummary.top_up_spent) }}</strong></span>
                                                <span v-if="metricIsVisible('remaining')"><i class="is-remaining" /> {{ metricLabel('remaining', 'Remanescente') }} <strong>{{ formatMoney(props.paymentSummary.top_up_remaining) }}</strong></span>
                                            </footer>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section
                                v-if="isBlockVisible('chart_daily') && props.dailySales.length > 1"
                                class="dash-card report-dashboard-analytics-card report-dashboard-analytics-line-card"
                                :style="blockStyle('chart_daily')"
                            >
                                <header>
                                    <div>
                                        <span>{{ blockHelper('chart_daily', 'Gráfico de linha') }}</span>
                                        <h4>{{ blockLabel('chart_daily', 'Evolução diária da faturação') }}</h4>
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

                            <section
                                v-if="isBlockVisible('chart_hourly')"
                                class="dash-card report-dashboard-analytics-card report-dashboard-analytics-hourly-card"
                                :style="blockStyle('chart_hourly')"
                            >
                                <header>
                                    <div>
                                        <span>{{ blockHelper('chart_hourly', 'Gráfico de linha') }}</span>
                                        <h4>{{ blockLabel('chart_hourly', 'Picos de vendas por hora') }}</h4>
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

                            <section
                                v-if="isBlockVisible('chart_payments')"
                                class="dash-card report-dashboard-analytics-card report-dashboard-analytics-donut-card"
                                :style="blockStyle('chart_payments')"
                            >
                                <header>
                                    <div>
                                        <span>{{ blockHelper('chart_payments', 'Gráfico de pizza') }}</span>
                                        <h4>{{ blockLabel('chart_payments', 'Formas de pagamento') }}</h4>
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

                            <section
                                v-if="isBlockVisible('chart_zones')"
                                class="dash-card report-dashboard-analytics-card report-dashboard-analytics-bars-card"
                                :style="blockStyle('chart_zones')"
                            >
                                <header>
                                    <div>
                                        <span>{{ blockHelper('chart_zones', 'Gráfico de barras') }}</span>
                                        <h4>{{ blockLabel('chart_zones', 'Faturação por zona') }}</h4>
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

                            <section
                                v-if="isBlockVisible('chart_operations')"
                                class="dash-card report-dashboard-analytics-card report-dashboard-analytics-operational-card"
                                :style="blockStyle('chart_operations')"
                            >
                                <header>
                                    <div>
                                        <span>{{ blockHelper('chart_operations', 'Operação') }}</span>
                                        <h4>{{ blockLabel('chart_operations', 'Indicadores operacionais') }}</h4>
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
                                    <div v-if="chartOperationalMoneyMetrics.length" class="report-dashboard-analytics-operational-money">
                                        <article v-for="metric in chartOperationalMoneyMetrics" :key="metric.key">
                                            <span>{{ metric.label }}</span>
                                            <strong>{{ formatMoney(metric.key === 'average_ticket' ? props.summary.average_ticket : chartAveragePerDevice) }}</strong>
                                            <small>{{ metric.helper }}</small>
                                        </article>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>

                    <div v-else-if="activeSection === 'products'" class="contacto-menu-page">
                        <div class="contacto-menu-toolbar">
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
                            <label class="report-dashboard-search">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" />
                                    <path d="m16 16 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                                </svg>
                                <input v-model="productSearch" type="search" placeholder="Pesquisar produto..." />
                            </label>
                        </div>

                        <section class="contacto-menu-kpis is-four">
                            <article>
                                <span>Vendido</span>
                                <strong>{{ formatNumber(productSoldQuantity) }}</strong>
                                <small>Unidades pagas</small>
                            </article>
                            <article>
                                <span>Oferecido</span>
                                <strong>{{ formatNumber(productOfferedQuantity) }}</strong>
                                <small>Unidades com total zero</small>
                            </article>
                            <article>
                                <span>Total servido</span>
                                <strong>{{ formatNumber(productServedQuantity) }}</strong>
                                <small>Vendido + oferecido</small>
                            </article>
                            <article>
                                <span>Peso das ofertas</span>
                                <strong>{{ productOfferWeight.toFixed(1).replace('.', ',') }}%</strong>
                                <small>Do total servido</small>
                            </article>
                        </section>

                        <section class="contacto-menu-panel">
                            <header>
                                <div>
                                    <span>Ranking de produtos</span>
                                    <small>{{ formatNumber(visibleProducts.length) }} referências apresentadas</small>
                                </div>
                                <button type="button" @click="productSort = productSort === 'quantity' ? 'sales' : 'quantity'">
                                    {{ productSort === 'quantity' ? 'Ordenar por quantidade' : 'Ordenar por faturação' }}
                                </button>
                            </header>

                            <div class="contacto-menu-table-wrap">
                                <table class="contacto-menu-table">
                                    <thead>
                                        <tr>
                                            <th>Produto</th>
                                            <th>Referência</th>
                                            <th class="text-right">Vendido</th>
                                            <th class="text-right">Oferecido</th>
                                            <th class="text-right">Total servido</th>
                                            <th class="text-right">Valor faturado</th>
                                            <th class="text-right">% das vendas</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="product in visibleProducts" :key="`product-ranking-${product.code ?? product.label}`">
                                            <td><strong>{{ product.label }}</strong></td>
                                            <td>{{ product.code || '—' }}</td>
                                            <td class="text-right">{{ formatNumber(product.sold_quantity ?? product.quantity_total) }}</td>
                                            <td class="text-right">{{ formatNumber(product.offered_quantity ?? 0) }}</td>
                                            <td class="text-right contacto-menu-accent">{{ formatNumber(product.quantity_total) }}</td>
                                            <td class="text-right">{{ formatMoney(product.sales_total) }}</td>
                                            <td class="text-right">{{ getSalesShare(product.sales_total) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p v-if="!visibleProducts.length" class="report-dashboard-empty">Sem produtos para esta pesquisa.</p>
                        </section>

                        <section class="contacto-menu-panel contacto-product-bars">
                            <header>
                                <div>
                                    <span>Produtos mais servidos</span>
                                    <small>Unidades pagas e oferecidas</small>
                                </div>
                                <strong>Valor médio {{ formatMoney(averageProductValue) }}</strong>
                            </header>
                            <div class="contacto-product-bars-grid">
                                <article v-for="product in visibleProducts.slice(0, 8)" :key="`product-bar-${product.code ?? product.label}`">
                                    <div>
                                        <i class="is-sold" :style="{ height: getRatioWidth(product.sold_quantity ?? product.quantity_total, maxProductQuantity) }" />
                                        <i class="is-offered" :style="{ height: getRatioWidth(product.offered_quantity ?? 0, maxProductQuantity) }" />
                                    </div>
                                    <strong>{{ product.label }}</strong>
                                    <small>{{ formatNumber(product.quantity_total) }} un</small>
                                </article>
                            </div>
                        </section>
                    </div>

                    <div v-else-if="activeSection === 'zones'" class="contacto-menu-page">
                        <div class="contacto-menu-toolbar">
                            <div>
                                <span class="contacto-label">Seleção</span>
                                <strong>{{ selectedZoneLabel }}</strong>
                            </div>
                            <label class="report-dashboard-search">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" />
                                    <path d="m16 16 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                                </svg>
                                <input v-model="zoneSearch" type="search" placeholder="Pesquisar zona ou device..." />
                            </label>
                        </div>

                        <section class="contacto-zone-overview">
                            <article class="contacto-zone-total">
                                <span>Faturação da seleção</span>
                                <strong>{{ formatMoney(props.summary.total_sales) }}</strong>
                                <small>{{ selectedZoneShare.toFixed(1).replace('.', ',') }}% do total do evento</small>
                            </article>
                            <div class="contacto-zone-kpis">
                                <article>
                                    <span>Ticket médio</span>
                                    <strong>{{ formatMoney(props.summary.average_ticket) }}</strong>
                                    <small>Por transação na seleção</small>
                                </article>
                                <article>
                                    <span>Transações</span>
                                    <strong>{{ formatNumber(props.summary.tickets_count) }}</strong>
                                    <small>Documentos de venda</small>
                                </article>
                                <article>
                                    <span>Devices</span>
                                    <strong>{{ formatNumber(props.summary.stores_count) }}</strong>
                                    <small>Equipamentos com vendas</small>
                                </article>
                                <article class="is-accent">
                                    <span>Peso no evento</span>
                                    <strong>{{ selectedZoneShare.toFixed(1).replace('.', ',') }}%</strong>
                                    <small>{{ formatNumber(props.summary.bar_groups_count) }} zonas na seleção</small>
                                </article>
                            </div>
                        </section>

                        <section v-if="leadingZone" class="contacto-zone-leader">
                            <div><i /><span>Zona líder</span><strong>{{ leadingZone.label }}</strong></div>
                            <div><strong>{{ leadingZoneShare.toFixed(1).replace('.', ',') }}%</strong><small>{{ formatMoney(leadingZone.sales_total) }}</small></div>
                        </section>

                        <section class="contacto-menu-panel">
                            <header>
                                <div>
                                    <span>Desempenho por zona</span>
                                    <small>Clique numa zona para aplicar o filtro</small>
                                </div>
                                <strong>{{ formatMoney(props.summary.total_sales) }} total</strong>
                            </header>
                            <div class="contacto-menu-table-wrap">
                                <table class="contacto-menu-table">
                                    <thead>
                                        <tr>
                                            <th>Zona</th>
                                            <th class="text-right">Faturação</th>
                                            <th class="text-right">Transações</th>
                                            <th class="text-right">Devices</th>
                                            <th class="text-right">Ticket médio</th>
                                            <th class="text-right">% total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="zone in visibleZonePerformanceRows"
                                            :key="zone.label"
                                            class="is-clickable"
                                            @click="applyBarGroupFilter(getZoneFilterValue(zone.label))"
                                        >
                                            <td><strong>{{ zone.label }}</strong></td>
                                            <td class="text-right">{{ formatMoney(zone.totalSales) }}</td>
                                            <td class="text-right">{{ formatNumber(zone.ticketsCount) }}</td>
                                            <td class="text-right">{{ formatNumber(zone.devicesCount) }}</td>
                                            <td class="text-right">{{ formatMoney(zone.averageTicket) }}</td>
                                            <td class="text-right contacto-menu-accent">{{ getSalesShare(zone.totalSales) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section class="contacto-zone-devices">
                            <article v-for="zone in visibleZoneDevices" :key="zone.label">
                                <header><strong>{{ zone.label }}</strong><span>{{ formatNumber(zone.devices_count) }} devices</span></header>
                                <div v-for="item in zone.items" :key="`${zone.label}-${item.code ?? item.label}`">
                                    <span>{{ getDeviceLabel(item) }}</span><strong>{{ formatMoney(item.sales_total) }}</strong>
                                </div>
                            </article>
                        </section>
                    </div>

                    <div v-else-if="activeSection === 'reconciliation'" class="contacto-menu-page">
                        <section v-if="showZtCard" class="contacto-payment-overview">
                            <article class="contacto-payment-total">
                                <span>Carregamentos ZT - Card</span>
                                <strong>{{ formatMoney(props.paymentSummary.top_up_loaded) }}</strong>
                                <small>{{ formatNumber(props.paymentSummary.top_up_documents_count) }} carregamentos registados</small>
                            </article>
                            <div class="contacto-payment-kpis">
                                <article>
                                    <span>Carregamento médio</span>
                                    <strong>{{ formatMoney(averageTopUp) }}</strong>
                                    <small>Por cartão carregado</small>
                                </article>
                                <article class="is-accent">
                                    <span>Saldo por consumir</span>
                                    <strong>{{ formatMoney(props.paymentSummary.top_up_remaining) }}</strong>
                                    <small>Carregado menos gasto</small>
                                </article>
                                <article>
                                    <span>Valor gasto</span>
                                    <strong>{{ formatMoney(props.paymentSummary.top_up_spent) }}</strong>
                                    <small>Consumo ZT - Card</small>
                                </article>
                                <article>
                                    <span>Total com ZT</span>
                                    <strong>{{ formatMoney(props.paymentSummary.total_with_zt) }}</strong>
                                    <small>Vendas + carregamentos</small>
                                </article>
                            </div>
                        </section>

                        <section v-else class="contacto-payment-overview">
                            <article class="contacto-payment-total">
                                <span>Pagamentos das vendas</span>
                                <strong>{{ formatMoney(paymentMethodsTotal) }}</strong>
                                <small>{{ formatNumber(props.paymentSummary.documents_count) }} documentos</small>
                            </article>
                            <div class="contacto-payment-kpis">
                                <article><span>Multibanco</span><strong>{{ formatMoney(props.paymentSummary.multibanco) }}</strong><small>{{ getPaymentShare(props.paymentSummary.multibanco) }}</small></article>
                                <article><span>Dinheiro</span><strong>{{ formatMoney(props.paymentSummary.cash) }}</strong><small>{{ getPaymentShare(props.paymentSummary.cash) }}</small></article>
                                <article><span>Outros pagamentos</span><strong>{{ formatMoney(props.paymentSummary.other) }}</strong><small>{{ getPaymentShare(props.paymentSummary.other) }}</small></article>
                                <article class="is-accent"><span>Total faturado</span><strong>{{ formatMoney(props.summary.total_sales) }}</strong><small>Vendas sincronizadas</small></article>
                            </div>
                        </section>

                        <section class="contacto-menu-kpis contacto-payment-methods" :class="showZtCard ? 'is-four' : 'is-three'">
                            <article v-if="metricIsVisible('multibanco')">
                                <span>{{ metricLabel('multibanco', 'Multibanco') }}</span>
                                <strong>{{ formatMoney(props.paymentSummary.multibanco) }}</strong>
                                <small>{{ getPaymentShare(props.paymentSummary.multibanco) }}</small>
                            </article>
                            <article v-if="showZtCard && metricIsVisible('zticket')">
                                <span>{{ metricLabel('zticket', 'ZT - Card') }}</span>
                                <strong>{{ formatMoney(props.paymentSummary.zticket) }}</strong>
                                <small>{{ getPaymentShare(props.paymentSummary.zticket) }}</small>
                            </article>
                            <article v-if="metricIsVisible('cash')">
                                <span>{{ metricLabel('cash', 'Dinheiro') }}</span>
                                <strong>{{ formatMoney(props.paymentSummary.cash) }}</strong>
                                <small>{{ getPaymentShare(props.paymentSummary.cash) }}</small>
                            </article>
                            <article v-if="metricIsVisible('other_payments')">
                                <span>{{ metricLabel('other_payments', 'Outros pagamentos') }}</span>
                                <strong>{{ formatMoney(props.paymentSummary.other) }}</strong>
                                <small>{{ getPaymentShare(props.paymentSummary.other) }}</small>
                            </article>
                        </section>

                        <section class="contacto-menu-panel">
                            <header>
                                <div><span>Conciliação por device</span><small>{{ props.reconciliation.scope_note }}</small></div>
                                <label class="report-dashboard-search">
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8" /><path d="m16 16 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" /></svg>
                                    <input v-model="reconciliationSearch" type="search" placeholder="Procurar device..." />
                                </label>
                            </header>
                            <div v-if="props.reconciliation.available" class="contacto-menu-table-wrap">
                                <table class="contacto-menu-table">
                                    <thead>
                                        <tr>
                                            <th>Device</th>
                                            <th v-if="metricIsVisible('multibanco')" class="text-right">{{ metricLabel('multibanco', 'Multibanco') }}</th>
                                            <th v-if="showZtCard && metricIsVisible('zticket')" class="text-right">{{ metricLabel('zticket', 'ZT - Card') }}</th>
                                            <th v-if="metricIsVisible('cash')" class="text-right">{{ metricLabel('cash', 'Dinheiro') }}</th>
                                            <th v-if="metricIsVisible('other_payments')" class="text-right">Outros</th>
                                            <th class="text-right">Pagamentos</th>
                                            <th class="text-right">Vendas</th>
                                            <th class="text-right">Diferença</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="item in visibleReconciliationItems" :key="item.store_code ?? item.store_name">
                                            <td><strong>{{ item.store_name }}</strong><small>{{ item.store_code || 'Sem código' }}</small></td>
                                            <td v-if="metricIsVisible('multibanco')" class="text-right">{{ formatMoney(item.multibanco) }}</td>
                                            <td v-if="showZtCard && metricIsVisible('zticket')" class="text-right">{{ formatMoney(item.zticket) }}</td>
                                            <td v-if="metricIsVisible('cash')" class="text-right">{{ formatMoney(item.cash) }}</td>
                                            <td v-if="metricIsVisible('other_payments')" class="text-right">{{ formatMoney(item.other) }}</td>
                                            <td class="text-right contacto-menu-accent">{{ formatMoney(item.payments_total) }}</td>
                                            <td class="text-right">{{ formatMoney(item.sales_total) }}</td>
                                            <td class="text-right"><span :class="['report-dashboard-difference-pill', getDifferenceClass(item.difference)]">{{ item.difference === null ? '—' : formatMoney(item.difference) }}</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p v-else class="report-dashboard-empty">A sincronização atual não contém documentos de pagamento.</p>
                        </section>
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

                    <div v-else-if="activeSection === 'highlights'" class="contacto-menu-page">
                        <section class="contacto-performance-chart">
                            <header>
                                <div>
                                    <span>Produtos mais servidos</span>
                                    <small>Vendido e oferecido por produto</small>
                                </div>
                                <strong>Top: {{ mostServedProduct?.label || 'Sem dados' }}</strong>
                            </header>
                            <div class="contacto-performance-bars" role="img" aria-label="Produtos vendidos e oferecidos">
                                <article v-for="product in activeProducts.slice(0, 8)" :key="`performance-product-${product.code ?? product.label}`">
                                    <div>
                                        <i class="is-sold" :style="{ height: getRatioWidth(product.sold_quantity ?? product.quantity_total, maxProductQuantity) }" />
                                        <i class="is-offered" :style="{ height: getRatioWidth(product.offered_quantity ?? 0, maxProductQuantity) }" />
                                    </div>
                                    <strong>{{ product.label }}</strong>
                                    <small>{{ formatNumber(product.quantity_total) }} un</small>
                                </article>
                            </div>
                            <footer><span><i class="is-sold" /> Vendido</span><span><i class="is-offered" /> Oferecido</span></footer>
                        </section>

                        <section class="contacto-menu-kpis is-four contacto-performance-kpis">
                            <article><span>Melhor produto</span><strong>{{ bestProductBySales?.label || '—' }}</strong><small>{{ bestProductBySales ? formatMoney(bestProductBySales.sales_total) : formatMoney(0) }}</small></article>
                            <article><span>Produto mais servido</span><strong>{{ mostServedProduct?.label || '—' }}</strong><small>{{ mostServedProduct ? `${formatNumber(mostServedProduct.quantity_total)} unidades` : 'Sem dados' }}</small></article>
                            <article><span>Pico horário</span><strong>{{ primaryHourlyPeak?.hour_label || '—' }}</strong><small>{{ primaryHourlyPeak ? formatMoney(primaryHourlyPeak.sales_total) : 'Sem vendas' }}</small></article>
                            <article class="is-accent"><span>Zona líder</span><strong>{{ leadingZone?.label || '—' }}</strong><small>{{ leadingZone ? formatMoney(leadingZone.sales_total) : 'Sem dados' }}</small></article>
                        </section>

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
                                <span v-if="metricIsVisible('multibanco')">{{ metricLabel('multibanco', 'Multibanco') }}</span><strong v-if="metricIsVisible('multibanco')">{{ formatMoney(day.multibanco) }}</strong>
                                <span v-if="showZtPaymentDetails && metricIsVisible('zticket')">{{ metricLabel('zticket', 'ZT - Card') }}</span><strong v-if="showZtPaymentDetails && metricIsVisible('zticket')">{{ formatMoney(day.zticket) }}</strong>
                                <span v-if="metricIsVisible('cash')">{{ metricLabel('cash', 'Dinheiro') }}</span><strong v-if="metricIsVisible('cash')">{{ formatMoney(day.cash) }}</strong>
                                <span v-if="day.other !== 0 && metricIsVisible('other_payments')">{{ metricLabel('other_payments', 'Outros pagamentos') }}</span><strong v-if="day.other !== 0 && metricIsVisible('other_payments')">{{ formatMoney(day.other) }}</strong>
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
                                <span v-if="metricIsVisible('loaded')">{{ metricLabel('loaded', 'Valor carregado') }}</span><strong v-if="metricIsVisible('loaded')">{{ formatMoney(day.top_up_loaded) }}</strong>
                                <span v-if="metricIsVisible('spent')">{{ metricLabel('spent', 'Valor gasto') }}</span><strong v-if="metricIsVisible('spent')">{{ formatMoney(day.top_up_spent) }}</strong>
                                <span v-if="metricIsVisible('remaining')">{{ metricLabel('remaining', 'Remanescente') }}</span><strong v-if="metricIsVisible('remaining')">{{ formatMoney(day.top_up_remaining) }}</strong>
                                <span v-if="metricIsVisible('top_up_count')">{{ metricLabel('top_up_count', 'Carregamentos ZT') }}</span><strong v-if="metricIsVisible('top_up_count')">{{ formatNumber(day.top_up_documents_count) }}</strong>
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
                            <span>{{ metricLabel('average_ticket', 'Ticket médio') }} geral</span>
                            <strong>{{ formatMoney(props.summary.average_ticket) }}</strong>
                        </div>
                    </template>
                </div>

                <p v-else class="report-dashboard-detail-empty">Não existem dados diários sincronizados para este evento.</p>
            </section>
        </div>

    </AuthenticatedLayout>
</template>
