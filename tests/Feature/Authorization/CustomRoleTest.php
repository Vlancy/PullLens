<?php

use App\Enums\Users\UserPermission;
use App\Models\Users\User;
use Spatie\Permission\Models\Role;

/*
| Administrators can add their own roles next to the built-in ones, give them any
| permission set, assign them to users, and remove them once nobody holds them.
*/

test('an administrator can create a custom role', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->post('/admin/roles', [
        'label' => 'Security Reviewer',
        'permissions' => [UserPermission::ViewFindings->value, UserPermission::ResolveFindings->value],
    ])->assertRedirect(route('admin.roles.index'))->assertSessionHasNoErrors();

    $role = Role::query()->where('name', 'security-reviewer')->firstOrFail();

    expect($role->label)->toBe('Security Reviewer')
        ->and($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['findings.resolve', 'findings.view']);
});

test('custom roles appear on the roles page after the built-in ones', function () {
    $this->actingAs(User::factory()->admin()->create());
    $this->post('/admin/roles', ['label' => 'Auditor', 'permissions' => []]);

    $this->get('/admin/roles')->assertInertia(fn ($page) => $page
        ->component('admin/roles')
        ->where('roles.0.value', 'admin')
        ->where('roles.4.value', 'auditor')
        ->where('roles.4.label', 'Auditor')
        ->where('roles.4.is_custom', true)
        ->where('roles.0.is_custom', false));
});

test('only an administrator can create a role', function () {
    $this->actingAs(User::factory()->manager()->create());

    $this->post('/admin/roles', ['label' => 'Sneaky', 'permissions' => []])->assertForbidden();

    expect(Role::query()->where('name', 'sneaky')->exists())->toBeFalse();
});

test('a custom role cannot reuse a built-in or existing name', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->post('/admin/roles', ['label' => 'Admin', 'permissions' => []])->assertSessionHasErrors('label');
    $this->post('/admin/roles', ['label' => 'Manager', 'permissions' => []])->assertSessionHasErrors('label');

    $this->post('/admin/roles', ['label' => 'Auditor', 'permissions' => []])->assertSessionHasNoErrors();
    $this->post('/admin/roles', ['label' => 'Auditor', 'permissions' => []])->assertSessionHasErrors('label');
});

test('a custom role can be edited and renamed', function () {
    $this->actingAs(User::factory()->admin()->create());
    $this->post('/admin/roles', ['label' => 'Auditor', 'permissions' => []]);

    $this->put('/admin/roles/auditor', [
        'label' => 'Lead Auditor',
        'permissions' => [UserPermission::ViewReports->value],
    ])->assertRedirect(route('admin.roles.index'))->assertSessionHasNoErrors();

    $role = Role::query()->where('name', 'auditor')->firstOrFail();

    expect($role->label)->toBe('Lead Auditor')
        ->and($role->permissions->pluck('name')->all())->toBe(['reports.view']);
});

test('a custom role can be assigned to a user and its permissions apply', function () {
    $this->actingAs(User::factory()->admin()->create());
    $this->post('/admin/roles', ['label' => 'Reporter', 'permissions' => [
        UserPermission::ViewReports->value,
        UserPermission::ViewAllRepositories->value,
    ]]);

    $this->post('/admin/users', [
        'name' => 'Rita',
        'email' => 'rita@example.com',
        'password' => 'a-long-Secure-passw0rd!',
        'role' => 'reporter',
    ])->assertSessionHasNoErrors();

    $rita = User::query()->where('email', 'rita@example.com')->firstOrFail();

    expect($rita->hasRole('reporter'))->toBeTrue();

    $this->actingAs($rita);
    $this->get('/reports')->assertOk();
});

test('an unknown role cannot be assigned', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->post('/admin/users', [
        'name' => 'Rita',
        'email' => 'rita@example.com',
        'password' => 'a-long-Secure-passw0rd!',
        'role' => 'made-up',
    ])->assertSessionHasErrors('role');
});

test('an unused custom role can be deleted', function () {
    $this->actingAs(User::factory()->admin()->create());
    $this->post('/admin/roles', ['label' => 'Auditor', 'permissions' => []]);

    $this->delete('/admin/roles/auditor')->assertRedirect(route('admin.roles.index'));

    expect(Role::query()->where('name', 'auditor')->exists())->toBeFalse();
});

test('a custom role still held by users cannot be deleted', function () {
    $this->actingAs(User::factory()->admin()->create());
    $this->post('/admin/roles', ['label' => 'Auditor', 'permissions' => []]);
    User::factory()->create()->syncRoles(['auditor']);

    $this->delete('/admin/roles/auditor')->assertSessionHasErrors('role');

    expect(Role::query()->where('name', 'auditor')->exists())->toBeTrue();
});

test('built-in roles cannot be deleted', function () {
    $this->actingAs(User::factory()->admin()->create());
    User::factory()->member()->create();

    $this->delete('/admin/roles/member')->assertSessionHasErrors('role');

    expect(Role::query()->where('name', 'member')->exists())->toBeTrue();
});

test('editing an unknown role returns not found', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->put('/admin/roles/nope', ['permissions' => []])->assertNotFound();
    $this->delete('/admin/roles/nope')->assertNotFound();
});
