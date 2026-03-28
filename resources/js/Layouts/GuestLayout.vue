<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="guest-shell">
        <div aria-hidden="true" class="guest-aurora guest-aurora-left"></div>
        <div aria-hidden="true" class="guest-aurora guest-aurora-right"></div>

        <div class="guest-frame">
            <Link href="/" class="guest-brand">
                <span class="guest-brand-mark">
                    <img
                        src="/images/logo2.png"
                        alt="Contacto Digital"
                        class="guest-logo"
                    />
                </span>

                <span>
                    <span class="guest-brand-title">Contacto Digital</span>
                    <span class="guest-brand-subtitle">
                        Gestao moderna para clientes e eventos
                    </span>
                </span>
            </Link>

            <div class="guest-card">
                <slot />
            </div>
        </div>
    </div>
</template>
