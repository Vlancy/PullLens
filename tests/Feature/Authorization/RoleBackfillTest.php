<?php

use App\Enums\Users\UserRole;
use App\Models\Users\User;

/*
| The upgrade path. Before roles existed every authenticated user could reach every
| page; the backfill migration must reproduce that exactly for accounts created
| before the upgrade, or the deploy locks the whole team out.
*/

function runRoleBackfill(): void
{
    $migration = require database_path('migrations/2026_08_31_120001_backfill_user_roles.php');

    $migration->up();
}

test('an account that predates roles is granted administrator access', function () {
    $legacy = User::factory()->create();

    expect($legacy->getRoleNames())->toBeEmpty();

    runRoleBackfill();

    expect($legacy->fresh()->isAdmin())->toBeTrue();
});

test('the backfill leaves an already-assigned role alone', function () {
    $member = User::factory()->member()->create();

    runRoleBackfill();

    expect($member->fresh()->getRoleNames()->toArray())->toBe([UserRole::Member->value]);
});

test('the backfill is safe to run twice', function () {
    $legacy = User::factory()->create();

    runRoleBackfill();
    runRoleBackfill();

    expect($legacy->fresh()->getRoleNames()->toArray())->toBe([UserRole::Admin->value]);
});

test('a legacy account can still reach every page after the backfill', function (string $route) {
    $legacy = User::factory()->create();

    runRoleBackfill();

    $this->actingAs($legacy->fresh());
    $this->get($route)->assertOk();
})->with(['/dashboard', '/findings', '/repositories', '/reports', '/admin/users', '/admin/roles']);
