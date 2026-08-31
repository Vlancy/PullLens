<?php

namespace App\Services\Users;

use App\Enums\Users\UserPermission;
use App\Enums\Users\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reads and writes the role/permission matrix that the admin page edits.
 *
 * Enforces the one rule that must never be violated by a UI mistake: the
 * administrator role always keeps the permissions needed to reach the admin area.
 * Without that guard, unticking two checkboxes would permanently lock every operator
 * out of an installation that has no self-service registration to recover through.
 */
class RolePermissionManager
{
    /**
     * Permissions the administrator role can never lose.
     *
     * `users.manage` reaches the admin area at all; `repositories.view-all` keeps an
     * administrator from being silently scoped down to zero repositories.
     *
     * @var array<int, UserPermission>
     */
    private const ADMIN_LOCKED = [
        UserPermission::ManageUsers,
        UserPermission::ViewAllRepositories,
    ];

    /**
     * The current matrix: every role with the permissions it holds.
     *
     * @return array<int, array<string, mixed>>
     */
    public function matrix(): array
    {
        $assigned = Role::query()
            ->with('permissions:id,name')
            ->get()
            ->keyBy('name');

        return array_map(function (UserRole $role) use ($assigned): array {
            $model = $assigned->get($role->value);

            return [
                'value' => $role->value,
                'label' => $role->label(),
                'permissions' => $model?->permissions->pluck('name')->values()->all() ?? [],
                'users_count' => $model?->users()->count() ?? 0,
                // The UI renders these as fixed, always-on checkboxes.
                'locked_permissions' => $this->lockedFor($role),
                'is_admin' => $role === UserRole::Admin,
            ];
        }, UserRole::cases());
    }

    /**
     * Every permission that can appear in the matrix, with human-readable labels.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function availablePermissions(): array
    {
        return array_map(
            static fn (UserPermission $permission): array => [
                'value' => $permission->value,
                'label' => $permission->label(),
            ],
            UserPermission::cases(),
        );
    }

    /**
     * Replace one role's permissions.
     *
     * Locked permissions are re-added regardless of what was submitted, so the request
     * cannot remove them even if the form is bypassed.
     *
     * @param  array<int, string>  $permissions
     *
     * @throws ValidationException When the change would leave nobody able to manage users.
     */
    public function sync(UserRole $role, array $permissions): void
    {
        $permissions = array_values(array_unique([...$permissions, ...$this->lockedFor($role)]));

        $this->guardAgainstOrphanedUserManagement($role, $permissions);

        DB::transaction(function () use ($role, $permissions): void {
            Role::findOrCreate($role->value, config('auth.defaults.guard', 'web'))
                ->syncPermissions($permissions);
        });

        // Permission checks are cached; without this the change would not take effect
        // until the cache expired on its own.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Permissions the given role is not allowed to give up.
     *
     * @return array<int, string>
     */
    public function lockedFor(UserRole $role): array
    {
        if ($role !== UserRole::Admin) {
            return [];
        }

        return array_map(
            static fn (UserPermission $permission): string => $permission->value,
            self::ADMIN_LOCKED,
        );
    }

    /**
     * Refuse a change that would leave no role at all able to manage users.
     *
     * The admin lock above already prevents this for the administrator role; this is
     * the belt-and-braces check for any future role arrangement.
     *
     * @param  array<int, string>  $permissions
     *
     * @throws ValidationException
     */
    private function guardAgainstOrphanedUserManagement(UserRole $role, array $permissions): void
    {
        if (in_array(UserPermission::ManageUsers->value, $permissions, true)) {
            return;
        }

        $othersCanManage = Role::query()
            ->where('name', '!=', $role->value)
            ->whereHas('permissions', fn ($q) => $q->where('name', UserPermission::ManageUsers->value))
            ->exists();

        if (! $othersCanManage) {
            throw ValidationException::withMessages([
                'permissions' => 'At least one role must keep the "Manage users" permission.',
            ]);
        }
    }
}
