<?php

use App\Models\Users\User;
use Illuminate\Session\TokenMismatchException;

/*
| Error pages are the only pages here that nobody visits on purpose, which is why
| they rot: they render outside the SPA, they are skipped by every happy-path test,
| and a mistake in one only shows up in front of a visitor who is already having a
| bad time. These pin the three things that matter - the right status code, the
| right words, and no chance of the page being indexed.
*/

beforeEach(function () {
    // With debug on, Laravel renders the Ignition stack trace instead of the view,
    // so none of this is reachable. Production is what is under test.
    config()->set('app.debug', false);
});

test('a missing page answers 404 and not a soft 200', function () {
    // A "not found" page returned with a 200 is a soft 404: Google indexes it as a
    // real page, and every missing URL on the instance becomes a duplicate of it.
    $this->get('/a-route-that-does-not-exist')
        ->assertNotFound()
        ->assertSee('There is nothing at this address');
});

test('every error page renders with the product layout', function (int $code) {
    Route::get('__test/abort/'.$code, fn () => abort($code));

    $response = $this->get('__test/abort/'.$code);

    $response->assertStatus($code)
        // The shared layout, rather than the framework's grey default.
        ->assertSee('favicon.png', escape: false)
        ->assertSee((string) $code);
})->with([401, 402, 403, 404, 429, 500, 503]);

test('error pages ask not to be indexed', function (int $code) {
    // robots.txt already refuses the crawl, but a crawler that follows a direct
    // link never reads robots.txt for that URL. This is the half that survives.
    Route::get('__test/noindex/'.$code, fn () => abort($code));

    $this->get('__test/noindex/'.$code)
        ->assertStatus($code)
        ->assertSee('noindex, nofollow', escape: false)
        ->assertDontSee('<link rel="canonical"', escape: false);
})->with([403, 404, 500, 503]);

test('error pages carry no analytics and no structured data', function () {
    // Both are landing-page concerns. Structured data on an error page invites a
    // search engine to describe the failure as if it were a product page.
    $body = $this->get('/a-route-that-does-not-exist')->getContent();

    expect($body)
        ->not->toContain('application/ld+json')
        ->not->toContain('matomo');
});

test('the 403 page prefers the reason the policy gave', function () {
    Route::get('__test/forbidden', fn () => abort(403, 'Reports are limited to reviewers.'));

    $this->get('__test/forbidden')
        ->assertForbidden()
        ->assertSee('Reports are limited to reviewers.');
});

test('the 403 page still explains itself when the policy said nothing', function () {
    Route::get('__test/forbidden-silent', fn () => abort(403));

    $this->get('__test/forbidden-silent')
        ->assertForbidden()
        ->assertSee('needs a permission your role does not carry');
});

test('the 419 page offers the way back in rather than a dead end', function () {
    // Thrown directly: a real expired session reaches the handler as exactly this,
    // and driving it through a live form would test Laravel's CSRF middleware
    // rather than the page it renders.
    Route::get('__test/expired', function () {
        throw new TokenMismatchException('CSRF token mismatch.');
    });

    $this->get('__test/expired')
        ->assertStatus(419)
        ->assertSee('That page sat still for too long')
        ->assertSee('Sign in again');
});

test('a private instance does not link out to withdrawn public pages', function () {
    // HOMEPAGE_LOGIN withdraws the landing page and the guide. Linking to either
    // from an error page would send the visitor to a second error.
    config()->set('pulllens.homepage_login', true);

    $body = $this->get('/a-route-that-does-not-exist')
        ->assertNotFound()
        ->getContent();

    expect($body)->not->toContain('Read the guide');
});

test('a public instance points the visitor at the home page and the guide', function () {
    $body = $this->get('/a-route-that-does-not-exist')
        ->assertNotFound()
        ->getContent();

    expect($body)
        ->toContain('Back to the home page')
        ->toContain('Read the guide');
});

test('an error page renders without the asset build', function () {
    // The moment an error page is most needed is the moment the build is broken or
    // missing. Anything pulled in through Vite would render a blank page then.
    $body = $this->get('/a-route-that-does-not-exist')->getContent();

    expect($body)
        ->not->toContain('/build/')
        ->not->toContain('@vite');
});

test('a signed in user still gets a branded page, not the framework default', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/a-route-that-does-not-exist')
        ->assertNotFound()
        ->assertSee('There is nothing at this address');
});

test('the maintenance page does not send the visitor round in a circle', function () {
    // During a maintenance window the home page and the guide answer 503 as well,
    // so linking to either from the 503 page is a loop.
    Route::get('__test/maintenance', fn () => abort(503));

    $body = $this->get('__test/maintenance')->assertStatus(503)->getContent();

    expect($body)
        ->toContain('Try again')
        ->not->toContain('Read the guide');
});

test('a visitor who chose the light theme keeps it on a dark desktop', function () {
    // The media query in the layout would otherwise overrule an explicit choice;
    // the class is what excludes it. The cookie is sent unencrypted because it is
    // on the encryptCookies except list - the front end writes it from JavaScript.
    Route::middleware('web')->get('__test/light', fn () => abort(404));

    $this->withUnencryptedCookie('appearance', 'light')
        ->get('__test/light')
        ->assertNotFound()
        ->assertSee('class="light"', escape: false);
});
