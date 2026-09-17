{{--
    The shared layout for every error page.

    Deliberately self-contained: no @vite, no Inertia, no React. An error page is
    needed at exactly the moments the rest of the application cannot be relied on -
    a 500 with a broken container, a 503 while `artisan down` is holding the door,
    a 404 served before the asset build has run. Anything that depends on the
    front-end build would render a blank page precisely then, so the styles are
    inlined and the only external file is the product mark.

    The palette is the same set of tokens as resources/css/app.css, copied rather
    than imported for the reason above. They are few and they are stable; if the
    theme ever moves, the four values at the top of the <style> block move with it.
--}}
@php
    /**
     * `$appearance` is shared by HandleAppearance, but that middleware does not run
     * when maintenance mode short-circuits the request, so it cannot be assumed.
     * 'system' falls through to prefers-color-scheme, which is the right default.
     */
    $appearance = $appearance ?? 'system';

    // A private instance withdraws the landing page and the guide (see
    // EnsurePublicPagesAreEnabled). Offering a visitor a link to either would be
    // sending them to another error.
    $publicPagesEnabled = ! config('pulllens.homepage_login');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $appearance === 'dark', 'light' => $appearance === 'light'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title') · {{ config('app.name') }}</title>

        @include('partials.head')

        <style>
            :root {
                --background: oklch(1 0 0);
                --foreground: oklch(0.145 0 0);
                --muted-foreground: oklch(0.556 0 0);
                --border: oklch(0.922 0 0);
                --card: oklch(1 0 0);
                --primary: oklch(0.205 0 0);
                --primary-foreground: oklch(0.985 0 0);
                --accent: oklch(0.97 0 0);
                --radius: 0.625rem;
                --mark-filter: none;
            }

            /*
                Two selectors, one palette. `.dark` honours the appearance a signed
                in user chose, which arrives on the root element above. The media
                query covers everyone else - including a visitor who has never had a
                session here - but is excluded on `.light`, because someone who
                explicitly asked for the light theme has overruled their OS.
            */
            :root { color-scheme: light; }
            .dark { color-scheme: dark; }

            @media (prefers-color-scheme: dark) {
                :root:not(.light) {
                    color-scheme: dark;
                    --background: oklch(0.145 0 0);
                    --foreground: oklch(0.985 0 0);
                    --muted-foreground: oklch(0.708 0 0);
                    --border: oklch(0.269 0 0);
                    --card: oklch(0.185 0 0);
                    --primary: oklch(0.985 0 0);
                    --primary-foreground: oklch(0.205 0 0);
                    --accent: oklch(0.269 0 0);
                    /* The mark is black artwork on transparency, so it vanishes on
                       a dark surface unless it is inverted. */
                    --mark-filter: invert(1);
                }
            }

            .dark {
                --background: oklch(0.145 0 0);
                --foreground: oklch(0.985 0 0);
                --muted-foreground: oklch(0.708 0 0);
                --border: oklch(0.269 0 0);
                --card: oklch(0.185 0 0);
                --primary: oklch(0.985 0 0);
                --primary-foreground: oklch(0.205 0 0);
                --accent: oklch(0.269 0 0);
                --mark-filter: invert(1);
            }

            *,
            *::before,
            *::after {
                box-sizing: border-box;
            }

            html {
                background-color: var(--background);
                -webkit-text-size-adjust: 100%;
            }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                background-color: var(--background);
                color: var(--foreground);
                font-family: 'Instrument Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                font-size: 16px;
                line-height: 1.6;
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
            }

            /*
                A single soft wash behind the card. Keeps the page from reading as a
                blank server default without adding an image request that may well
                be the thing that is broken.
            */
            body::before {
                content: '';
                position: fixed;
                inset: 0;
                pointer-events: none;
                background:
                    radial-gradient(60rem 30rem at 50% -10%, color-mix(in oklch, var(--foreground) 6%, transparent), transparent 70%);
            }

            main {
                position: relative;
                flex: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 2rem 1.5rem;
            }

            .panel {
                width: 100%;
                max-width: 34rem;
                text-align: center;
            }

            .mark {
                width: 3rem;
                height: 3rem;
                margin: 0 auto 2rem;
                display: block;
                filter: var(--mark-filter);
            }

            /*
                The status code as a quiet label rather than a six-inch numeral. The
                number matters to whoever is debugging; the sentence underneath is
                what the visitor actually needs.
            */
            .code {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                margin: 0 0 1.25rem;
                padding: 0.3rem 0.75rem;
                border: 1px solid var(--border);
                border-radius: 999px;
                background-color: var(--card);
                color: var(--muted-foreground);
                font-size: 0.75rem;
                font-weight: 600;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .code::before {
                content: '';
                width: 0.375rem;
                height: 0.375rem;
                border-radius: 999px;
                background-color: currentColor;
                opacity: 0.6;
            }

            h1 {
                margin: 0 0 0.75rem;
                font-size: 1.875rem;
                line-height: 1.25;
                font-weight: 600;
                letter-spacing: -0.02em;
            }

            @media (min-width: 640px) {
                h1 {
                    font-size: 2.25rem;
                }
            }

            .explanation {
                margin: 0 auto;
                max-width: 30rem;
                color: var(--muted-foreground);
                text-wrap: pretty;
            }

            .actions {
                margin-top: 2rem;
                display: flex;
                flex-wrap: wrap;
                gap: 0.75rem;
                justify-content: center;
            }

            .button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0.625rem 1.125rem;
                border: 1px solid var(--border);
                border-radius: var(--radius);
                background-color: transparent;
                color: var(--foreground);
                font-size: 0.9375rem;
                font-weight: 500;
                text-decoration: none;
                transition: background-color 150ms ease, border-color 150ms ease;
            }

            .button:hover {
                background-color: var(--accent);
            }

            .button--primary {
                border-color: var(--primary);
                background-color: var(--primary);
                color: var(--primary-foreground);
            }

            .button--primary:hover {
                opacity: 0.9;
                background-color: var(--primary);
            }

            .button:focus-visible,
            footer a:focus-visible {
                outline: 2px solid var(--foreground);
                outline-offset: 2px;
            }

            /*
                The same legal line the landing page ends on, so an error page is
                still recognisably part of the product rather than a bare server
                response. Stacked on a phone, split left and right from 640px up -
                which is how the landing page footer behaves.
            */
            footer {
                position: relative;
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
                padding: 1.5rem;
                border-top: 1px solid var(--border);
                color: var(--muted-foreground);
                font-size: 0.75rem;
                text-align: center;
            }

            @media (min-width: 640px) {
                footer {
                    flex-direction: row;
                    align-items: center;
                    justify-content: space-between;
                    text-align: left;
                }
            }

            footer p {
                margin: 0;
            }

            footer a {
                color: color-mix(in oklch, var(--foreground) 80%, transparent);
                font-weight: 500;
                text-decoration: none;
                text-underline-offset: 4px;
                transition: color 150ms ease;
            }

            footer a:hover {
                color: var(--foreground);
                text-decoration: underline;
            }

            @media (prefers-reduced-motion: reduce) {
                * {
                    transition: none !important;
                }
            }
        </style>
    </head>
    <body>
        <main role="main">
            <div class="panel">
                <img class="mark" src="{{ asset('favicon.png') }}" alt="" width="48" height="48">

                <p class="code">@yield('code') @yield('title')</p>

                <h1>@yield('headline')</h1>

                <p class="explanation">@yield('explanation')</p>

                <div class="actions">
                    @hasSection('actions')
                        @yield('actions')
                    @else
                        @if ($publicPagesEnabled)
                            <a class="button button--primary" href="{{ route('home') }}">{{ __('Back to the home page') }}</a>
                            <a class="button" href="{{ route('docs') }}">{{ __('Read the guide') }}</a>
                        @else
                            <a class="button button--primary" href="{{ url('/') }}">{{ __('Back to the home page') }}</a>
                        @endif
                    @endif
                </div>
            </div>
        </main>

        <footer>
            <p>&copy; {{ date('Y') }} <a href="https://vlancy.com" target="_blank" rel="noreferrer">Vlancy LTD</a>. {{ __('All rights reserved.') }}</p>
            <p>{{ __('MIT licence with the Commons Clause') }} &middot; {{ __('Self-hosted') }} &middot; {{ __('No telemetry') }}</p>
        </footer>
    </body>
</html>
