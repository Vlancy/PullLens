{{--
    Matomo, on the landing page only.

    Included from app.blade.php behind a check on the current route name, so it is
    never emitted on a signed in page: no third-party script is ever in a position
    to observe a customer's repositories, findings or team data. It renders at all
    only when both MATOMO_URL and MATOMO_SITE_ID are set, so a fresh installation
    is silent rather than reporting to somebody else's instance.

    The landing page is the one full document load in the flow. Moving from it to
    the login screen is an Inertia visit, which does not run this again, so exactly
    one page view is recorded.
--}}
@php
    $matomoUrl = rtrim((string) config('pulllens.analytics.matomo.url'), '/').'/';
    $matomoSiteId = (string) config('pulllens.analytics.matomo.site_id');
@endphp

<!-- Matomo -->
<script>
    var _paq = window._paq = window._paq || [];
    _paq.push(['trackPageView']);
    _paq.push(['enableLinkTracking']);
    (function() {
        var u = @json($matomoUrl, JSON_UNESCAPED_SLASHES);
        _paq.push(['setTrackerUrl', u + 'matomo.php']);
        _paq.push(['setSiteId', @json($matomoSiteId)]);
        var d = document, g = d.createElement('script'), s = d.getElementsByTagName('script')[0];
        g.async = true; g.src = u + 'matomo.js'; s.parentNode.insertBefore(g, s);
    })();
</script>
<!-- End Matomo Code -->
