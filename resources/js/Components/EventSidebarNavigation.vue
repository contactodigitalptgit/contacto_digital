<script setup lang="ts">
import AppSidebarIcon from '@/Components/AppSidebarIcon.vue';
import type { DashboardSectionKey } from '@/types/dashboard-configuration';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

export interface EventSidebarSection {
    key: DashboardSectionKey;
    label: string;
    icon: 'dashboard' | 'products' | 'payments' | 'zones' | 'performance' | 'compare';
}

// Menu lateral partilhado pelo dashboard do evento e pelas páginas de
// configuração (Gerir TPA, Gerir zonas), para o menu não mudar ao navegar.
const props = withDefaults(defineProps<{
    eventId: number;
    isAdmin: boolean;
    active: DashboardSectionKey | 'tpas' | 'zones-manage';
    previewMode?: boolean;
    showConfiguration?: boolean;
    sections?: EventSidebarSection[];
    sectionUrl?: (key: DashboardSectionKey) => string | null;
}>(), {
    previewMode: true,
    showConfiguration: true,
    sections: () => EVENT_SIDEBAR_SECTIONS,
    sectionUrl: undefined,
});

const routeSuffixes: Partial<Record<DashboardSectionKey, string>> = {
    summary: 'dashboard',
    products: 'products',
    reconciliation: 'payments',
    zones: 'zones',
    highlights: 'performance',
    comparison: 'comparison',
};

const groupTitles: Partial<Record<DashboardSectionKey, string>> = {
    summary: 'Operação',
    products: 'Análise',
    reconciliation: 'Financeiro',
    zones: 'Análise operacional',
};

const mainSections = computed(() => props.sections.filter((section) => section.key !== 'comparison'));
const comparisonSection = computed(() => props.sections.find((section) => section.key === 'comparison') ?? null);

function urlFor(key: DashboardSectionKey): string {
    if (props.sectionUrl) {
        return props.sectionUrl(key) || '#';
    }

    const suffix = routeSuffixes[key];

    return suffix
        ? route(`${props.previewMode ? 'admin.events' : 'events'}.${suffix}`, props.eventId)
        : '#';
}

function isActive(key: DashboardSectionKey): boolean {
    return props.active === key || (key === 'summary' && props.active === 'charts');
}
</script>

<script lang="ts">
export const EVENT_SIDEBAR_SECTIONS: EventSidebarSection[] = [
    { key: 'summary', label: 'Dashboard', icon: 'dashboard' },
    { key: 'products', label: 'Produtos', icon: 'products' },
    { key: 'reconciliation', label: 'Pagamentos', icon: 'payments' },
    { key: 'zones', label: 'Zonas', icon: 'zones' },
    { key: 'highlights', label: 'Performance', icon: 'performance' },
    { key: 'comparison', label: 'Comparar edições', icon: 'compare' },
];
</script>

<template>
    <section class="contacto-sidebar-navigation" aria-label="Navegação principal">
        <div class="contacto-sidebar-menu">
            <template v-for="section in mainSections" :key="section.key">
                <span v-if="groupTitles[section.key]" class="contacto-sidebar-menu-title">
                    {{ groupTitles[section.key] }}
                </span>

                <Link
                    :href="urlFor(section.key)"
                    class="contacto-sidebar-menu-item"
                    :class="{
                        'is-active': isActive(section.key),
                        'is-dashboard': section.key === 'summary',
                    }"
                >
                    <AppSidebarIcon :name="section.icon" />
                    <span>{{ section.label }}</span>
                </Link>
            </template>

            <template v-if="comparisonSection">
                <span class="contacto-sidebar-menu-title">Fecho</span>

                <Link
                    :href="urlFor(comparisonSection.key)"
                    class="contacto-sidebar-menu-item"
                    :class="{ 'is-active': isActive(comparisonSection.key) }"
                >
                    <AppSidebarIcon :name="comparisonSection.icon" />
                    <span>{{ comparisonSection.label }}</span>
                </Link>
            </template>

            <template v-if="isAdmin && showConfiguration">
                <span class="contacto-sidebar-menu-title">Configurar evento</span>

                <Link
                    :href="route('admin.events.tpas.manage', eventId)"
                    class="contacto-sidebar-menu-item"
                    :class="{ 'is-active': active === 'tpas' }"
                >
                    <AppSidebarIcon name="tpa" />
                    <span>Gerir TPA</span>
                </Link>

                <Link
                    :href="route('admin.events.zones.manage', eventId)"
                    class="contacto-sidebar-menu-item"
                    :class="{ 'is-active': active === 'zones-manage' }"
                >
                    <AppSidebarIcon name="zones" />
                    <span>Gerir zonas</span>
                </Link>
            </template>

            <template v-if="isAdmin">
                <span class="contacto-sidebar-menu-title">Administração</span>

                <Link
                    :href="route('admin.events.index')"
                    prefetch
                    class="contacto-sidebar-menu-item"
                >
                    <AppSidebarIcon name="events" />
                    <span>Eventos</span>
                </Link>

                <Link
                    :href="route('admin.clients.index')"
                    prefetch
                    class="contacto-sidebar-menu-item"
                >
                    <AppSidebarIcon name="clients" />
                    <span>Clientes</span>
                </Link>
            </template>
        </div>

        <slot />
    </section>
</template>
