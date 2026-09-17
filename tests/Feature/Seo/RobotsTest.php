<?php

/*
| robots.txt is the one file here nobody looks at again after it is written, and
| the one that quietly puts a login screen into a search index when it is wrong.
*/

test('robots.txt is served by the application, not by a stale file in public', function () {
    // A file in public/ is served by the web server before PHP is ever reached, so
    // one left behind would silently win over every rule below.
    expect(file_exists(public_path('robots.txt')))->toBeFalse();

    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
});

test('the landing page and the guide are the only crawlable paths', function () {
    $body = $this->get('/robots.txt')->getContent();

    expect($body)
        ->toContain('Allow: /$')
        ->toContain('Allow: /docs');
});

test('every signed in and credential path is disallowed', function (string $path) {
    expect($this->get('/robots.txt')->getContent())->toContain("Disallow: {$path}");
})->with([
    '/login',
    '/logout',
    '/forgot-password',
    '/reset-password',
    '/two-factor-challenge',
    '/dashboard',
    '/repositories',
    '/findings',
    '/tasks',
    '/reports',
    '/assistant',
    '/admin',
    '/settings',
    '/user/',
    '/horizon',
    '/telescope',
    '/webhooks/',
]);

test('the compiled assets stay crawlable so the page can be rendered', function () {
    // Google ranks what it renders. Disallowing /build hides the stylesheet and the
    // bundle, and the crawler judges an unstyled document.
    expect($this->get('/robots.txt')->getContent())->not->toContain('Disallow: /build');
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
        ->not->toContain('Allow: /docs');
});
