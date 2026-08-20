<script setup lang="ts">
defineProps<{
    name: 'dashboard' | 'products' | 'payments' | 'zones' | 'performance' | 'events' | 'clients' | 'compare' | 'edit';
}>();
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.7"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <template v-if="name === 'dashboard'">
            <path d="M4 14a8 8 0 0 1 16 0" />
            <path d="m12 14 4-4" />
        </template>

        <template v-else-if="name === 'products'">
            <path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z" />
            <path d="m4.3 7.7 7.7 4.4 7.7-4.4M12 12.1V21" />
        </template>

        <template v-else-if="name === 'payments'">
            <path d="M4 6.5h13a2 2 0 0 1 2 2v1" />
            <path d="M4 6.5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h15a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2h-4a2 2 0 0 0 0 4h6" />
            <path d="M16 11.5h.01" />
        </template>

        <template v-else-if="name === 'zones'">
            <path d="m12 3 8 4-8 4-8-4 8-4Z" />
            <path d="m4 12 8 4 8-4M4 17l8 4 8-4" />
        </template>

        <template v-else-if="name === 'performance'">
            <path d="M4 19V9M9 19V5M14 19v-7M19 19V3" />
            <path d="M2 19h20" />
        </template>

        <template v-else-if="name === 'events'">
            <rect x="3" y="5" width="18" height="16" rx="2" />
            <path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01" />
        </template>

        <template v-else-if="name === 'clients'">
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
        </template>

        <template v-else-if="name === 'compare'">
            <circle cx="7" cy="7" r="2" />
            <circle cx="17" cy="17" r="2" />
            <path d="M7 9v8a2 2 0 0 0 2 2h6M17 15V7a2 2 0 0 0-2-2H9" />
        </template>

        <template v-else>
            <path d="m4 16-.8 4.8L8 20l10.4-10.4a2.1 2.1 0 0 0-3-3L5 17Z" />
            <path d="m13.8 8.2 3 3" />
        </template>
    </svg>
</template>
