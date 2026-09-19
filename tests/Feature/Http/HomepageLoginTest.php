<?php

use App\Models\Users\User;

/*
| HOMEPAGE_LOGIN turns a public instance into an internal one: the landing page
| becomes the login screen and the hosted guide is withdrawn with it. Both halves
| are pinned here, because half of the switch working is worse than neither.
*/

it('serves the landing page when the switch is off', function () {
    config()->set('pulllens.homepage_login', false);

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

it('advertises the login route on the landing page when the switch is off', function () {
    config()->set('pulllens.hide_login', false);

    $this->get('/')->assertInertia(
        fn ($page) => $page->component('welcome')->where('hideLogin', false)
    );
});

it('takes the login link off the landing page without closing the route', function () {
    config()->set('pulllens.hide_login', true);

    $this->get('/')->assertInertia(
        fn ($page) => $page->component('welcome')->where('hideLogin', true)
    );

    // The switch hides the way in; it must not bar anyone who has the address.
    $this->get('/login')->assertOk();
});

it('offers the guide in the sidebar, and withdraws it with the routes', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)->get('/dashboard')->assertInertia(
        fn ($page) => $page->where('docs_enabled', true)
    );

    config()->set('pulllens.homepage_login', true);

    $this->actingAs($user)->get('/dashboard')->assertInertia(
        fn ($page) => $page->where('docs_enabled', false)
    );
});

/*
| The switches are read from the environment, so the value a fresh clone starts
| with lives in .env.example rather than in any of the code above. It is pinned
| here because nothing else would notice it being flipped by accident.
*/
test('the shipped environment file opens an instance privately', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)
        ->toContain("\nHOMEPAGE_LOGIN=true\n")
        ->toContain("\nHIDE_LOGIN=false\n");
});
