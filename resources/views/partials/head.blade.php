{{--
    Site-wide icons and link-preview metadata.

    Included by the Inertia app shell *and* by the error layouts. The error pages
    render outside the SPA, so without this they fell back to /favicon.ico - which
    used to be the Laravel skeleton's icon, not ours.

    Every URL here is absolute (asset() resolves against APP_URL/ASSET_URL): WhatsApp,
    Slack and other scrapers discard relative og:image values, which is why link
    previews showed a generic icon instead of the product mark.
--}}

@php
    /**
     * Browsers cache a favicon far more stubbornly than any other asset: they will
     * keep showing an old one long after the file on disk has changed, and a plain
     * reload does not clear it. Appending the file's own modification time gives the
     * URL a new identity exactly when, and only when, the icon itself changes.
     */
    $icon = static fn (string $file): string => asset($file)
        .'?v='.(is_file($path = public_path($file)) ? filemtime($path) : 1);

    /**
     * The landing page is the only page here that is meant to be found. Everything
     * else this partial reaches - the signed in application, the credential screens,
     * the error pages - is either behind authentication or a dead end, and indexing
     * it puts the shape of a private instance into a public search result.
     *
     * robots.txt already asks crawlers not to fetch those URLs; this is the half of
     * the instruction that survives someone linking to one directly, because a page
     * a crawler never fetches is a page whose Disallow it cannot read.
     */
    $isIndexable = request()->routeIs('home') && ! config('pulllens.homepage_login');
@endphp

{{-- Browser tab and home-screen icons --}}
<link rel="icon" href="{{ $icon('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="{{ $icon('favicon-32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ $icon('favicon-16.png') }}">
<link rel="icon" type="image/png" sizes="512x512" href="{{ $icon('favicon.png') }}">
<link rel="apple-touch-icon" href="{{ $icon('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ $icon('site.webmanifest') }}">
<meta name="theme-color" content="{{ config('pulllens.meta.theme_color') }}">

{{--
    Indexing. The max-* directives opt the landing page into full-size preview
    images and untruncated snippets: Google and Bing shorten both by default, and a
    truncated snippet is the difference between a generated answer quoting the page
    and paraphrasing somebody else's description of it.
--}}
@if ($isIndexable)
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}/">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">

    {{--
        Rendered here rather than from the React page's <Head>. Inertia writes that
        one after hydration, which is fine for a browser and useless for everything
        that reads HTML without running it - every link-preview scraper, and most
        of the assistant crawlers. The page description has to be in the response.
    --}}
    <meta name="description" content="{{ config('pulllens.meta.description') }}">
    <meta name="keywords" content="{{ config('pulllens.meta.keywords') }}">
@else
    <meta name="robots" content="noindex, nofollow">
@endif

{{-- Link previews (Open Graph is what WhatsApp, Slack and LinkedIn read) --}}
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:locale" content="{{ config('pulllens.meta.locale') }}">
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
