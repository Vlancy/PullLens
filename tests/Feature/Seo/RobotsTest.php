<?php

/*
| robots.txt is the one file here nobody looks at again after it is written, and
| the one that quietly puts a login screen into a search index when it is wrong.
| It is also the first file anyone scanning the instance reads, so what it does
| not say matters as much as what it does.
*/

test('robots.txt is served by the application, not by a stale file in public', function () {
    // A file in public/ is served by the web server before PHP is ever reached, so
    // one left behind would silently win over every rule below.
    expect(file_exists(public_path('robots.txt')))->toBeFalse();

    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
});

test('everything is closed unless it is named', function () {
    $body = $this->get('/robots.txt')->getContent();

    expect($body)
        ->toContain('Disallow: /')
        ->toContain('Allow: /$')
        ->toContain('Allow: /docs');
});

test('the file does not publish the shape of the application', function (string $path) {
    // An allow list keeps the admin area, the reports, the credential flows and
    // the queue dashboard out of a file the whole internet is invited to read.
    expect($this->get('/robots.txt')->getContent())->not->toContain($path);
})->with([
    '/login',
    '/logout',
    '/forgot-password',
    '/reset-password',
    '/two-factor-challenge',
    '/passkeys',
    '/dashboard',
    '/repositories',
    '/findings',
    '/tasks',
    '/reports',
    '/assistant',
    '/admin',
    '/settings',
    '/horizon',
    '/telescope',
    '/webhooks',
    '/storage',
]);

test('the compiled assets stay crawlable so the page can be rendered', function () {
    // Google ranks what it renders. Leaving /build closed hides the stylesheet and
    // the bundle, and the crawler judges an unstyled document.
    expect($this->get('/robots.txt')->getContent())->toContain('Allow: /build/');
});

test('the link preview image and the icons are reachable', function () {
    $body = $this->get('/robots.txt')->getContent();

    expect($body)
        ->toContain('Allow: /og-image.png')
        ->toContain('Allow: /site.webmanifest')
        ->toContain('Allow: /favicon.ico');
});

test('the crawler files are allowed to be fetched', function () {
    $body = $this->get('/robots.txt')->getContent();

    expect($body)
        ->toContain('Allow: /sitemap.xml')
        ->toContain('Allow: /llms.txt');
});

test('the sitemap is advertised at an absolute url', function () {
    config()->set('app.url', 'https://pulllens.example.com');

    expect($this->get('/robots.txt')->getContent())
        ->toContain('Sitemap: https://pulllens.example.com/sitemap.xml');
});

test('the assistant crawlers are named so the permission is deliberate', function () {
    $body = $this->get('/robots.txt')->getContent();

    expect($body)
        ->toContain('User-agent: GPTBot')
        ->toContain('User-agent: ClaudeBot')
        ->toContain('User-agent: PerplexityBot')
        ->toContain('User-agent: Google-Extended');
});

test('a private instance withdraws itself from every index', function () {
    config()->set('pulllens.homepage_login', true);

    $body = $this->get('/robots.txt')->assertOk()->getContent();

    expect($body)
        ->toContain("User-agent: *\nDisallow: /")
        ->not->toContain('Sitemap:')
        ->not->toContain('Allow:');
});
