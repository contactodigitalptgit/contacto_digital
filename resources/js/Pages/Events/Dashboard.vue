<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface EventMeta {
    id: number;
    title: string;
    description: string | null;
    event_date: string;
    client_name: string;
    client_business_name: string | null;
    active_imports_count: number;
    last_synced_at: string | null;
}

interface FilterState {
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

interface EventSummary {
    active_imports_count: number;
    total_rows: number;
    filtered_rows: number;
    bar_groups_count: number;
    total_sales: number;
    total_value: number;
    total_discount: number;
    total_quantity: number;
    stores_count: number;
    products_count: number;
    average_ticket: number;
    last_synced_at: string | null;
    machines_count: number;
}

interface IntegrationMeta {
    source: string;
    configured_client_ids_count: number;
    machines_count: number;
    last_synced_at: string | null;
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

interface EventRow {
    id: number;
    store_code: string | null;
    store_name: string | null;
    sale_date: string | null;
    sale_datetime: string | null;
    doc_type: string | null;
    document_series: string | null;
    document_number: string | null;
    product_code: string | null;
    description: string | null;
    quantity: number;
    value: number;
    discount: number;
    total: number;
}

interface Pagination {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface HeroHighlight {
    label: string;
    value: string;
    helper: string;
    featured?: boolean;
}

interface SummaryCard {
    label: string;
    value: string;
    helper: string;
    featured?: boolean;
}

interface IntegrationCard {
    label: string;
    value: string;
    helper: string;
}

interface FilterChip {
    label: string;
    value: string;
}

type DashboardSectionId =
    | 'overview'
    | 'filters'
    | 'bars'
    | 'stores'
    | 'products'
    | 'rows';

interface DashboardSection {
    id: DashboardSectionId;
    label: string;
    helper: string;
}

interface HeroMetaCard {
    label: string;
    value: string;
}

const props = defineProps<{
    event: EventMeta;
    integration: IntegrationMeta;
    filters: FilterState;
    filterOptions: {
        barGroups: FilterOption[];
        stores: FilterOption[];
        products: FilterOption[];
    };
    summary: EventSummary;
    barGroups: BarGroupItem[];
    topStores: BreakdownItem[];
    topProducts: BreakdownItem[];
    rows: EventRow[];
    pagination: Pagination;
    previewMode?: boolean;
    backUrl: string;
    backLabel: string;
}>();

const filterForm = useForm({
    bar_group: props.filters.bar_group,
    store: props.filters.store,
    product: props.filters.product,
    date_from: props.filters.date_from,
    date_to: props.filters.date_to,
    total_min: props.filters.total_min,
    total_max: props.filters.total_max,
});

const dashboardRoute = computed(() =>
    props.previewMode
        ? route('admin.events.dashboard', props.event.id)
        : route('events.dashboard', props.event.id),
);

const hasImportedData = computed(() => props.summary.total_rows > 0);
const activeSection = ref<DashboardSectionId>('overview');
const showSectionMenu = ref(false);

const getFilterOptionLabel = (options: FilterOption[], value: string) =>
    options.find((option) => option.value === value)?.label ?? value;

const activeFilterChips = computed<FilterChip[]>(() => {
    const chips: FilterChip[] = [];

    if (props.filters.bar_group) {
        chips.push({
            label: 'Bar',
            value: getFilterOptionLabel(props.filterOptions.barGroups, props.filters.bar_group),
        });
    }

    if (props.filters.store) {
        chips.push({
            label: 'Loja',
            value: getFilterOptionLabel(props.filterOptions.stores, props.filters.store),
        });
    }

    if (props.filters.product) {
        chips.push({
            label: 'Produto',
            value: getFilterOptionLabel(props.filterOptions.products, props.filters.product),
        });
    }

    if (props.filters.date_from || props.filters.date_to) {
        chips.push({
            label: 'Período',
            value: `${props.filters.date_from ? formatDate(props.filters.date_from) : 'Início'} até ${props.filters.date_to ? formatDate(props.filters.date_to) : 'Hoje'}`,
        });
    }

    if (props.filters.total_min || props.filters.total_max) {
        chips.push({
            label: 'Total',
            value: `${props.filters.total_min ? formatMoney(Number(props.filters.total_min)) : formatMoney(0)} até ${props.filters.total_max ? formatMoney(Number(props.filters.total_max)) : 'Sem limite'}`,
        });
    }

    return chips;
});

const hasActiveFilters = computed(() => activeFilterChips.value.length > 0);

const dashboardSections = computed<DashboardSection[]>(() => [
    {
        id: 'overview',
        label: 'Visão geral',
        helper: 'Resumo financeiro e operacional',
    },
    {
        id: 'filters',
        label: 'Filtros',
        helper: hasActiveFilters.value
            ? `${activeFilterChips.value.length} filtro(s) ativo(s)`
            : 'Sem filtros aplicados',
    },
    {
        id: 'bars',
        label: 'Bares',
        helper: `${formatNumber(props.summary.bar_groups_count)} agrupamento(s)`,
    },
    {
        id: 'stores',
        label: 'Lojas',
        helper: `${formatNumber(props.summary.stores_count)} ponto(s) no recorte`,
    },
    {
        id: 'products',
        label: 'Produtos',
        helper: `${formatNumber(props.summary.products_count)} item(ns) no recorte`,
    },
    {
        id: 'rows',
        label: 'Linhas',
        helper: `${formatNumber(props.pagination.total)} registro(s) na listagem`,
    },
]);

const currentSection = computed(
    () =>
        dashboardSections.value.find(
            (section) => section.id === activeSection.value,
        ) ?? dashboardSections.value[0],
);

const heroMetaCards = computed<HeroMetaCard[]>(() => {
    const cards: HeroMetaCard[] = [
        {
            label: 'Linhas ativas',
            value: formatNumber(props.summary.total_rows),
        },
        {
            label: 'Linhas no recorte',
            value: formatNumber(props.summary.filtered_rows),
        },
        {
            label: 'Lojas no recorte',
            value: formatNumber(props.summary.stores_count),
        },
        {
            label: 'Produtos no recorte',
            value: formatNumber(props.summary.products_count),
        },
    ];

    if (props.previewMode) {
        cards.push(
            {
                label: 'Máquinas sincronizadas',
                value: formatNumber(props.summary.machines_count),
            },
            {
                label: 'Última sincronização',
                value: formatDateTime(props.summary.last_synced_at),
            },
        );
    }

    return cards;
});

const heroHighlights = computed<HeroHighlight[]>(() => [
    {
        label: 'Vendas no filtro',
        value: formatMoney(props.summary.total_sales),
        helper: `${formatNumber(props.summary.filtered_rows)} linha(s) consideradas no recorte atual`,
        featured: true,
    },
    {
        label: 'Ticket médio',
        value: formatMoney(props.summary.average_ticket),
        helper: `${formatNumber(props.summary.total_quantity)} unidade(s) movimentadas`,
    },
    {
        label: props.previewMode ? 'Sincronizações ativas' : 'Linhas no recorte',
        value: props.previewMode
            ? formatNumber(props.summary.active_imports_count)
            : formatNumber(props.summary.filtered_rows),
        helper: props.previewMode
            ? props.summary.last_synced_at
                ? `Atualizado em ${formatDateTime(props.summary.last_synced_at)}`
                : 'Nenhuma sincronização realizada'
            : `${formatNumber(props.summary.products_count)} produto(s) e ${formatNumber(props.summary.stores_count)} loja(s) no recorte`,
    },
]);

const summaryCards = computed<SummaryCard[]>(() => [
    {
        label: 'Vendas',
        value: formatMoney(props.summary.total_sales),
        helper: 'Total líquido apurado no filtro atual',
        featured: true,
    },
    {
        label: 'Valor base',
        value: formatMoney(props.summary.total_value),
        helper: 'Montante antes dos descontos',
    },
    {
        label: 'Descontos',
        value: formatMoney(props.summary.total_discount),
        helper: 'Desconto agregado das linhas filtradas',
    },
    {
        label: 'Quantidade',
        value: formatNumber(props.summary.total_quantity),
        helper: 'Itens vendidos nas linhas filtradas',
    },
    {
        label: 'Ticket médio',
        value: formatMoney(props.summary.average_ticket),
        helper: 'Valor médio por linha filtrada',
    },
    {
        label: 'Linhas no recorte',
        value: formatNumber(props.summary.filtered_rows),
        helper: 'Linhas consideradas depois dos filtros',
    },
    {
        label: 'Linhas ativas',
        value: formatNumber(props.summary.total_rows),
        helper: 'Base ativa disponível para este evento',
    },
    {
        label: 'Bares agrupados',
        value: formatNumber(props.summary.bar_groups_count),
        helper: 'Pontos consolidados por bar base',
    },
    {
        label: 'Lojas',
        value: formatNumber(props.summary.stores_count),
        helper: 'Operadores ou pontos de venda únicos',
    },
    {
        label: 'Produtos',
        value: formatNumber(props.summary.products_count),
        helper: 'Produtos distintos no recorte atual',
    },
]);

const integrationCards = computed<IntegrationCard[]>(() => [
    {
        label: 'Origem',
        value: props.integration.source,
        helper: 'Fonte usada para alimentar os dados deste evento',
    },
    {
        label: 'Client IDs ativos',
        value: formatNumber(props.integration.configured_client_ids_count),
        helper: 'Máquinas ativas configuradas para este cliente',
    },
    {
        label: 'Máquinas na última sync',
        value: formatNumber(props.integration.machines_count),
        helper: props.integration.machines_count > 0
            ? 'Client IDs usados na sincronização mais recente'
            : 'Ainda não existe sincronização concluída',
    },
    {
        label: 'Última sincronização',
        value: props.integration.last_synced_at
            ? formatDateTime(props.integration.last_synced_at)
            : 'Ainda não sincronizado',
        helper: 'Momento da atualização mais recente vinda da ZoneSoft',
    },
]);

const maxBarGroupSales = computed(() =>
    props.barGroups.reduce((max, item) => Math.max(max, item.sales_total), 0),
);

const maxStoreSales = computed(() =>
    props.topStores.reduce((max, item) => Math.max(max, item.sales_total), 0),
);

const maxProductSales = computed(() =>
    props.topProducts.reduce((max, item) => Math.max(max, item.sales_total), 0),
);

const applyFilters = () => {
    router.get(
        dashboardRoute.value,
        {
            ...filterForm.data(),
            page: 1,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const clearFilters = () => {
    filterForm.bar_group = '';
    filterForm.store = '';
    filterForm.product = '';
    filterForm.date_from = '';
    filterForm.date_to = '';
    filterForm.total_min = '';
    filterForm.total_max = '';

    router.get(
        dashboardRoute.value,
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const setActiveSection = (section: DashboardSectionId) => {
    activeSection.value = section;
    showSectionMenu.value = false;

    if (typeof window === 'undefined') {
        return;
    }

    window.requestAnimationFrame(() => {
        document
            .getElementById(`event-dashboard-section-${section}`)
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
};

const goToPage = (url: string | null) => {
    if (!url) {
        return;
    }

    router.get(
        url,
        {},
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
};

const formatDateTime = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('pt-PT', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : 'Sem data';

const formatDate = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('pt-PT', {
              dateStyle: 'medium',
          }).format(new Date(value))
        : 'Sem data';

const formatMoney = (value: number) =>
    new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency: 'EUR',
    }).format(value);

const formatNumber = (value: number) =>
    new Intl.NumberFormat('pt-PT', {
        maximumFractionDigits: value % 1 === 0 ? 0 : 2,
    }).format(value);

const getBreakdownWidth = (value: number, maxValue: number) => {
    if (value <= 0 || maxValue <= 0) {
        return '0%';
    }

    return `${Math.max(12, Math.round((value / maxValue) * 100))}%`;
};

const getRowDocument = (row: EventRow) => {
    const main = [row.doc_type, row.document_number].filter(Boolean).join(' ');

    if (!row.document_series) {
        return main || 'Sem documento';
    }

    return `${main} · ${row.document_series}`;
};
</script>

<template>
    <Head :title="`Evento - ${props.event.title}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="event-dashboard-header">
                <div>
                    <div class="event-dashboard-header-top">
                        <span class="event-dashboard-page-kicker">Dashboard do evento</span>
                        <span
                            v-if="props.previewMode"
                            class="event-dashboard-page-badge"
                        >
                            Preview admin
                        </span>
                    </div>
                    <h2 class="dash-page-title">
                        {{ props.event.title }}
                    </h2>
                    <p class="event-dashboard-subtitle">
                        {{ props.event.description || 'Acompanhe o desempenho do evento com uma leitura mais objetiva e focada na operação.' }}
                    </p>
                </div>
                <Link
                    :href="props.backUrl"
                    class="dash-link-button"
                >
                    {{ props.backLabel }}
                </Link>
            </div>
        </template>

        <div class="dash-page space-y-6">
            <section
                v-if="props.previewMode"
                class="dash-card dash-card-full"
            >
                <div class="dash-preview-banner">
                    <div>
                        <h3 class="dash-card-title mb-0">Visualização do Evento</h3>
                        <p class="dash-recent-subtitle">
                            Você está vendo o dashboard do evento como administrador.
                        </p>
                    </div>
                    <Link
                        :href="props.backUrl"
                        class="dash-link-button"
                    >
                        {{ props.backLabel }}
                    </Link>
                </div>
            </section>

            <section class="dash-card event-dashboard-hero">
                <div class="event-dashboard-hero-shell">
                    <div class="event-dashboard-hero-copy">
                        <p class="event-dashboard-label">Visão operacional</p>
                        <h3 class="dash-card-title mb-0">{{ props.event.title }}</h3>
                        <p
                            v-if="props.event.description"
                            class="event-dashboard-description"
                        >
                            {{ props.event.description }}
                        </p>

                        <div class="event-dashboard-meta-grid">
                            <article
                                v-for="card in heroMetaCards"
                                :key="card.label"
                                class="event-dashboard-meta-card"
                            >
                                <span>{{ card.label }}</span>
                                <strong>{{ card.value }}</strong>
                            </article>
                        </div>
                    </div>

                    <div class="event-dashboard-highlight-grid">
                        <article
                            v-for="highlight in heroHighlights"
                            :key="highlight.label"
                            class="event-dashboard-highlight-card"
                            :class="{ 'event-dashboard-highlight-card-featured': highlight.featured }"
                        >
                            <span class="event-dashboard-highlight-label">
                                {{ highlight.label }}
                            </span>
                            <strong class="event-dashboard-highlight-value">
                                {{ highlight.value }}
                            </strong>
                            <p class="event-dashboard-highlight-helper">
                                {{ highlight.helper }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <section class="dash-card event-dashboard-section">
                <div class="dash-recent-header">
                    <div>
                        <h3 class="dash-card-title mb-0">Integração ZoneSoft</h3>
                        <p class="dash-recent-subtitle">
                            Estado operacional da integração usada para sincronizar este evento.
                        </p>
                    </div>
                </div>

                <div class="event-dashboard-summary-grid">
                    <article
                        v-for="card in integrationCards"
                        :key="card.label"
                        class="event-dashboard-summary-card"
                    >
                        <span class="event-dashboard-summary-label">{{ card.label }}</span>
                        <strong class="event-dashboard-summary-value">{{ card.value }}</strong>
                        <p class="event-dashboard-summary-helper">{{ card.helper }}</p>
                    </article>
                </div>
            </section>

            <section class="hidden dash-card event-dashboard-section event-dashboard-menu-section lg:block">
                <div class="event-dashboard-menu-header">
                    <div>
                        <h3 class="dash-card-title mb-0">Navegação do dashboard</h3>
                        <p class="dash-recent-subtitle">
                            Troque de área sem alongar a página e mantenha a consulta mais leve.
                        </p>
                    </div>

                    <div class="event-dashboard-menu-tools">
                        <span class="event-dashboard-menu-status">
                            {{ hasActiveFilters ? `${activeFilterChips.length} filtro(s) ativo(s)` : 'Sem filtros ativos' }}
                        </span>

                        <button
                            v-if="activeSection !== 'filters'"
                            type="button"
                            class="dash-link-button"
                            @click="setActiveSection('filters')"
                        >
                            Abrir filtros
                        </button>

                        <button
                            v-if="hasActiveFilters"
                            type="button"
                            class="dash-link-button"
                            @click="clearFilters"
                        >
                            Limpar filtros
                        </button>
                    </div>
                </div>

                <div class="event-dashboard-menu-grid">
                    <button
                        v-for="section in dashboardSections"
                        :key="section.id"
                        type="button"
                        class="event-dashboard-menu-item"
                        :class="{ 'is-active': activeSection === section.id }"
                        @click="setActiveSection(section.id)"
                    >
                        <span class="event-dashboard-menu-item-label">
                            {{ section.label }}
                        </span>
                        <strong class="event-dashboard-menu-item-helper">
                            {{ section.helper }}
                        </strong>
                    </button>
                </div>
            </section>

            <div class="event-dashboard-floating-actions lg:hidden">
                <button
                    type="button"
                    class="event-dashboard-floating-btn"
                    @click="showSectionMenu = !showSectionMenu"
                >
                    <span class="event-dashboard-floating-btn-label">Área visível</span>
                    <strong>{{ currentSection.label }}</strong>
                </button>

                <button
                    type="button"
                    class="event-dashboard-floating-btn event-dashboard-floating-btn-primary"
                    @click="setActiveSection('filters')"
                >
                    <span class="event-dashboard-floating-btn-label">Filtro</span>
                    <strong>{{ hasActiveFilters ? `${activeFilterChips.length} ativo(s)` : 'Abrir filtros' }}</strong>
                </button>
            </div>

            <div
                v-if="showSectionMenu"
                class="event-dashboard-floating-panel-overlay lg:hidden"
                @click="showSectionMenu = false"
            >
                <div
                    class="event-dashboard-floating-panel"
                    @click.stop
                >
                    <div class="event-dashboard-floating-panel-header">
                        <div>
                            <span class="event-dashboard-floating-panel-kicker">Menu do dashboard</span>
                            <strong class="event-dashboard-floating-panel-title">Escolha a área da consulta</strong>
                        </div>
                        <button
                            type="button"
                            class="event-dashboard-floating-panel-close"
                            @click="showSectionMenu = false"
                        >
                            <svg
                                class="h-5 w-5"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path d="M18 6L6 18M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="event-dashboard-floating-panel-list">
                        <button
                            v-for="section in dashboardSections"
                            :key="section.id"
                            type="button"
                            class="event-dashboard-floating-panel-item"
                            :class="{ 'is-active': activeSection === section.id }"
                            @click="setActiveSection(section.id)"
                        >
                            <span>{{ section.label }}</span>
                            <strong>{{ section.helper }}</strong>
                        </button>
                    </div>

                    <div class="event-dashboard-floating-panel-actions">
                        <button
                            v-if="hasActiveFilters"
                            type="button"
                            class="dash-link-button"
                            @click="clearFilters"
                        >
                            Limpar filtros
                        </button>
                        <button
                            type="button"
                            class="dash-action-button dash-action-button-inline"
                            @click="setActiveSection('filters')"
                        >
                            Abrir filtros
                        </button>
                    </div>
                </div>
            </div>

            <section
                v-show="activeSection === 'overview'"
                id="event-dashboard-section-overview"
                class="dash-card event-dashboard-section"
            >
                <div class="dash-recent-header">
                    <div>
                        <h3 class="dash-card-title mb-0">Visão geral do evento</h3>
                        <p class="dash-recent-subtitle">
                            Resumo financeiro e operacional do recorte atual, sem informação técnica desnecessária para o cliente.
                        </p>
                    </div>
                </div>

                <div
                    v-if="!hasImportedData"
                    class="event-dashboard-empty"
                >
                    Nenhum relatório sincronizado para este evento.
                </div>

                <div
                    v-else
                    class="event-dashboard-summary-grid"
                >
                    <article
                        v-for="card in summaryCards"
                        :key="card.label"
                        class="event-dashboard-summary-card"
                        :class="{ 'event-dashboard-summary-card-featured': card.featured }"
                    >
                        <span class="event-dashboard-summary-label">{{ card.label }}</span>
                        <strong class="event-dashboard-summary-value">{{ card.value }}</strong>
                        <p class="event-dashboard-summary-helper">{{ card.helper }}</p>
                    </article>
                </div>
            </section>

            <section
                v-show="activeSection === 'filters'"
                id="event-dashboard-section-filters"
                class="dash-card event-dashboard-section"
            >
                <div class="dash-recent-header">
                    <div>
                        <h3 class="dash-card-title mb-0">Filtros</h3>
                        <p class="dash-recent-subtitle">
                            Ajuste bar agrupado, loja, produto, período e faixa de total sem sair do dashboard.
                        </p>
                    </div>
                    <div class="event-dashboard-filter-actions">
                        <button
                            type="button"
                            class="dash-link-button"
                            @click="clearFilters"
                        >
                            Limpar filtros
                        </button>
                        <button
                            type="button"
                            class="dash-action-button dash-action-button-inline"
                            @click="applyFilters"
                        >
                            Aplicar filtros
                        </button>
                    </div>
                </div>

                <div class="event-dashboard-filter-summary">
                    <div
                        v-if="hasActiveFilters"
                        class="event-dashboard-filter-chip-list"
                    >
                        <span
                            v-for="chip in activeFilterChips"
                            :key="`${chip.label}-${chip.value}`"
                            class="event-dashboard-filter-chip"
                        >
                            <span>{{ chip.label }}</span>
                            <strong>{{ chip.value }}</strong>
                        </span>
                    </div>
                    <div
                        v-else
                        class="event-dashboard-filter-empty"
                    >
                        Sem filtros aplicados. O dashboard está considerando todas as linhas ativas.
                    </div>

                    <div class="event-dashboard-filter-result">
                        <span>Linhas no recorte</span>
                        <strong>{{ formatNumber(props.summary.filtered_rows) }}</strong>
                    </div>
                </div>

                <form
                    class="event-dashboard-filter-grid"
                    @submit.prevent="applyFilters"
                >
                    <div class="event-dashboard-field">
                        <label
                            class="dash-modal-label"
                            for="event_filter_bar_group"
                        >
                            Bar agrupado
                        </label>
                        <select
                            id="event_filter_bar_group"
                            v-model="filterForm.bar_group"
                            class="dash-modal-input"
                        >
                            <option value="">Todos os bares</option>
                            <option
                                v-for="barGroup in props.filterOptions.barGroups"
                                :key="barGroup.value"
                                :value="barGroup.value"
                            >
                                {{ barGroup.label }} ({{ barGroup.rows_count }})
                            </option>
                        </select>
                    </div>

                    <div class="event-dashboard-field">
                        <label
                            class="dash-modal-label"
                            for="event_filter_store"
                        >
                            Loja
                        </label>
                        <select
                            id="event_filter_store"
                            v-model="filterForm.store"
                            class="dash-modal-input"
                        >
                            <option value="">Todas as lojas</option>
                            <option
                                v-for="store in props.filterOptions.stores"
                                :key="store.value"
                                :value="store.value"
                            >
                                {{ store.label }} ({{ store.rows_count }})
                            </option>
                        </select>
                    </div>

                    <div class="event-dashboard-field">
                        <label
                            class="dash-modal-label"
                            for="event_filter_product"
                        >
                            Produto
                        </label>
                        <select
                            id="event_filter_product"
                            v-model="filterForm.product"
                            class="dash-modal-input"
                        >
                            <option value="">Todos os produtos</option>
                            <option
                                v-for="product in props.filterOptions.products"
                                :key="product.value"
                                :value="product.value"
                            >
                                {{ product.label }} ({{ product.rows_count }})
                            </option>
                        </select>
                    </div>

                    <div class="event-dashboard-field">
                        <label
                            class="dash-modal-label"
                            for="event_filter_date_from"
                        >
                            Data inicial
                        </label>
                        <input
                            id="event_filter_date_from"
                            v-model="filterForm.date_from"
                            class="dash-modal-input"
                            type="date"
                        />
                    </div>

                    <div class="event-dashboard-field">
                        <label
                            class="dash-modal-label"
                            for="event_filter_date_to"
                        >
                            Data final
                        </label>
                        <input
                            id="event_filter_date_to"
                            v-model="filterForm.date_to"
                            class="dash-modal-input"
                            type="date"
                        />
                    </div>

                    <div class="event-dashboard-field">
                        <label
                            class="dash-modal-label"
                            for="event_filter_total_min"
                        >
                            Total mínimo
                        </label>
                        <input
                            id="event_filter_total_min"
                            v-model="filterForm.total_min"
                            class="dash-modal-input"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="0.00"
                        />
                    </div>

                    <div class="event-dashboard-field">
                        <label
                            class="dash-modal-label"
                            for="event_filter_total_max"
                        >
                            Total máximo
                        </label>
                        <input
                            id="event_filter_total_max"
                            v-model="filterForm.total_max"
                            class="dash-modal-input"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="0.00"
                        />
                    </div>
                </form>
            </section>

            <section
                v-show="activeSection === 'bars'"
                id="event-dashboard-section-bars"
                class="dash-card event-dashboard-section"
            >
                <div class="dash-recent-header">
                    <div>
                        <h3 class="dash-card-title mb-0">Bares agrupados</h3>
                        <p class="dash-recent-subtitle">
                            Consolidação por bar base, agrupando os operadores do mesmo ponto.
                        </p>
                    </div>
                </div>

                <ul class="event-dashboard-breakdown-list">
                    <li
                        v-for="(bar, index) in props.barGroups"
                        :key="bar.label"
                        class="event-dashboard-breakdown-item"
                    >
                        <div class="event-dashboard-breakdown-main">
                            <span class="event-dashboard-rank">
                                {{ index + 1 }}
                            </span>

                            <div class="min-w-0 flex-1">
                                <div class="event-dashboard-breakdown-head">
                                    <p class="event-dashboard-breakdown-title">
                                        {{ bar.label }}
                                    </p>
                                    <span class="event-dashboard-breakdown-badge">
                                        {{ bar.stores_count }} operador(es)
                                    </span>
                                </div>
                                <p class="event-dashboard-breakdown-subtitle">
                                    {{ bar.rows_count }} linha(s) sincronizadas neste agrupamento
                                </p>

                                <div class="event-dashboard-breakdown-track">
                                    <span
                                        class="event-dashboard-breakdown-fill"
                                        :style="{ width: getBreakdownWidth(bar.sales_total, maxBarGroupSales) }"
                                    />
                                </div>

                                <div
                                    v-if="bar.members.length"
                                    class="event-dashboard-chip-list"
                                >
                                    <span
                                        v-for="member in bar.members"
                                        :key="member"
                                        class="event-dashboard-chip"
                                    >
                                        {{ member }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="event-dashboard-breakdown-side">
                            <div class="event-dashboard-breakdown-stat">
                                <span>Vendido</span>
                                <strong>{{ formatMoney(bar.sales_total) }}</strong>
                            </div>
                            <div class="event-dashboard-breakdown-stat">
                                <span>Qtd</span>
                                <strong>{{ formatNumber(bar.quantity_total) }}</strong>
                            </div>
                        </div>
                    </li>
                    <li
                        v-if="!props.barGroups.length"
                        class="event-dashboard-empty"
                    >
                        Nenhum bar encontrado para os filtros atuais.
                    </li>
                </ul>
            </section>

            <section
                v-show="activeSection === 'stores'"
                id="event-dashboard-section-stores"
                class="dash-card event-dashboard-section"
            >
                <div class="dash-recent-header">
                    <div>
                        <h3 class="dash-card-title mb-0">Pontos de venda</h3>
                        <p class="dash-recent-subtitle">
                            Detalhe por operador e ponto de venda no filtro atual.
                        </p>
                    </div>
                </div>

                <ul class="event-dashboard-breakdown-list">
                    <li
                        v-for="(store, index) in props.topStores"
                        :key="`${store.label}-${store.code || 'no-code'}`"
                        class="event-dashboard-breakdown-item"
                    >
                        <div class="event-dashboard-breakdown-main">
                            <span class="event-dashboard-rank">
                                {{ index + 1 }}
                            </span>

                            <div class="min-w-0 flex-1">
                                <div class="event-dashboard-breakdown-head">
                                    <p class="event-dashboard-breakdown-title">
                                        {{ store.label }}
                                    </p>
                                    <span class="event-dashboard-breakdown-badge">
                                        {{ store.code || 'Sem código' }}
                                    </span>
                                </div>
                                <p class="event-dashboard-breakdown-subtitle">
                                    {{ store.rows_count }} linha(s) no recorte atual
                                </p>

                                <div class="event-dashboard-breakdown-track">
                                    <span
                                        class="event-dashboard-breakdown-fill"
                                        :style="{ width: getBreakdownWidth(store.sales_total, maxStoreSales) }"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="event-dashboard-breakdown-side">
                            <div class="event-dashboard-breakdown-stat">
                                <span>Vendido</span>
                                <strong>{{ formatMoney(store.sales_total) }}</strong>
                            </div>
                            <div class="event-dashboard-breakdown-stat">
                                <span>Qtd</span>
                                <strong>{{ formatNumber(store.quantity_total) }}</strong>
                            </div>
                        </div>
                    </li>
                    <li
                        v-if="!props.topStores.length"
                        class="event-dashboard-empty"
                    >
                        Nenhuma loja encontrada para os filtros atuais.
                    </li>
                </ul>
            </section>

            <section
                v-show="activeSection === 'products'"
                id="event-dashboard-section-products"
                class="dash-card event-dashboard-section"
            >
                <div class="dash-recent-header">
                    <div>
                        <h3 class="dash-card-title mb-0">Top produtos</h3>
                        <p class="dash-recent-subtitle">
                            Ranking por total vendido no filtro atual.
                        </p>
                    </div>
                </div>

                <ul class="event-dashboard-breakdown-list">
                    <li
                        v-for="(product, index) in props.topProducts"
                        :key="`${product.label}-${product.code || 'no-code'}`"
                        class="event-dashboard-breakdown-item"
                    >
                        <div class="event-dashboard-breakdown-main">
                            <span class="event-dashboard-rank">
                                {{ index + 1 }}
                            </span>

                            <div class="min-w-0 flex-1">
                                <div class="event-dashboard-breakdown-head">
                                    <p class="event-dashboard-breakdown-title">
                                        {{ product.label }}
                                    </p>
                                    <span class="event-dashboard-breakdown-badge">
                                        {{ product.code || 'Sem código' }}
                                    </span>
                                </div>
                                <p class="event-dashboard-breakdown-subtitle">
                                    {{ product.rows_count }} linha(s) no recorte atual
                                </p>

                                <div class="event-dashboard-breakdown-track">
                                    <span
                                        class="event-dashboard-breakdown-fill"
                                        :style="{ width: getBreakdownWidth(product.sales_total, maxProductSales) }"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="event-dashboard-breakdown-side">
                            <div class="event-dashboard-breakdown-stat">
                                <span>Vendido</span>
                                <strong>{{ formatMoney(product.sales_total) }}</strong>
                            </div>
                            <div class="event-dashboard-breakdown-stat">
                                <span>Qtd</span>
                                <strong>{{ formatNumber(product.quantity_total) }}</strong>
                            </div>
                        </div>
                    </li>
                    <li
                        v-if="!props.topProducts.length"
                        class="event-dashboard-empty"
                    >
                        Nenhum produto encontrado para os filtros atuais.
                    </li>
                </ul>
            </section>

            <section
                v-show="activeSection === 'rows'"
                id="event-dashboard-section-rows"
                class="dash-card event-dashboard-section"
            >
                <div class="dash-recent-header">
                    <div>
                        <h3 class="dash-card-title mb-0">Linhas sincronizadas</h3>
                        <p class="dash-recent-subtitle">
                            Dados filtrados usados pelo dashboard do evento.
                        </p>
                    </div>
                    <div
                        v-if="props.pagination.total"
                        class="dash-muted"
                    >
                        {{ props.pagination.from ?? 0 }}-{{ props.pagination.to ?? 0 }}
                        de {{ props.pagination.total }}
                    </div>
                </div>

                <div class="event-dashboard-table-toolbar">
                    <p class="dash-muted">
                        {{ hasActiveFilters ? 'O resultado abaixo reflete os filtros aplicados.' : 'Sem filtros ativos. A listagem mostra todas as linhas sincronizadas ativas.' }}
                    </p>
                    <div class="event-dashboard-filter-chip-list">
                        <span class="event-dashboard-filter-chip">
                            <span>Página</span>
                            <strong>{{ props.pagination.current_page }} / {{ props.pagination.last_page }}</strong>
                        </span>
                        <span
                            v-if="hasActiveFilters"
                            class="event-dashboard-filter-chip"
                        >
                            <span>Filtros</span>
                            <strong>{{ activeFilterChips.length }} ativo(s)</strong>
                        </span>
                    </div>
                </div>

                <div
                    v-if="!hasImportedData"
                    class="event-dashboard-empty"
                >
                    Nenhum relatório sincronizado para este evento.
                </div>

                <template v-else>
                    <div class="event-dashboard-mobile-rows md:hidden">
                        <article
                            v-for="row in props.rows"
                            :key="row.id"
                            class="event-dashboard-row-card"
                        >
                            <div class="event-dashboard-row-top">
                                <div>
                                    <p class="event-dashboard-breakdown-title">
                                        {{ row.description || 'Produto sem descrição' }}
                                    </p>
                                    <p class="event-dashboard-breakdown-subtitle">
                                        {{ row.product_code || 'Sem código' }}
                                    </p>
                                </div>
                                <span class="status-pill neutral">
                                    {{ formatMoney(row.total) }}
                                </span>
                            </div>

                            <div class="event-dashboard-row-grid">
                                <div>
                                    <p class="event-dashboard-row-label">Loja</p>
                                    <p class="event-dashboard-row-value">
                                        {{ row.store_name || 'Sem loja' }}
                                    </p>
                                </div>
                                <div>
                                    <p class="event-dashboard-row-label">Data</p>
                                    <p class="event-dashboard-row-value">
                                        {{ formatDateTime(row.sale_datetime || row.sale_date) }}
                                    </p>
                                </div>
                                <div>
                                    <p class="event-dashboard-row-label">Documento</p>
                                    <p class="event-dashboard-row-value">
                                        {{ getRowDocument(row) }}
                                    </p>
                                </div>
                                <div>
                                    <p class="event-dashboard-row-label">Quantidade</p>
                                    <p class="event-dashboard-row-value">
                                        {{ formatNumber(row.quantity) }}
                                    </p>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div class="hidden overflow-x-auto md:block">
                        <table class="dash-table event-dashboard-table">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Loja</th>
                                    <th>Produto</th>
                                    <th>Documento</th>
                                    <th class="text-right">Qtd</th>
                                    <th class="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in props.rows"
                                    :key="row.id"
                                >
                                    <td class="event-dashboard-table-text">
                                        {{ formatDateTime(row.sale_datetime || row.sale_date) }}
                                    </td>
                                    <td>
                                        <p class="event-dashboard-table-title">
                                            {{ row.store_name || 'Sem loja' }}
                                        </p>
                                        <p class="event-dashboard-table-subtitle">
                                            {{ row.store_code || 'Sem código' }}
                                        </p>
                                    </td>
                                    <td>
                                        <p class="event-dashboard-table-title">
                                            {{ row.description || 'Produto sem descrição' }}
                                        </p>
                                        <p class="event-dashboard-table-subtitle">
                                            {{ row.product_code || 'Sem código' }}
                                        </p>
                                    </td>
                                    <td class="event-dashboard-table-text">
                                        {{ getRowDocument(row) }}
                                    </td>
                                    <td class="text-right event-dashboard-table-text">
                                        {{ formatNumber(row.quantity) }}
                                    </td>
                                    <td class="text-right event-dashboard-table-strong">
                                        {{ formatMoney(row.total) }}
                                    </td>
                                </tr>
                                <tr v-if="!props.rows.length">
                                    <td
                                        colspan="6"
                                        class="py-8 text-center text-sm"
                                    >
                                        <span class="dash-muted-text">
                                            Nenhuma linha encontrada para os filtros atuais.
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-if="props.pagination.last_page > 1"
                        class="event-dashboard-pagination"
                    >
                        <button
                            type="button"
                            class="dash-link-button"
                            :disabled="!props.pagination.prev_page_url"
                            :class="{ 'opacity-50': !props.pagination.prev_page_url }"
                            @click="goToPage(props.pagination.prev_page_url)"
                        >
                            Página anterior
                        </button>

                        <span class="dash-muted">
                            Página {{ props.pagination.current_page }} de {{ props.pagination.last_page }}
                        </span>

                        <button
                            type="button"
                            class="dash-link-button"
                            :disabled="!props.pagination.next_page_url"
                            :class="{ 'opacity-50': !props.pagination.next_page_url }"
                            @click="goToPage(props.pagination.next_page_url)"
                        >
                            Próxima página
                        </button>
                    </div>
                </template>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
