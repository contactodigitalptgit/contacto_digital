<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#1f2937">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="Contacto">

        <script>
            (() => {
                const key = 'contacto-theme';

                try {
                    const savedTheme = localStorage.getItem(key);
                    const shouldUseDark = savedTheme
                        ? savedTheme === 'dark'
                        : window.matchMedia('(prefers-color-scheme: dark)').matches;

                    document.documentElement.setAttribute(
                        'data-theme',
                        shouldUseDark ? 'dark' : 'light',
                    );
                } catch (error) {
                    document.documentElement.setAttribute('data-theme', 'light');
                }
            })();
        </script>

        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
