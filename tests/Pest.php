<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Feature tests render real Inertia pages. Without this they would fail whenever
    // the Vite manifest is missing or stale, turning an asset-build concern into a
    // test failure that says nothing about the behaviour under test.
    //
    // The public-surface switches are pinned off for the same reason. The landing
    // page, the hosted guide, robots.txt and the sitemap are all read through them,
    // and without this the suite would answer to whatever .env the machine happens
    // to carry - an instance that runs privately would fail two dozen tests that say
    // nothing about the change under test. Tests for the switched-on behaviour turn
    // them back on themselves.
    ->beforeEach(function () {
        $this->withoutVite();

        config()->set('pulllens.homepage_login', false);
        config()->set('pulllens.hide_login', false);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
