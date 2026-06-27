<script setup lang="ts">
import { showErrorToast, showSuccessToast } from '@/lib/swal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

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

interface SalesdayTotals {
    fs: number;
    ft: number;
    tk: number;
    vd: number;
    enc: number;
    nc: number;
    rc: number;
    movimento: number;
    num: number;
    deb: number;
    crd: number;
    chq: number;
    cartoes: number;
    etk: number;
}

interface SalesdaySummary {
    available: boolean;
    records_count: number;
    stores_count: number;
    days_count: number;
    cash_registers_count: number;
    closed_records_count: number;
    open_records_count: number;
    totals: SalesdayTotals;
    warnings_count: number;
    scope_note: string;
}

interface PaymentSummary {
    available: boolean;
    source: string;
    documents_count: number;
    multibanco: number;
    cash: number;
    zticket: number;
    other: number;
    top_up_loaded: number;
    top_up_spent: number;
    top_up_remaining: number;
    scope_note: string;
}

interface MetricCard {
    label: string;
    value: string;
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
    salesday: SalesdaySummary;
    paymentSummary: PaymentSummary;
    zoneDevices: ZoneDeviceGroup[];
    previewMode?: boolean;
    backUrl: string;
    backLabel: string;
}>();

const isSyncingReport = ref(false);
const syncIntegrationError = ref('');
const dashboardPollerId = ref<number | null>(null);

const hasImportedData = computed(() => props.summary.total_rows > 0);
const hasProcessingSync = computed(
    () => props.previewMode === true && props.summary.processing_imports_count > 0,
);

const brandSubtitle = computed(() => {
    const parts = [
        props.event.client_name,
        formatDate(props.event.event_date),
    ].filter(Boolean);

    return parts.join(' - ');
});

const paymentCards = computed<MetricCard[]>(() => {
    const multibanco = roundCurrency(props.paymentSummary.multibanco);
    const zticketPurchases = roundCurrency(props.paymentSummary.zticket);
    const cash = roundCurrency(props.paymentSummary.cash);

    return [
        {
            label: 'Valor Total Faturado',
            value: formatMoney(props.summary.total_sales),
        },
        {
            label: 'Multibanco',
            value: formatMoney(multibanco),
        },
        {
            label: 'Compra ZTicket',
            value: formatMoney(zticketPurchases),
        },
        {
            label: 'Dinheiro',
            value: formatMoney(cash),
        },
    ];
});

const topUpLoaded = computed(() =>
    props.paymentSummary.available
        ? roundCurrency(props.paymentSummary.top_up_loaded)
        : (props.barGroups.find((group) => group.label === 'Top Up')?.sales_total ?? 0),
);

const topUpSpent = computed(() =>
    props.paymentSummary.available
        ? roundCurrency(props.paymentSummary.top_up_spent)
        : roundCurrency(props.salesday.totals.etk),
);

const topUpRemaining = computed(() =>
    props.paymentSummary.available
        ? roundCurrency(props.paymentSummary.top_up_remaining)
        : roundCurrency(Math.max(topUpLoaded.value - topUpSpent.value, 0)),
);

const topUpCards = computed<MetricCard[]>(() => [
    {
        label: 'Valor Carregado',
        value: formatMoney(topUpLoaded.value),
    },
    {
        label: 'Valor Gasto em Bloom Card',
        value: formatMoney(topUpSpent.value),
    },
    {
        label: 'Remanescente',
        value: formatMoney(topUpRemaining.value),
    },
]);

const summaryCards = computed<MetricCard[]>(() => {
    const averagePerZone =
        props.summary.bar_groups_count > 0
            ? roundCurrency(props.summary.total_sales / props.summary.bar_groups_count)
            : 0;

    return [
        {
            label: 'Total Devices',
            value: formatNumber(props.summary.stores_count),
        },
        {
            label: 'Zonas',
            value: formatNumber(props.summary.bar_groups_count),
        },
        {
            label: 'Ticket Médio',
            value: formatMoney(props.summary.average_ticket),
        },
        {
            label: 'Tickets',
            value: formatNumber(props.summary.tickets_count),
        },
        {
            label: 'Média Geral',
            value: formatMoney(averagePerZone),
        },
    ];
});

const productColumns = computed(() => {
    const midpoint = Math.ceil(props.topProducts.length / 2);

    return [
        props.topProducts.slice(0, midpoint),
        props.topProducts.slice(midpoint),
    ].filter((column) => column.length > 0);
});

const maxProductQuantity = computed(() =>
    props.topProducts.reduce((max, item) => Math.max(max, item.quantity_total), 0),
);

const maxZoneAverage = computed(() =>
    props.barGroups.reduce((max, group) => {
        const average = group.stores_count > 0 ? group.sales_total / group.stores_count : 0;

        return Math.max(max, average);
    }, 0),
);

const zonePerformanceRows = computed<ZonePerformanceRow[]>(() =>
    props.barGroups.map((group) => {
        const averageSales = group.stores_count > 0
            ? roundCurrency(group.sales_total / group.stores_count)
            : 0;

        return {
            label: group.label,
            totalSales: group.sales_total,
            devicesCount: group.stores_count,
            averageSales,
            performanceWidth: getRatioWidth(averageSales, maxZoneAverage.value),
        };
    }),
);

const salesdayCards = computed<MetricCard[]>(() => [
    {
        label: 'Registos Salesday',
        value: formatNumber(props.salesday.records_count),
    },
    {
        label: 'Dias Cobertos',
        value: formatNumber(props.salesday.days_count),
    },
    {
        label: 'Caixas',
        value: formatNumber(props.salesday.cash_registers_count),
    },
    {
        label: 'Fechos',
        value: formatNumber(props.salesday.closed_records_count),
    },
    {
        label: 'FS',
        value: formatMoney(props.salesday.totals.fs),
    },
    {
        label: 'FT',
        value: formatMoney(props.salesday.totals.ft),
    },
    {
        label: 'TK',
        value: formatMoney(props.salesday.totals.tk),
    },
    {
        label: 'VD',
        value: formatMoney(props.salesday.totals.vd),
    },
]);

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
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 5000);
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

const submitReportSync = () => {
    if (!props.previewMode || isSyncingReport.value || hasProcessingSync.value) {
        return;
    }

    isSyncingReport.value = true;
    syncIntegrationError.value = '';

    router.post(
        route('admin.events.reports.store', props.event.id),
        {
            redirect_to: getCurrentDashboardUrl(),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                void showSuccessToast('Sincronizacao iniciada. O dashboard vai atualizar automaticamente.');
            },
            onError: (errors) => {
                syncIntegrationError.value =
                    (errors.integration as string | undefined) ?? '';

                if (!syncIntegrationError.value) {
                    void showErrorToast('Nao foi possivel iniciar a sincronizacao.');
                }
            },
            onFinish: () => {
                isSyncingReport.value = false;
            },
        },
    );
};

watch(
    hasProcessingSync,
    (processing) => {
        if (processing) {
            startDashboardPolling();

            return;
        }

        stopDashboardPolling();
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    stopDashboardPolling();
});

function formatDateTime(value: string | null) {
    return value
        ? new Intl.DateTimeFormat('pt-PT', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : 'Sem data';
}

function formatDate(value: string | null) {
    return value
        ? new Intl.DateTimeFormat('pt-PT', {
              dateStyle: 'long',
          }).format(new Date(value))
        : 'Sem data';
}

function formatMoney(value: number) {
    return new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency: 'EUR',
    }).format(value);
}

function formatNumber(value: number) {
    return new Intl.NumberFormat('pt-PT', {
        maximumFractionDigits: value % 1 === 0 ? 0 : 2,
    }).format(value);
}

function roundCurrency(value: number) {
    return Number.parseFloat(value.toFixed(4));
}

function getRatioWidth(value: number, maxValue: number) {
    if (value <= 0 || maxValue <= 0) {
        return '0%';
    }

    return `${Math.max(8, Math.round((value / maxValue) * 100))}%`;
}

function getDeviceLabel(item: BreakdownItem) {
    return item.code ? `${item.code} - ${item.label}` : item.label;
}
</script>

<template>
    <Head :title="`Evento - ${props.event.title}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="report-dashboard-toolbar">
                <div>
                    <h2 class="dash-page-title">
                        {{ props.event.title }}
                    </h2>
                    <p class="report-dashboard-toolbar-subtitle">
                        {{ brandSubtitle }}
                    </p>
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

                    <Link
                        :href="props.backUrl"
                        class="dash-link-button"
                    >
                        {{ props.backLabel }}
                    </Link>
                </div>
            </div>
        </template>

        <div class="dash-page space-y-6">
            <section
                v-if="!hasImportedData"
                class="dash-card report-dashboard-empty"
            >
                Nenhum relatório sincronizado para este evento.
            </section>

            <template v-else>
                <section class="report-dashboard-brand">
                    <div>
                        <p class="report-dashboard-brand-kicker">Contacto Digital Reporting</p>
                        <h3 class="report-dashboard-brand-title">{{ props.event.title }}</h3>
                        <p class="report-dashboard-brand-subtitle">{{ brandSubtitle }}</p>
                        <p class="report-dashboard-brand-description">
                            {{
                                props.event.description
                                    || 'Relatório com dados de vendas e operação do evento, para apoio à leitura comercial e operacional.'
                            }}
                        </p>
                        <p class="report-dashboard-brand-note">
                            Informação confidencial - uso interno e exclusivo para análise e planeamento.
                        </p>
                    </div>

                    <div class="report-dashboard-brand-meta">
                        <div class="report-dashboard-brand-meta-card">
                            <span>Última sincronização</span>
                            <strong>{{ formatDateTime(props.summary.last_synced_at) }}</strong>
                        </div>
                        <div class="report-dashboard-brand-meta-card">
                            <span>Máquinas sincronizadas</span>
                            <strong>{{ formatNumber(props.summary.machines_count) }}</strong>
                        </div>
                        <div class="report-dashboard-brand-meta-card">
                            <span>Estado</span>
                            <strong>
                                {{
                                    hasProcessingSync
                                        ? 'Sincronização em curso'
                                        : 'Snapshot pronto'
                                }}
                            </strong>
                        </div>
                    </div>
                </section>

                <p
                    v-if="props.previewMode && hasProcessingSync"
                    class="report-dashboard-inline-note"
                >
                    Sincronização em curso. O dashboard atualiza automaticamente.
                </p>
                <p
                    v-else-if="syncIntegrationError"
                    class="dash-modal-error"
                >
                    {{ syncIntegrationError }}
                </p>

                <section class="report-dashboard-section">
                    <div class="report-dashboard-grid report-dashboard-grid-4">
                        <article
                            v-for="card in paymentCards"
                            :key="card.label"
                            class="report-dashboard-metric-card report-dashboard-metric-card-strong"
                        >
                            <span class="report-dashboard-metric-label">{{ card.label }}</span>
                            <strong class="report-dashboard-metric-value">{{ card.value }}</strong>
                        </article>
                    </div>
                </section>

                <section class="report-dashboard-section">
                    <div class="report-dashboard-section-header">
                        <h3 class="report-dashboard-section-title">TopUp Bloom Card</h3>
                    </div>

                    <div class="report-dashboard-grid report-dashboard-grid-3">
                        <article
                            v-for="card in topUpCards"
                            :key="card.label"
                            class="report-dashboard-metric-card"
                        >
                            <span class="report-dashboard-metric-label">{{ card.label }}</span>
                            <strong class="report-dashboard-metric-value">{{ card.value }}</strong>
                        </article>
                    </div>
                </section>

                <section class="report-dashboard-section">
                    <div class="report-dashboard-grid report-dashboard-grid-5">
                        <article
                            v-for="card in summaryCards"
                            :key="card.label"
                            class="report-dashboard-summary-card"
                        >
                            <span class="report-dashboard-summary-label">{{ card.label }}</span>
                            <strong class="report-dashboard-summary-value">{{ card.value }}</strong>
                        </article>
                    </div>
                </section>

                <section class="report-dashboard-section">
                    <div class="report-dashboard-section-header">
                        <h3 class="report-dashboard-section-title">Produtos Mais Vendidos</h3>
                    </div>

                    <div class="dash-card report-dashboard-panel">
                        <div class="report-dashboard-products-grid">
                            <div
                                v-for="(column, columnIndex) in productColumns"
                                :key="columnIndex"
                                class="space-y-3"
                            >
                                <article
                                    v-for="(product, productIndex) in column"
                                    :key="`${columnIndex}-${product.label}`"
                                    class="report-dashboard-product-row"
                                >
                                    <span
                                        class="report-dashboard-rank"
                                        :class="{ 'is-top': columnIndex * Math.ceil(props.topProducts.length / 2) + productIndex < 3 }"
                                    >
                                        {{ columnIndex * Math.ceil(props.topProducts.length / 2) + productIndex + 1 }}
                                    </span>

                                    <div class="flex-1">
                                        <div class="report-dashboard-product-head">
                                            <span>{{ product.label }}</span>
                                            <strong>{{ formatNumber(product.quantity_total) }}</strong>
                                        </div>

                                        <div class="report-dashboard-product-track">
                                            <span
                                                class="report-dashboard-product-fill"
                                                :style="{ width: getRatioWidth(product.quantity_total, maxProductQuantity) }"
                                            />
                                        </div>
                                    </div>
                                </article>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="report-dashboard-section">
                    <div class="report-dashboard-section-header">
                        <h3 class="report-dashboard-section-title">Resumo &amp; Performance por Zona</h3>
                    </div>

                    <div class="dash-card report-dashboard-table-shell">
                        <table class="dash-table report-dashboard-table">
                            <thead>
                                <tr>
                                    <th>Zona</th>
                                    <th class="text-right">Total (€)</th>
                                    <th class="text-right">Devices</th>
                                    <th class="text-right">Média (€)</th>
                                    <th>Performance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="zone in zonePerformanceRows"
                                    :key="zone.label"
                                >
                                    <td class="report-dashboard-table-title">{{ zone.label }}</td>
                                    <td class="text-right">{{ formatMoney(zone.totalSales) }}</td>
                                    <td class="text-right">{{ formatNumber(zone.devicesCount) }}</td>
                                    <td class="text-right">{{ formatMoney(zone.averageSales) }}</td>
                                    <td>
                                        <div class="report-dashboard-performance-track">
                                            <span
                                                class="report-dashboard-performance-fill"
                                                :style="{ width: zone.performanceWidth }"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td>Total</td>
                                    <td class="text-right">{{ formatMoney(props.summary.total_sales) }}</td>
                                    <td class="text-right">{{ formatNumber(props.summary.stores_count) }}</td>
                                    <td class="text-right">{{ formatMoney(props.summary.bar_groups_count > 0 ? props.summary.total_sales / props.summary.bar_groups_count : 0) }}</td>
                                    <td />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <section class="report-dashboard-section">
                    <div class="report-dashboard-section-header">
                        <h3 class="report-dashboard-section-title">Vendas por Device</h3>
                    </div>

                    <div class="report-dashboard-zone-grid">
                        <article
                            v-for="zone in props.zoneDevices"
                            :key="zone.label"
                            class="report-dashboard-zone-card"
                        >
                            <div class="report-dashboard-zone-header">
                                <span>{{ zone.label }}</span>
                                <strong>{{ formatNumber(zone.devices_count) }} device(s)</strong>
                            </div>

                            <div class="report-dashboard-zone-body">
                                <div
                                    v-for="item in zone.items"
                                    :key="`${zone.label}-${item.code ?? item.label}`"
                                    class="report-dashboard-zone-row"
                                >
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

                <section class="report-dashboard-section">
                    <div class="report-dashboard-section-header">
                        <h3 class="report-dashboard-section-title">Resumo Z / Salesday</h3>
                        <p class="report-dashboard-section-helper">
                            {{ props.salesday.scope_note }}
                        </p>
                    </div>

                    <div
                        v-if="!props.salesday.available"
                        class="dash-card report-dashboard-empty"
                    >
                        Ainda não existem dados Salesday para este evento.
                    </div>

                    <div
                        v-else
                        class="report-dashboard-grid report-dashboard-grid-4"
                    >
                        <article
                            v-for="card in salesdayCards"
                            :key="card.label"
                            class="report-dashboard-summary-card"
                        >
                            <span class="report-dashboard-summary-label">{{ card.label }}</span>
                            <strong class="report-dashboard-summary-value">{{ card.value }}</strong>
                        </article>
                    </div>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
