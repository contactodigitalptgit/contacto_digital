<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="guest-shell">
        <div aria-hidden="true" class="guest-aurora guest-aurora-left"></div>
        <div aria-hidden="true" class="guest-aurora guest-aurora-right"></div>

        <div class="guest-frame">
            <section class="guest-intro">
                <Link href="/" class="guest-brand" aria-label="Contacto Digital">
                    <span class="guest-brand-mark">
                        <img
                            src="/images/logo-contacto-digital.webp"
                            alt="Contacto Digital"
                            class="guest-logo"
                        />
                    </span>

                    <span>
                        <span class="guest-brand-title">Contacto Digital</span>
                        <span class="guest-brand-subtitle">
                            Cashless · Eventos · Reporting
                        </span>
                    </span>
                </Link>

                <div class="guest-intro-copy">
                    <p class="guest-intro-eyebrow">Controlo operacional</p>
                    <h2>Os dados do evento, com clareza.</h2>
                    <p>
                        Acompanhe vendas, pagamentos, produtos e zonas numa visão
                        segura e preparada para decisões em tempo real.
                    </p>
                </div>

                <div class="guest-security-note">
                    <span aria-hidden="true"></span>
                    <p>
                        <strong>Acesso protegido</strong>
                        Área reservada a clientes e administradores.
                    </p>
                </div>
            </section>

            <main class="guest-card">
                <slot />
            </main>
        </div>
    </div>
</template>
