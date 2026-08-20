<script setup lang="ts">
import type { PageProps } from '@/types';
import { computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
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

const isActive = (item: NavItem) =>
    route().current(item.pattern)
    || (item.key === 'dashboard' && route().current('events.dashboard'));

</script>

<template>
    <div class="app-shell">
        <div class="app-layout">
            <aside class="app-sidebar">
                <div class="app-sidebar-brand">
                    <Link :href="route('dashboard')" class="contacto-sidebar-brand-link">
                        <img src="/images/logo2.png" alt="Contacto Digital" class="contacto-sidebar-logo" />
                    </Link>
                    <span v-if="isAdmin" class="contacto-sidebar-mode">Modo administrador</span>
                </div>

                <nav class="app-nav">
                    <Link
                        v-for="item in primaryNavigation"
                        :key="item.key"
                        :href="item.href"
                        class="app-nav-link"
                        :class="{ 'is-active': isActive(item) }"
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

                    <slot name="sidebar-navigation" />
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
                        <span>{{ item.label }}</span>
                    </Link>
                </li>
            </ul>
        </nav>
    </div>
</template>
