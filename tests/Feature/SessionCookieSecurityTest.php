<?php

use App\Providers\AppServiceProvider;

/*
| A Secure session cookie is dropped by the browser when the page arrived over plain
| http://, taking the session and the CSRF token with it, so every POST - the login
| form first - comes back 419. Production still forces the flag on behind HTTPS; on a
| declared plain-HTTP instance the operator's setting is left alone so logging in works.
*/

function bootSecurityFor(string $environment, string $appUrl, ?bool $secureCookie = null): void
{
    config([
        'app.url' => $appUrl,
        'session.secure' => $secureCookie,
    ]);

    app()->detectEnvironment(fn (): string => $environment);

    (new AppServiceProvider(app()))->boot();
}

it('forces the session cookie Secure in production behind HTTPS', function (): void {
    bootSecurityFor('production', 'https://pulllens.example.com', false);

    expect(config('session.secure'))->toBeTrue();
});

it('leaves the session cookie flag alone on a plain-HTTP production instance', function (): void {
    bootSecurityFor('production', 'http://203.0.113.10', false);

    expect(config('session.secure'))->toBeFalse();
});

it('does not force HTTPS on generated URLs when APP_URL is plain HTTP', function (): void {
    bootSecurityFor('production', 'http://203.0.113.10', false);

    expect(url('/login'))->toStartWith('http://');
});

it('forces HTTPS on generated URLs when APP_URL is HTTPS', function (): void {
    bootSecurityFor('production', 'https://pulllens.example.com', true);

    expect(url('/login'))->toStartWith('https://');
});

it('does not touch the session cookie flag outside production', function (): void {
    bootSecurityFor('local', 'https://pulllens.example.com', false);

    expect(config('session.secure'))->toBeFalse();
});
