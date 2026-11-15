<?php

use App\Enums\Users\UserPermission;
use App\Models\GIT\GitRepository;
use App\Models\Users\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/*
| A custom role without "see every repository" is scoped exactly like Contributor:
| it sees only granted repositories, and only the areas its permissions open.
*/

function customRoleUser(array $permissions): User
{
    $role = Role::findOrCreate('tasks-viewer', 'web');
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user->fresh();
}

test('a custom role without view-all only sees granted repositories on the dashboard', function () {
    $granted = GitRepository::factory()->create();
    GitRepository::factory()->count(3)->create();

    $user = customRoleUser([UserPermission::ViewTasks->value]);
    $user->repositories()->attach($granted->id, ['access_level' => 'view']);

    expect($user->isRepositoryScoped())->toBeTrue();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_repositories', 1)
            ->etc());
});

test('a custom role with no grants sees no repositories on the dashboard', function () {
    GitRepository::factory()->count(3)->create();

    $this->actingAs(customRoleUser([UserPermission::ViewTasks->value]))
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_repositories', 0)
            ->etc());
});

test('a role without findings.view gets repository and pull request counts but no findings data', function () {
    $granted = GitRepository::factory()->create();
    $user = customRoleUser([UserPermission::ViewTasks->value]);
    $user->repositories()->attach($granted->id, ['access_level' => 'view']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_view_findings', false)
            ->where('stats.total_repositories', 1)
            ->where('stats.total_findings', null)
            ->where('stats.critical_high_findings', null)
            ->where('stats.total_reviews', null)
            ->where('findings_by_severity', [])
            ->where('findings_by_category', [])
            ->where('verdict_distribution', [])
            ->where('recent_reviews', [])
            ->where('top_repositories', [])
            ->etc());
});

test('a role with findings.view still gets the findings dashboard', function () {
    $this->actingAs(customRoleUser([UserPermission::ViewFindings->value]))
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_view_findings', true)
            ->where('stats.total_findings', 0)
            ->etc());
});
