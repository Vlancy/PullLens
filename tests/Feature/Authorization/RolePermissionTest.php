<?php

use App\Enums\Users\UserPermission;
use App\Enums\Users\UserRole;
use App\Models\Users\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

/*
| The roles page can grant any permission in the system, so it is administrator-only,
| and it must never be able to make the admin area unreachable.
*/

test('only an administrator can open the roles page', function () {
    $this->actingAs(User::factory()->manager()->create());
    $this->get('/admin/roles')->assertForbidden();

    $this->actingAs(User::factory()->member()->create());
    $this->get('/admin/roles')->assertForbidden();

    $this->actingAs(User::factory()->admin()->create());
    $this->get('/admin/roles')->assertOk();
});

test('an administrator can change what a role may do', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->put('/admin/roles/member', [
        'permissions' => [
            UserPermission::ViewFindings->value,
            UserPermission::ResolveFindings->value,
        ],
    ])->assertRedirect(route('admin.roles.index'));

    $member = Role::query()->where('name', 'member')->firstOrFail();

    expect($member->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['findings.resolve', 'findings.view']);
});

test('a permission change takes effect immediately', function () {
    $member = User::factory()->member()->create();

    // A member can read reports out of the box.
    $this->actingAs($member);
    $this->get('/reports')->assertOk();

    $this->actingAs(User::factory()->admin()->create());
    $this->put('/admin/roles/member', ['permissions' => [UserPermission::ViewFindings->value]]);

    $this->actingAs($member->fresh());
    $this->get('/reports')->assertForbidden();
});

test('the administrator role cannot give up the permissions that reach the admin area', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    // Submit an empty set: the locked permissions must survive it.
    $this->put('/admin/roles/admin', ['permissions' => []])->assertRedirect();

    $granted = Role::query()->where('name', 'admin')->firstOrFail()
        ->permissions->pluck('name')->all();

    expect($granted)->toContain(UserPermission::ManageUsers->value)
        ->and($granted)->toContain(UserPermission::ViewAllRepositories->value);

    // And the administrator can still get back in.
    $this->actingAs($admin->fresh());
    $this->get('/admin/roles')->assertOk();
});

test('an unknown permission is rejected', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->put('/admin/roles/member', ['permissions' => ['not.a.permission']])
        ->assertSessionHasErrors('permissions.0');
});

test('an unknown role is not routable', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->put('/admin/roles/superuser', ['permissions' => []])->assertNotFound();
});

test('re-running the seeder does not discard configured permissions', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->put('/admin/roles/member', ['permissions' => [UserPermission::ViewFindings->value]]);

    // Deploys run the seeder again; an operator's choices must survive it.
    (new RolesAndPermissionsSeeder)->run();

    expect(Role::query()->where('name', 'member')->firstOrFail()->permissions->pluck('name')->all())
        ->toBe([UserPermission::ViewFindings->value]);
});

test('the seeder always restores every permission to the administrator role', function () {
    (new RolesAndPermissionsSeeder)->run();

    $admin = Role::query()->where('name', UserRole::Admin->value)->firstOrFail();

    expect($admin->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(UserPermission::values())->sort()->values()->all());
});
