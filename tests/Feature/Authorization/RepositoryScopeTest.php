<?php

use App\Enums\Users\RepositoryAccessLevel;
use App\Enums\Users\UserRole;
use App\Models\GIT\GitRepository;
use App\Models\Users\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Full names of the repositories a page rendered, read from the Inertia props rather
 * than the HTML - the payload is JSON-escaped, so a raw string search would miss them.
 *
 * @return array<int, string>
 */
function renderedRepositoryNames(TestResponse $response): array
{
    $repositories = $response->viewData('page')['props']['repositories'] ?? [];

    return array_column($repositories, 'full_name');
}

/*
| A user without `repositories.view-all` must see exactly the repositories granted to
| them - no more from the listing, the findings page, the dashboard totals, or a
| direct URL.
*/

function contributorWith(GitRepository $repository, RepositoryAccessLevel $level): User
{
    $user = User::factory()->withRole(UserRole::Contributor)->create();

    $user->repositories()->attach($repository->id, ['access_level' => $level->value]);

    return $user;
}

test('a scoped user sees only the repositories granted to them', function () {
    $granted = GitRepository::factory()->create(['full_name' => 'octo/granted']);
    GitRepository::factory()->create(['full_name' => 'octo/hidden']);

    $this->actingAs(contributorWith($granted, RepositoryAccessLevel::View));

    $response = $this->get('/repositories')->assertOk();

    expect(renderedRepositoryNames($response))->toBe(['octo/granted']);
});

test('a scoped user cannot open a repository they were not granted', function () {
    $granted = GitRepository::factory()->create();
    $other = GitRepository::factory()->create();

    $this->actingAs(contributorWith($granted, RepositoryAccessLevel::View));

    $this->get("/repositories/{$granted->id}")->assertOk();
    $this->get("/repositories/{$other->id}")->assertForbidden();
});

test('a scoped user with no grants at all sees nothing rather than everything', function () {
    GitRepository::factory()->create(['full_name' => 'octo/secret']);

    $user = User::factory()->withRole(UserRole::Contributor)->create();
    $this->actingAs($user);

    $response = $this->get('/repositories')->assertOk();

    expect(renderedRepositoryNames($response))->toBe([]);
});

test('a view-only grant cannot trigger reviews', function () {
    $repository = GitRepository::factory()->create();

    $this->actingAs(contributorWith($repository, RepositoryAccessLevel::View));

    $this->post("/repositories/{$repository->id}/sync-reviews")->assertForbidden();
});

test('a manage grant still needs the trigger-reviews permission', function () {
    $repository = GitRepository::factory()->create();

    // Contributor holds a manage grant but not `reviews.trigger`.
    $this->actingAs(contributorWith($repository, RepositoryAccessLevel::Manage));

    $this->post("/repositories/{$repository->id}/sync-reviews")->assertForbidden();
});

test('an unscoped user still sees every repository', function () {
    GitRepository::factory()->create(['full_name' => 'octo/one']);
    GitRepository::factory()->create(['full_name' => 'octo/two']);

    $this->actingAs(User::factory()->member()->create());

    $response = $this->get('/repositories')->assertOk();

    expect(renderedRepositoryNames($response))->toBe(['octo/one', 'octo/two']);
});

test('the repository filter cannot be used to escape the users grants', function () {
    $granted = GitRepository::factory()->create();
    $other = GitRepository::factory()->create();

    $this->actingAs(contributorWith($granted, RepositoryAccessLevel::View));

    // A hand-edited repository_id outside the grant set must narrow to nothing,
    // never widen the query back to the whole installation.
    $this->get("/findings?repository_id={$other->id}&status=all")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('total', 0));
});

test('aggregate reports are closed to scoped users', function (string $route) {
    $repository = GitRepository::factory()->create();

    $this->actingAs(contributorWith($repository, RepositoryAccessLevel::Manage));

    $this->get($route)->assertForbidden();
})->with(['/reports', '/reports/developers', '/reports/repositories']);

test('promoting a scoped user to an unscoped role clears their stale grants', function () {
    $repository = GitRepository::factory()->create();
    $user = contributorWith($repository, RepositoryAccessLevel::Manage);

    $this->actingAs(User::factory()->admin()->create());

    $this->put("/admin/users/{$user->id}", [
        'name' => $user->name,
        'email' => $user->email,
        'role' => 'member',
    ])->assertRedirect();

    expect($user->fresh()->repositories()->count())->toBe(0);
});
