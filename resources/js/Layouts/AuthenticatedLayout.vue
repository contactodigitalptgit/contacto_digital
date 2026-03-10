<script setup lang="ts">
import { usePwaInstall } from '@/composables/usePwaInstall';
import { showErrorToast, showSuccessToast } from '@/lib/swal';
import type { PageProps } from '@/types';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import { Link, usePage } from '@inertiajs/vue3';

interface NavItem {
    key: string;
    label: string;
    href: string;
    pattern: string;
    icon: 'dashboard' | 'clients' | 'events' | 'profile';
}

type ThemeMode = 'light' | 'dark';

const page = usePage<PageProps>();
const isAdmin = computed(() => page.props.auth.user.role === 'admin');
const theme = ref<ThemeMode>('light');
const isDarkTheme = computed(() => theme.value === 'dark');
const updateAvailable = ref(false);

const { canInstallPwa, installPwa, isStandalonePwa } = usePwaInstall();

const primaryNavigation = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            key: 'dashboard',
            label: 'Dashboard',
            href: route('dashboard'),
            pattern: 'dashboard',
            icon: 'dashboard',
        },
    ];

    if (isAdmin.value) {
        items.push(
            {
                key: 'clients',
                label: 'Clientes',
                href: route('admin.clients.index'),
                pattern: 'admin.clients.*',
                icon: 'clients',
            },
            {
                key: 'events',
                label: 'Eventos',
                href: route('admin.events.index'),
                pattern: 'admin.events.*',
                icon: 'events',
            },
        );
    }

    return items;
});

const mobileNavigation = computed<NavItem[]>(() => [
    ...primaryNavigation.value,
    {
        key: 'profile',
        label: 'Perfil',
        href: route('profile.edit'),
        pattern: 'profile.*',
        icon: 'profile',
    },
]);

const isActive = (pattern: string) => route().current(pattern);

const applyTheme = (value: ThemeMode) => {
    if (typeof document === 'undefined') {
        return;
    }

    theme.value = value;
    document.documentElement.setAttribute('data-theme', value);
    window.localStorage.setItem('contacto-theme', value);
};

const toggleTheme = () => {
    applyTheme(theme.value === 'dark' ? 'light' : 'dark');
};

const runPwaInstall = async () => {
    const installed = await installPwa();

    if (installed) {
        void showSuccessToast('Aplicativo instalado com sucesso.');
    }
};

const updatePwa = async () => {
    if (!window.__pwaUpdateSW) {
        return;
    }

    try {
        await window.__pwaUpdateSW(true);
    } catch {
        void showErrorToast('Não foi possível atualizar o aplicativo.');
    }
};

const handleNeedRefresh = () => {
    updateAvailable.value = true;
};

const handleOfflineReady = () => {
    void showSuccessToast('Modo offline pronto para uso.');
};

onMounted(() => {
    const savedTheme = window.localStorage.getItem('contacto-theme');

    if (savedTheme === 'light' || savedTheme === 'dark') {
        applyTheme(savedTheme);
    } else {
        const prefersDark = window.matchMedia(
            '(prefers-color-scheme: dark)',
        ).matches;

        applyTheme(prefersDark ? 'dark' : 'light');
    }

    window.addEventListener('pwa:need-refresh', handleNeedRefresh);
    window.addEventListener('pwa:offline-ready', handleOfflineReady);
});

onUnmounted(() => {
    window.removeEventListener('pwa:need-refresh', handleNeedRefresh);
    window.removeEventListener('pwa:offline-ready', handleOfflineReady);
});
</script>

<template>
    <div class="app-shell">
        <div class="app-layout">
            <aside class="app-sidebar">
                <div class="app-sidebar-brand">
                    <Link :href="route('dashboard')" class="w-full inline-flex items-center justify-center gap-3">
                        <ApplicationLogo class=" w-36 fill-current text-white" />
                        <div>
                        </div>
                    </Link>
                </div>

                <nav class="app-nav">
                    <Link
                        v-for="item in primaryNavigation"
                        :key="item.key"
                        :href="item.href"
                        class="app-nav-link"
                        :class="{ 'is-active': isActive(item.pattern) }"
                    >
                        <span class="app-nav-icon">
                            <svg
                                v-if="item.icon === 'dashboard'"
                                class="h-5 w-5"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                            >
                                <path d="M3 13h8V3H3zM13 21h8V11h-8zM13 3h8v6h-8zM3 21h8v-6H3z" />
                            </svg>
                            <svg
                                v-if="item.icon === 'clients'"
                                class="h-5 w-5"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                            >
                                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                                <circle cx="8.5" cy="7" r="4" />
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                            </svg>
                            <svg
                                v-if="item.icon === 'events'"
                                class="h-5 w-5"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                            >
                                <rect x="3" y="4" width="18" height="18" rx="2" />
                                <path d="M16 2v4M8 2v4M3 10h18" />
                            </svg>
                        </span>
                        <span>{{ item.label }}</span>
                    </Link>
                </nav>
            </aside>

            <div class="app-main">
                <div class="app-mobile-header lg:hidden">
                    <div class="flex h-16 items-center justify-between px-4">
                        <Link :href="route('dashboard')">
                            <ApplicationLogo class="app-mobile-logo" />
                        </Link>
                        <div class="app-mobile-header-actions">
                            <div class="app-mobile-pwa-actions">
                                <button
                                    v-if="canInstallPwa"
                                    type="button"
                                    class="app-mobile-install-btn"
                                    @click="runPwaInstall"
                                >
                                    Instalar app
                                </button>
                                <button
                                    v-if="updateAvailable"
                                    type="button"
                                    class="app-mobile-install-btn update"
                                    @click="updatePwa"
                                >
                                    Atualizar
                                </button>
                                <span
                                    v-if="isStandalonePwa"
                                    class="app-mobile-pwa-badge"
                                >
                                    PWA
                                </span>
                            </div>

                            <Dropdown
                                align="right"
                                width="48"
                                content-classes="app-header-dropdown app-mobile-dropdown"
                            >
                                <template #trigger>
                                    <button
                                        type="button"
                                        class="app-mobile-user-trigger"
                                    >
                                        <span class="dash-user-dot"></span>
                                        <span class="app-mobile-user-name">
                                            {{ $page.props.auth.user.name }}
                                        </span>
                                        <svg
                                            class="h-4 w-4"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                        >
                                            <path
                                                fill-rule="evenodd"
                                                d="M5.23 7.21a.75.75 0 011.06.02L10 11.13l3.71-3.9a.75.75 0 011.08 1.04l-4.25 4.46a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                    </button>
                                </template>

                                <template #content>
                                    <div class="app-header-dropdown-section">
                                        <p class="text-sm font-semibold text-current">
                                            {{ $page.props.auth.user.name }}
                                        </p>
                                        <p class="text-xs opacity-75">
                                            {{ $page.props.auth.user.email }}
                                        </p>
                                    </div>

                                    <button
                                        v-if="canInstallPwa"
                                        type="button"
                                        class="app-header-dropdown-item"
                                        @click="runPwaInstall"
                                    >
                                        <span>Instalar app</span>
                                    </button>

                                    <button
                                        v-if="updateAvailable"
                                        type="button"
                                        class="app-header-dropdown-item"
                                        @click="updatePwa"
                                    >
                                        <span>Atualizar app</span>
                                    </button>

                                    <button
                                        type="button"
                                        class="app-header-dropdown-item"
                                        @click="toggleTheme"
                                    >
                                        <svg
                                            v-if="isDarkTheme"
                                            class="h-4 w-4"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                        >
                                            <circle cx="12" cy="12" r="4" />
                                            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                                        </svg>
                                        <svg
                                            v-else
                                            class="h-4 w-4"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.8"
                                        >
                                            <path d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79z" />
                                        </svg>
                                        <span>{{ isDarkTheme ? 'Tema claro' : 'Tema escuro' }}</span>
                                    </button>

                                    <Link
                                        :href="route('profile.edit')"
                                        class="app-header-dropdown-item"
                                    >
                                        Perfil
                                    </Link>

                                    <Link
                                        :href="route('logout')"
                                        method="post"
                                        as="button"
                                        class="app-header-dropdown-item is-danger"
                                    >
                                        Sair
                                    </Link>
                                </template>
                            </Dropdown>
                        </div>
                    </div>
                </div>

                <header
                    class="app-page-header"
                    v-if="$slots.header"
                >
                    <div class="app-page-header-inner">
                        <div class="app-header-row">
                            <slot name="header" />

                            <div class="app-header-actions">
                                <label class="app-header-search">
                                    <svg
                                        class="h-4 w-4"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                    >
                                        <circle cx="11" cy="11" r="7" />
                                        <path d="M20 20l-3.5-3.5" />
                                    </svg>
                                    <input type="text" placeholder="Pesquisar..." />
                                </label>

                                <button
                                    v-if="canInstallPwa"
                                    type="button"
                                    class="app-header-pwa-btn"
                                    @click="runPwaInstall"
                                >
                                    Instalar app
                                </button>

                                <button
                                    v-if="updateAvailable"
                                    type="button"
                                    class="app-header-pwa-btn update"
                                    @click="updatePwa"
                                >
                                    Atualizar
                                </button>

                                <span
                                    v-if="isStandalonePwa"
                                    class="app-standalone-chip"
                                >
                                    PWA
                                </span>

                                <Dropdown
                                    align="right"
                                    width="48"
                                    content-classes="app-header-dropdown"
                                >
                                    <template #trigger>
                                        <button
                                            type="button"
                                            class="app-header-user-trigger"
                                        >
                                            <span class="dash-user-dot"></span>
                                            <span>{{ $page.props.auth.user.name }}</span>
                                            <svg
                                                class="h-4 w-4"
                                                viewBox="0 0 20 20"
                                                fill="currentColor"
                                            >
                                                <path
                                                    fill-rule="evenodd"
                                                    d="M5.23 7.21a.75.75 0 011.06.02L10 11.13l3.71-3.9a.75.75 0 011.08 1.04l-4.25 4.46a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"
                                                    clip-rule="evenodd"
                                                />
                                            </svg>
                                        </button>
                                    </template>

                                    <template #content>
                                        <div class="app-header-dropdown-section">
                                            <p class="text-sm font-semibold text-current">
                                                {{ $page.props.auth.user.name }}
                                            </p>
                                            <p class="text-xs opacity-75">
                                                {{ $page.props.auth.user.email }}
                                            </p>
                                        </div>

                                        <button
                                            v-if="canInstallPwa"
                                            type="button"
                                            class="app-header-dropdown-item"
                                            @click="runPwaInstall"
                                        >
                                            <span>Instalar app</span>
                                        </button>

                                        <button
                                            v-if="updateAvailable"
                                            type="button"
                                            class="app-header-dropdown-item"
                                            @click="updatePwa"
                                        >
                                            <span>Atualizar app</span>
                                        </button>

                                        <button
                                            type="button"
                                            class="app-header-dropdown-item"
                                            @click="toggleTheme"
                                        >
                                            <svg
                                                v-if="isDarkTheme"
                                                class="h-4 w-4"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="1.8"
                                            >
                                                <circle cx="12" cy="12" r="4" />
                                                <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                                            </svg>
                                            <svg
                                                v-else
                                                class="h-4 w-4"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="1.8"
                                            >
                                                <path d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79z" />
                                            </svg>
                                            <span>{{ isDarkTheme ? 'Tema claro' : 'Tema escuro' }}</span>
                                        </button>

                                        <Link
                                            :href="route('profile.edit')"
                                            class="app-header-dropdown-item"
                                        >
                                            Perfil
                                        </Link>

                                        <Link
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                            class="app-header-dropdown-item is-danger"
                                        >
                                            Sair
                                        </Link>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="app-content">
                    <slot />
                </main>
            </div>
        </div>

        <nav
            class="app-mobile-nav lg:hidden"
            style="padding-bottom: calc(env(safe-area-inset-bottom) + 0.4rem)"
        >
            <ul
                class="grid gap-1"
                :class="{
                    'grid-cols-2': mobileNavigation.length === 2,
                    'grid-cols-3': mobileNavigation.length === 3,
                    'grid-cols-4': mobileNavigation.length === 4,
                }"
            >
                <li v-for="item in mobileNavigation" :key="item.key">
                    <Link
                        :href="item.href"
                        class="app-mobile-nav-link"
                        :class="{ 'is-active': isActive(item.pattern) }"
                    >
                        <svg
                            v-if="item.icon === 'dashboard'"
                            class="mb-1 h-5 w-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                        >
                            <path d="M3 13h8V3H3zM13 21h8V11h-8zM13 3h8v6h-8zM3 21h8v-6H3z" />
                        </svg>
                        <svg
                            v-if="item.icon === 'clients'"
                            class="mb-1 h-5 w-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                        >
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                            <circle cx="8.5" cy="7" r="4" />
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                        </svg>
                        <svg
                            v-if="item.icon === 'events'"
                            class="mb-1 h-5 w-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                        >
                            <rect x="3" y="4" width="18" height="18" rx="2" />
                            <path d="M16 2v4M8 2v4M3 10h18" />
                        </svg>
                        <svg
                            v-if="item.icon === 'profile'"
                            class="mb-1 h-5 w-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                        >
                            <circle cx="12" cy="8" r="4" />
                            <path d="M4 21a8 8 0 0 1 16 0" />
                        </svg>
                        <span>{{ item.label }}</span>
                    </Link>
                </li>
            </ul>
        </nav>
    </div>
</template>
