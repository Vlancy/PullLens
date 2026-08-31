<?php

use App\Models\GIT\GitRepository;
use App\Models\Users\User;
use Illuminate\Support\Str;

/*
| Every feature area is gated on a permission, not merely on being signed in.
| A user with no role has no permissions and must be refused everywhere.
*/

test('a user with no role is refused every permissioned page', function (string $route) {
    $this->actingAs(User::factory()->create());

    $this->get($route)->assertForbidden();
})->with([
    '/findings',
    '/reports',
    '/reports/developers',
    '/reports/commits',
    '/repositories',
    '/settings/ai-providers',
    '/settings/git-providers',
]);

/*
| Only the engine-portable report pages are rendered here. The developer, commit and
| daily reports use PostgreSQL-specific SQL (DATE_TRUNC, EXTRACT(EPOCH ...), the ~*
| regex operator and window functions) which the in-memory SQLite test database
| cannot parse. Their authorization is still covered by the "no role is refused"
| case above, which is decided before any query runs.
*/
test('a member can read reports and findings', function (string $route) {
    $this->actingAs(User::factory()->member()->create());

    $this->get($route)->assertOk();
})->with([
    '/findings',
    '/reports',
    '/reports/repositories',
]);

test('a member cannot manage credentials', function (string $route) {
    $this->actingAs(User::factory()->member()->create());

    $this->get($route)->assertForbidden();
})->with([
    '/settings/ai-providers',
    '/settings/git-providers',
]);

test('a member cannot resolve findings', function () {
    $this->actingAs(User::factory()->member()->create());

    $this->post('/admin/findings/bulk-resolve', [
        'finding_ids' => [(string) Str::uuid()],
        'resolution_type' => 'fix_confirmed',
    ])->assertForbidden();
});

test('a member cannot trigger reviews', function () {
    $repository = GitRepository::factory()->create();

    $this->actingAs(User::factory()->member()->create());

    $this->post("/repositories/{$repository->id}/sync-reviews")->assertForbidden();
});

test('a manager can trigger reviews', function () {
    $repository = GitRepository::factory()->create();

    $this->actingAs(User::factory()->manager()->create());

    $this->post("/repositories/{$repository->id}/sync-reviews")->assertRedirect();
});

test('observability is limited to administrators', function () {
    expect(User::factory()->member()->create()->can('viewHorizon'))->toBeFalse()
        ->and(User::factory()->member()->create()->can('viewTelescope'))->toBeFalse()
        ->and(User::factory()->admin()->create()->can('viewHorizon'))->toBeTrue()
        ->and(User::factory()->admin()->create()->can('viewTelescope'))->toBeTrue();
});
