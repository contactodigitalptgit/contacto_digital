<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { showErrorToast, showSuccessToast } from '@/lib/swal';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

type DashboardSection = 'summary' | 'products' | 'zones' | 'reconciliation' | 'comparison';

interface EventMeta {
    id: number;
    title: string;
    description: string | null;
    event_date: string;
    client_name: string;
    client_business_name: string | null;
    processing_imports_count: number;
    last_synced_at: string | null;
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

const props = defineProps<{
    event: EventMeta;
    summary: EventSummary;
    barGroups: BarGroupItem[];
    topProducts: BreakdownItem[];
    productBreakdowns: ProductBreakdowns;
    dailySales: DailySale[];
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
];

const activeSection = ref<DashboardSection>('summary');
const selectedProductView = ref('total');
const filtersOpen = ref(false);
const isSyncingReport = ref(false);
const syncIntegrationError = ref('');
const dashboardPollerId = ref<number | null>(null);
const autoSyncClockId = ref<number | null>(null);
const currentTimestamp = ref(Date.now());
const lastAutoSyncRefreshAt = ref(0);
const isRefreshingAutoSync = ref(false);
const localFilters = ref<DashboardFilters>({ ...props.filters });

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

const paymentCards = computed<MetricCard[]>(() => [
    {
        label: 'Multibanco',
        value: formatMoney(props.paymentSummary.multibanco),
        helper: getPaymentShare(props.paymentSummary.multibanco),
    },
    {
        label: 'ZT - Card',
        value: formatMoney(props.paymentSummary.zticket),
        helper: getPaymentShare(props.paymentSummary.zticket),
    },
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
]);

const movementCards = computed<MetricCard[]>(() => [
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
]);

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
const productColumns = computed(() => {
    const midpoint = Math.ceil(activeProducts.value.length / 2);

    return [
        activeProducts.value.slice(0, midpoint),
        activeProducts.value.slice(midpoint),
    ].filter((column) => column.length > 0);
});
const maxProductQuantity = computed(() =>
    activeProducts.value.reduce((max, item) => Math.max(max, item.quantity_total), 0),
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
        document.addEventListener('visibilitychange', refreshAutoSyncOnFocus);
    }
});

onBeforeUnmount(() => {
    stopDashboardPolling();

    if (autoSyncClockId.value !== null && typeof window !== 'undefined') {
        window.clearInterval(autoSyncClockId.value);
        window.removeEventListener('focus', refreshAutoSyncOnFocus);
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
        + props.paymentSummary.zticket
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
            </section>
        </template>

        <template #header>
            <div class="report-dashboard-toolbar">
                <div>
                    <h2 class="dash-page-title">{{ props.event.title }}</h2>
                    <p class="report-dashboard-toolbar-subtitle">{{ brandSubtitle }}</p>
                </div>

                <div class="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center">
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

                    <Link :href="props.backUrl" class="dash-link-button">
                        {{ props.backLabel }}
                    </Link>
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
                    <section class="report-dashboard-commandbar">
                        <div>
                            <span class="report-dashboard-commandbar-kicker">Evento atual</span>
                            <strong>{{ props.event.title }}</strong>
                            <small>{{ formatDate(props.event.event_date) }}</small>
                        </div>

                        <div class="report-dashboard-commandbar-actions">
                            <div class="report-dashboard-sync-countdown">
                                <span>{{ hasProcessingSync ? 'Sincronização em curso' : 'Próxima sincronização' }}</span>
                                <strong>{{ autoSyncCountdown }}</strong>
                            </div>

                            <button
                                type="button"
                                class="report-dashboard-filter-button"
                                :class="{ 'is-active': filtersOpen || activeFilterCount > 0 }"
                                @click="filtersOpen = !filtersOpen"
                            >
                                Filtros
                                <span v-if="activeFilterCount > 0">{{ activeFilterCount }}</span>
                            </button>
                        </div>
                    </section>

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

                    <div v-if="activeSection === 'summary'" class="report-dashboard-view">
                        <section class="report-dashboard-overview-hero">
                            <div class="report-dashboard-overview-copy">
                                <span>Total sem ZT</span>
                                <strong>{{ formatMoney(props.paymentSummary.total_without_zt) }}</strong>
                                <div class="report-dashboard-day-list">
                                    <span v-for="day in props.dailySales" :key="day.date">
                                        {{ day.label }}
                                        <strong>{{ formatMoney(day.sales_total) }}</strong>
                                    </span>
                                </div>
                            </div>

                            <div class="report-dashboard-overview-meta">
                                <span>Última sincronização</span>
                                <strong>{{ formatDateTime(props.summary.last_synced_at) }}</strong>
                                <small>{{ formatNumber(props.summary.filtered_rows) }} linhas analisadas</small>
                            </div>
                        </section>

                        <section>
                            <div class="report-dashboard-section-heading">
                                <span>Leitura financeira</span>
                                <h3>Vendas e carregamentos ZT</h3>
                            </div>
                            <div class="report-dashboard-grid report-dashboard-grid-5">
                                <article
                                    v-for="card in movementCards"
                                    :key="card.label"
                                    class="report-dashboard-movement-card"
                                    :class="{ 'is-total': card.label === 'Total com ZT' }"
                                >
                                    <span>{{ card.label }}</span>
                                    <strong>{{ card.value }}</strong>
                                    <small>{{ card.helper }}</small>
                                </article>
                            </div>
                        </section>

                        <section>
                            <div class="report-dashboard-section-heading">
                                <span>Formas de pagamento</span>
                                <h3>Pagamentos das vendas sem ZT</h3>
                            </div>
                            <div class="report-dashboard-grid report-dashboard-grid-4">
                                <article
                                    v-for="(card, index) in paymentCards"
                                    :key="card.label"
                                    class="report-dashboard-metric-card"
                                    :class="{ 'is-featured': index === 0 }"
                                >
                                    <span>{{ card.label }}</span>
                                    <strong>{{ card.value }}</strong>
                                    <small>{{ card.helper }}</small>
                                </article>
                            </div>
                        </section>

                        <section class="report-dashboard-topup">
                            <div class="report-dashboard-section-heading">
                                <span>Fluxo de cartões</span>
                                <h3>Top-Up ZT - Card</h3>
                            </div>
                            <div class="report-dashboard-topup-flow">
                                <article v-for="(card, index) in topUpCards" :key="card.label">
                                    <span>{{ String(index + 1).padStart(2, '0') }}</span>
                                    <div>
                                        <small>{{ card.label }}</small>
                                        <strong>{{ card.value }}</strong>
                                        <p>{{ card.helper }}</p>
                                    </div>
                                </article>
                            </div>
                        </section>

                        <section>
                            <div class="report-dashboard-section-heading">
                                <span>Operação</span>
                                <h3>Indicadores operacionais</h3>
                            </div>
                            <div class="report-dashboard-grid report-dashboard-grid-5">
                                <article v-for="card in summaryCards" :key="card.label" class="report-dashboard-summary-card">
                                    <span>{{ card.label }}</span>
                                    <strong>{{ card.value }}</strong>
                                    <small>{{ card.helper }}</small>
                                </article>
                            </div>
                        </section>
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
                            <div v-if="activeProducts.length" class="report-dashboard-products-grid">
                                <div v-for="(column, columnIndex) in productColumns" :key="columnIndex" class="space-y-4">
                                    <article
                                        v-for="(product, productIndex) in column"
                                        :key="`${columnIndex}-${product.code ?? product.label}`"
                                        class="report-dashboard-product-row"
                                    >
                                        <span class="report-dashboard-rank" :class="{ 'is-top': columnIndex * Math.ceil(activeProducts.length / 2) + productIndex < 3 }">
                                            {{ columnIndex * Math.ceil(activeProducts.length / 2) + productIndex + 1 }}
                                        </span>
                                        <div>
                                            <div class="report-dashboard-product-head">
                                                <span>{{ product.label }}</span>
                                                <strong>{{ formatNumber(product.quantity_total) }}</strong>
                                            </div>
                                            <div class="report-dashboard-product-track">
                                                <span class="report-dashboard-product-fill" :style="{ width: getRatioWidth(product.quantity_total, maxProductQuantity) }" />
                                            </div>
                                            <small>{{ formatMoney(product.sales_total) }} faturados</small>
                                        </div>
                                    </article>
                                </div>
                            </div>
                            <p v-else class="report-dashboard-empty">Sem produtos para este período.</p>
                        </section>
                    </div>

                    <div v-else-if="activeSection === 'zones'" class="report-dashboard-view">
                        <header class="report-dashboard-view-header">
                            <div>
                                <span>Operação no recinto</span>
                                <h3>Resumo e performance por zona</h3>
                                <p>Faturação e detalhe dos devices agrupados pelas zonas reais do relatório.</p>
                            </div>
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
                                    <tr v-for="zone in zonePerformanceRows" :key="zone.label">
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
                                <article v-for="zone in props.zoneDevices" :key="zone.label" class="report-dashboard-zone-card">
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
                        </header>

                        <section class="report-dashboard-grid report-dashboard-grid-4">
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
                            <article class="report-dashboard-summary-card">
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
                                        <th class="text-right">ZT - Card</th>
                                        <th class="text-right">Dinheiro</th>
                                        <th class="text-right">Outros</th>
                                        <th class="text-right">Pagamentos</th>
                                        <th class="text-right">Vendas</th>
                                        <th class="text-right">Diferença</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in props.reconciliation.items" :key="item.store_code ?? item.store_name">
                                        <td>
                                            <strong>{{ item.store_code || '—' }}</strong>
                                            <small>{{ item.store_name }}</small>
                                        </td>
                                        <td class="text-right">{{ formatMoney(item.multibanco) }}</td>
                                        <td class="text-right">{{ formatMoney(item.zticket) }}</td>
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

                    <div v-else class="report-dashboard-view">
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
                                        <tr v-for="payment in props.comparison.payments" :key="payment.key">
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
                        </template>
                    </div>
                </main>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
