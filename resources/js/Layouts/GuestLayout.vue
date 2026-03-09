<script setup lang="ts">
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="guest-shell">
        <div>
            <Link href="/">
                <ApplicationLogo class="guest-logo" />
            </Link>
        </div>

        <div class="guest-card">
            <slot />
        </div>
    </div>
</template>
