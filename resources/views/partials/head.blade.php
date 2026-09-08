{{--
    Site-wide icons and link-preview metadata.

    Included by the Inertia app shell *and* by the error layouts. The error pages
    render outside the SPA, so without this they fell back to /favicon.ico - which
    used to be the Laravel skeleton's icon, not ours.

    Every URL here is absolute (asset() resolves against APP_URL/ASSET_URL): WhatsApp,
    Slack and other scrapers discard relative og:image values, which is why link
    previews showed a generic icon instead of the product mark.
--}}

{{-- Browser tab and home-screen icons --}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16.png') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}">
<meta name="theme-color" content="{{ config('pulllens.meta.theme_color') }}">

{{-- Link previews (Open Graph is what WhatsApp, Slack and LinkedIn read) --}}
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:title" content="{{ config('pulllens.meta.title') }}">
<meta property="og:description" content="{{ config('pulllens.meta.description') }}">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:image" content="{{ asset(config('pulllens.meta.image')) }}">
<meta property="og:image:type" content="image/png">
{{-- Dimensions let a scraper lay out the card before the image finishes downloading. --}}
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ config('pulllens.meta.image_alt') }}">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ config('pulllens.meta.title') }}">
<meta name="twitter:description" content="{{ config('pulllens.meta.description') }}">
<meta name="twitter:image" content="{{ asset(config('pulllens.meta.image')) }}">
