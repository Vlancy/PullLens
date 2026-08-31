<?php

use App\Models\Users\User;
use Laravel\Fortify\Features;

/*
| Registration is disabled application-wide: accounts are provisioned by an
| administrator. These tests are the regression guard — if a package upgrade or a
| configuration change re-opens self-service sign-up, they fail.
*/

test('the registration feature is disabled', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse()
        ->and(config('pulllens.registration_enabled'))->toBeFalse();
});

test('fortify does not register any registration route', function () {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse();
});

test('registration endpoints are not reachable', function (string $method, string $path) {
    $this->call($method, $path)->assertNotFound();
})->with([
    ['GET', '/register'],
    ['POST', '/register'],
    ['POST', '/api/register'],
    ['GET', '/auth/register'],
    ['POST', '/user/register'],
]);

test('posting registration data creates no account', function () {
    $this->post('/register', [
        'name' => 'Intruder',
        'email' => 'intruder@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    expect(User::query()->where('email', 'intruder@example.com')->exists())->toBeFalse();
});
