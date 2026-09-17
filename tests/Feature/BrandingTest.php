<?php

use App\Models\Users\User;

/*
| The favicon and the link-preview card are easy to break and nobody notices until
| a link is already shared. These pin both.
*/

test('the app shell serves the product icon, not a framework default', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('favicon.ico', escape: false)
        ->assertSee('apple-touch-icon.png', escape: false);
});

test('error pages carry the same icon as the app', function () {
    // Error views render outside the SPA; without the shared partial they fall back
    // to /favicon.ico with no icon declared at all.
    config()->set('app.debug', false);

    $response = $this->get('/a-route-that-does-not-exist');

    $response->assertNotFound()
        ->assertSee('favicon.ico', escape: false)
        ->assertSee('apple-touch-icon.png', escape: false);
});

test('the link preview image is an absolute url', function () {
    // WhatsApp, Slack and LinkedIn discard a relative og:image outright, which is
    // what made shared links fall back to a generic icon.
    config()->set('app.url', 'https://pulllens.example.com');
    config()->set('app.asset_url', 'https://pulllens.example.com');

    $this->get('/login')
        ->assertOk()
        ->assertSee('https://pulllens.example.com/og-image.png', escape: false);
});

test('the preview card declares the tags scrapers need', function () {
    $content = $this->get('/login')->assertOk()->getContent();

    foreach ([
        'property="og:title"',
        'property="og:description"',
        'property="og:image"',
        'property="og:image:width"',
        'property="og:url"',
        'name="twitter:card"',
    ] as $tag) {
        expect($content)->toContain($tag);
    }
});

test('the preview tags are not duplicated on the landing page', function () {
    $content = $this->get('/')->assertOk()->getContent();

    expect(substr_count($content, 'property="og:image"'))->toBe(1)
        ->and(substr_count($content, 'name="twitter:image"'))->toBe(1);
});

test('authenticated pages also carry the icon', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/dashboard')->assertOk()->assertSee('favicon.ico', escape: false);
});

test('the browser icons have no plate behind them', function () {
    // The icon set used to be exported onto an opaque white square, which showed as
    // a white box around the mark on every dark browser theme and home screen.
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('gd is not installed in this environment.');
    }

    foreach (['favicon.png', 'favicon-32.png', 'favicon-16.png', 'icon-192.png', 'icon-512.png'] as $icon) {
        $image = imagecreatefrompng(public_path($icon));
        $corner = imagecolorsforindex($image, imagecolorat($image, 0, 0));
        imagedestroy($image);

        // 127 is fully transparent in GD's inverted alpha scale.
        expect($corner['alpha'])->toBe(127, "{$icon} has an opaque corner");
    }
});

test('icon urls change when the icon file does', function () {
    // A browser will keep showing a cached favicon through reloads and deploys.
    // The file's own mtime in the query string is what forces it to fetch again.
    $content = $this->get('/login')->assertOk()->getContent();

    foreach (['favicon.ico', 'favicon-32.png', 'apple-touch-icon.png'] as $icon) {
        $version = filemtime(public_path($icon));

        expect($content)->toContain($icon.'?v='.$version);
    }
});
