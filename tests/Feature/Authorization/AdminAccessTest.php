<?php

use App\Models\Users\User;

/*
| The admin area mutates the highest-privilege state in the application. Before the
| authorization layer existed, any authenticated user could reach these routes.
*/

test('a member cannot reach user administration', function (string $method, string $route) {
    $this->actingAs(User::factory()->member()->create());

    $this->call($method, $route)->assertForbidden();
})->with([
    ['GET', '/admin/users'],
    ['POST', '/admin/users'],
]);

test('a manager cannot reach user administration', function () {
    $this->actingAs(User::factory()->manager()->create());

    $this->get('/admin/users')->assertForbidden();
});

test('an administrator can reach user administration', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/admin/users')->assertOk();
});

test('a user without a role cannot reach user administration', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin/users')->assertForbidden();
});

test('an administrator cannot delete their own account through the admin panel', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->delete("/admin/users/{$admin->id}")->assertForbidden();

    expect(User::query()->whereKey($admin->id)->exists())->toBeTrue();
});

test('an administrator cannot edit their own account through the admin panel', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->put("/admin/users/{$admin->id}", [
        'name' => 'Renamed',
        'email' => $admin->email,
        'role' => 'admin',
    ])->assertForbidden();
});

test('creating a user assigns exactly the requested role', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->post('/admin/users', [
        'name' => 'New Operator',
        'email' => 'operator@example.com',
        'password' => 'correct-horse-battery-staple',
        'role' => 'manager',
    ])->assertRedirect(route('admin.users.index'));

    $created = User::query()->where('email', 'operator@example.com')->firstOrFail();

    expect($created->getRoleNames()->toArray())->toBe(['manager'])
        ->and($created->email_verified_at)->not->toBeNull();
});

test('an unknown role is rejected', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->post('/admin/users', [
        'name' => 'New Operator',
        'email' => 'operator@example.com',
        'password' => 'correct-horse-battery-staple',
        'role' => 'superuser',
    ])->assertSessionHasErrors('role');

    expect(User::query()->where('email', 'operator@example.com')->exists())->toBeFalse();
});

test('the last administrator cannot delete their own account', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->delete('/user/settings/profile', ['password' => 'password'])->assertForbidden();

    expect(User::query()->whereKey($admin->id)->exists())->toBeTrue();
});

test('an administrator can delete their own account when another one remains', function () {
    User::factory()->admin()->create();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->delete('/user/settings/profile', ['password' => 'password'])->assertRedirect('/');

    expect(User::query()->whereKey($admin->id)->exists())->toBeFalse();
});
