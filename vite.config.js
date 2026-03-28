import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

const iconSizes = [48, 72, 96, 128, 144, 152, 192, 256, 384, 512];
const iconAssets = iconSizes.map((size) => `icons/icon-${size}x${size}.png`);
const manifestIcons = iconSizes.map((size) => ({
    src: `icons/icon-${size}x${size}.png`,
    sizes: `${size}x${size}`,
    type: 'image/png',
}));

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.ts',
            ssr: 'resources/js/ssr.ts',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        VitePWA({
            registerType: 'autoUpdate',
            includeAssets: [
                'favicon.ico',
                'apple-touch-icon.png',
                'apple-touch-icon-152x152.png',
                'pwa-192x192.png',
                'pwa-512x512.png',
                ...iconAssets,
            ],
            manifest: {
                name: 'Contacto Digital',
                short_name: 'Contacto',
                description: 'Gestao de clientes e eventos',
                theme_color: '#1f2937',
                background_color: '#ffffff',
                display: 'standalone',
                start_url: '/dashboard',
                scope: '/',
                lang: 'pt-PT',
                icons: manifestIcons,
            },
        }),
    ],
});
