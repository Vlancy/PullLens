<?php

namespace Database\Seeders;

use App\Enums\Users\UserPermission;
use App\Enums\Users\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Provisions the roles and permissions declared by the UserRole and UserPermission enums.
 *
 * Idempotent and safe to run on every deploy. Crucially it does **not** flatten what an
 * operator has configured through the roles admin page:
 *
 *   - Every permission in the enum is created if missing.
 *   - A role that does not exist yet is created with the enum's default permissions.
 *   - A role that already exists keeps whatever permissions it has been given.
 *
 * The administrator role is the single exception: it is re-synced to every permission
 * on each run, both because "full control" is its definition and because it guarantees
 * a newly added permission always has at least one role that holds it.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // The registrar caches the matrix; a stale cache would hide what we just wrote.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');

        foreach (UserPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, $guard);
        }

        foreach (UserRole::cases() as $role) {
            $this->provisionRole($role, $guard);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Create the role if missing, and decide whether to (re)apply its default permissions.
     */
    private function provisionRole(UserRole $role, string $guard): void
    {
        $existing = Role::query()
            ->where('name', $role->value)
            ->where('guard_name', $guard)
            ->first();

        if ($existing === null) {
            Role::findOrCreate($role->value, $guard)->syncPermissions($this->defaults($role));

            return;
        }

        if ($role === UserRole::Admin) {
            $existing->syncPermissions($this->defaults($role));
        }

        // Any other pre-existing role keeps the permissions an operator configured.
    }

    /**
     * The role's declared default permissions, as plain names.
     *
     * @return array<int, string>
     */
    private function defaults(UserRole $role): array
    {
        return array_map(
            static fn (UserPermission $permission): string => $permission->value,
            $role->permissions(),
        );
    }
}
