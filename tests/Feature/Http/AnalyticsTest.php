<?php

use App\Models\Users\User;

/*
| Matomo belongs on the public landing page and nowhere else: an analytics script
| on a signed in page would be in a position to observe repositories, findings and
| team data. These pin where it renders and where it must not.
*/

beforeEach(function () {
    config()->set('pulllens.analytics.matomo.url', 'https://analytics.example.com/');
    config()->set('pulllens.analytics.matomo.site_id', '3');
});

it('renders the tracker on the landing page', function () {
    $content = $this->get('/')->assertOk()->getContent();

    expect($content)->toContain('https://analytics.example.com/')
        ->and($content)->toContain("_paq.push(['trackPageView'])")
        ->and($content)->toContain('matomo.js');
});

it('never renders the tracker on a signed in page', function () {
    $this->actingAs(User::factory()->admin()->create());

    foreach (['/dashboard', '/findings', '/tasks'] as $path) {
        expect($this->get($path)->getContent())
            ->not->toContain('matomo.js', "{$path} loaded the analytics script");
    }
});

it('never renders the tracker on the login page', function () {
    expect($this->get('/login')->getContent())->not->toContain('matomo.js');
});

it('stays silent until both values are configured', function () {
    config()->set('pulllens.analytics.matomo.site_id', null);

    expect($this->get('/')->getContent())->not->toContain('matomo.js');

    config()->set('pulllens.analytics.matomo.url', null);
    config()->set('pulllens.analytics.matomo.site_id', '3');

    expect($this->get('/')->getContent())->not->toContain('matomo.js');
});

it('is absent by default', function () {
    config()->set('pulllens.analytics.matomo.url', null);
    config()->set('pulllens.analytics.matomo.site_id', null);

    expect($this->get('/')->getContent())->not->toContain('matomo');
});
