<script setup lang="ts">
import type { PageProps } from '@/types';
import { computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import AppSidebarIcon from '@/Components/AppSidebarIcon.vue';
import Dropdown from '@/Components/Dropdown.vue';
import { Link, usePage } from '@inertiajs/vue3';

interface NavItem {
    key: string;
    label: string;
    href: string;
    pattern: string;
    icon: 'dashboard' | 'clients' | 'events';
}

const page = usePage<PageProps>();
const isAdmin = computed(() => page.props.auth.user.role === 'admin');

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

const mobileNavigation = computed<NavItem[]>(() => primaryNavigation.value);

const isEventDashboard = () => route().current('events.dashboard') || route().current('admin.events.dashboard');

const isActive = (item: NavItem) => {
    if (isEventDashboard()) {
        return item.key === 'dashboard';
    }

    return route().current(item.pattern);
};

</script>

<template>
    <div class="app-shell">
        <div class="app-layout">
            <aside class="app-sidebar">
                <div class="app-sidebar-brand">
                    <Link :href="route('dashboard')" class="contacto-sidebar-brand-link">
                        <img src="/images/logo-contacto-digital.webp" alt="Contacto Digital" class="contacto-sidebar-logo" />
                    </Link>
                    <span v-if="isAdmin" class="contacto-sidebar-mode">Modo administrador</span>
                </div>

                <nav class="app-nav">
                    <slot
                        v-if="$slots['sidebar-navigation']"
                        name="sidebar-navigation"
                        :is-admin="isAdmin"
                    />

                    <template v-else>
                        <Link
                            v-for="item in primaryNavigation"
                            :key="item.key"
                            :href="item.href"
                            class="app-nav-link"
                            :class="{ 'is-active': isActive(item) }"
                        >
                            <span class="app-nav-icon">
                                <AppSidebarIcon :name="item.icon" class="h-5 w-5" />
                            </span>
                            <span>{{ item.label }}</span>
                        </Link>
                    </template>
                </nav>
            </aside>

            <div class="app-main">
                <div class="app-mobile-header lg:hidden">
                    <div class="flex h-16 items-center justify-between px-4">
                        <Link :href="route('dashboard')">
                            <ApplicationLogo class="app-mobile-logo" />
                        </Link>
                        <div class="app-mobile-header-actions">
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

                                    <Link
                                        v-if="isAdmin"
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

                                        <Link
                                            v-if="isAdmin"
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
                        :class="{ 'is-active': isActive(item) }"
                    >
                        <AppSidebarIcon :name="item.icon" class="mb-1 h-5 w-5" />
                        <span>{{ item.label }}</span>
                    </Link>
                </li>
            </ul>
        </nav>
    </div>
</template>
