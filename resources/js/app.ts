import '../css/app.css';
import './bootstrap';
import 'sweetalert2/dist/sweetalert2.min.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { registerSW } from 'virtual:pwa-register';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

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
