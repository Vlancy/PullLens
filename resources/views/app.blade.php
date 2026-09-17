<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        @include('partials.head')

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        {{--
            The landing page's title is its most valuable line of metadata, so it is
            in the response rather than written by Inertia after hydration. Every
            other page keeps the bare product name; they are all noindex anyway, and
            each one sets its own title through <Head>.
        --}}
        <x-inertia::head>
            <title>{{ request()->routeIs('home') ? config('pulllens.meta.title') : config('app.name', 'Laravel') }}</title>
        </x-inertia::head>

        @includeWhen(request()->routeIs('home'), 'partials.structured-data')

        @includeWhen(
            request()->routeIs('home')
                && config('pulllens.analytics.matomo.url')
                && config('pulllens.analytics.matomo.site_id'),
            'partials.analytics'
        )
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
