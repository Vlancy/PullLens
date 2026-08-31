<?php

use App\Enums\Users\UserRole;
use App\Models\Users\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Provision the role/permission matrix and keep existing installations working.
     *
     * Before roles existed, every authenticated user could reach every page. Now that
     * routes are permission-gated, an account with no role would be locked out of the
     * entire application the moment this deploys.
     *
     * Granting the administrator role to every pre-existing account reproduces the old
     * behaviour exactly, so the upgrade changes nothing for anyone until an operator
     * chooses to demote people from Admin → Users. Only accounts that already have a
     * role are left untouched, which makes this migration safe to re-run.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        (new RolesAndPermissionsSeeder)->run();

        User::query()
            ->whereDoesntHave('roles')
            ->each(function (User $user): void {
                $user->assignRole(UserRole::Admin->value);
            });
    }

    /**
     * Role assignments are dropped by the permission tables' own migration; there is
     * nothing to reverse here that would not destroy an operator's later choices.
     */
    public function down(): void
    {
        //
    }
};
