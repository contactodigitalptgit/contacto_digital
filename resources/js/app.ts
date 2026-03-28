import '../css/app.css';
import './bootstrap';
import 'sweetalert2/dist/sweetalert2.min.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { registerSW } from 'virtual:pwa-register';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const legacySwCleanupKey = 'contacto-legacy-sw-cleanup';

async function cleanupLegacyServiceWorkers(): Promise<void> {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    const registrations = await navigator.serviceWorker.getRegistrations();
    const legacyRegistrations = registrations.filter((registration) => {
        const worker =
            registration.active ?? registration.waiting ?? registration.installing;

        if (!worker) {
            return new URL(registration.scope).pathname === '/';
        }

        return new URL(worker.scriptURL).pathname === '/sw.js';
    });

    if (legacyRegistrations.length === 0) {
        sessionStorage.removeItem(legacySwCleanupKey);
        return;
    }

    await Promise.all(legacyRegistrations.map((registration) => registration.unregister()));

    if ('caches' in window) {
        const cacheNames = await caches.keys();
        await Promise.all(cacheNames.map((cacheName) => caches.delete(cacheName)));
    }

    if (sessionStorage.getItem(legacySwCleanupKey) === 'done') {
        return;
    }

    sessionStorage.setItem(legacySwCleanupKey, 'done');
    window.location.reload();
}

cleanupLegacyServiceWorkers().catch(() => {
    // Ignore cleanup failures and continue booting the app normally.
});

const updateSW = registerSW({
    immediate: true,
    onNeedRefresh() {
        window.dispatchEvent(new CustomEvent('pwa:need-refresh'));
    },
    onOfflineReady() {
        window.dispatchEvent(new CustomEvent('pwa:offline-ready'));
    },
});

window.__pwaUpdateSW = updateSW;

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
