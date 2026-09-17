<?php

/*
| HOMEPAGE_LOGIN turns a public instance into an internal one: the landing page
| becomes the login screen and the hosted guide is withdrawn with it. Both halves
| are pinned here, because half of the switch working is worse than neither.
*/

it('serves the landing page by default', function () {
    expect(config('pulllens.homepage_login'))->toBeFalse();

    $this->get('/')->assertOk();
    $this->get('/docs')->assertRedirect('docs/guide/index.html');
    $this->get('/docs/guide/index.html')->assertOk();
});

it('sends the home page to the login screen when the switch is on', function () {
    config()->set('pulllens.homepage_login', true);

    $this->get('/')->assertRedirect(route('login'));
});

it('withdraws the hosted guide when the switch is on', function () {
    config()->set('pulllens.homepage_login', true);

    $this->get('/docs')->assertNotFound();
    $this->get('/docs/guide/index.html')->assertNotFound();
    $this->get('/docs/images/tour.gif')->assertNotFound();
});
