<?php

namespace App\Services\Users;

use App\Enums\Users\UserPermission;
use App\Enums\Users\UserRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reads and writes the role/permission matrix that the admin page edits.
 *
 * Two kinds of role live side by side:
 *
 *   - Built-in roles, declared by the UserRole enum and provisioned by the seeder.
 *     They can be re-permissioned but never renamed or deleted.
 *   - Custom roles, created by an administrator from the roles page. They carry their
 *     own label and can be deleted once nobody holds them.
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
     * Built-in roles come first in enum order, followed by custom roles by label.
     *
     * @return array<int, array<string, mixed>>
     */
    public function matrix(): array
    {
        $roles = $this->orderedRoles(withPermissions: true);

        return $roles->map(fn (Role $role): array => [
            'value' => $role->name,
            'label' => $this->labelFor($role),
            'permissions' => $role->permissions->pluck('name')->values()->all(),
            'users_count' => $role->users()->count(),
            // The UI renders these as fixed, always-on checkboxes.
            'locked_permissions' => $this->lockedFor($role->name),
            'is_admin' => $role->name === UserRole::Admin->value,
            'is_custom' => $this->isCustom($role->name),
        ])->values()->all();
    }

    /**
     * Role choices for the user create/edit forms.
     *
     * `scoped` tells the UI whether picking the role should reveal the per-repository
     * grant editor - read from the role's actual permissions, so it stays correct
     * after an administrator edits the matrix.
     *
     * @return array<int, array{value: string, label: string, scoped: bool}>
     */
    public function options(): array
    {
        return $this->orderedRoles(withPermissions: true)
            ->map(fn (Role $role): array => [
                'value' => $role->name,
                'label' => $this->labelFor($role),
                'scoped' => ! $role->permissions->contains('name', UserPermission::ViewAllRepositories->value),
            ])
            ->values()
            ->all();
    }

    /**
     * Every role name a user may be assigned, built-in and custom.
     *
     * @return array<int, string>
     */
    public function assignableNames(): array
    {
        return $this->orderedRoles()->pluck('name')->values()->all();
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
     * Create a custom role with the given label and permissions.
     *
     * The stored name is a slug derived from the label, suffixed when needed so it
     * never collides with a built-in role or another custom one.
     *
     * @param  array<int, string>  $permissions
     */
    public function create(string $label, array $permissions): Role
    {
        $role = DB::transaction(function () use ($label, $permissions): Role {
            $role = Role::create([
                'name' => $this->uniqueNameFor($label),
                'guard_name' => $this->guard(),
            ]);

            // `label` is not in spatie's fillable list, so it is set explicitly.
            $role->forceFill(['label' => $label])->save();

            $role->syncPermissions(array_values(array_unique($permissions)));

            return $role;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role;
    }

    /**
     * Replace one role's permissions, and optionally rename a custom role.
     *
     * Locked permissions are re-added regardless of what was submitted, so the request
     * cannot remove them even if the form is bypassed. Built-in roles ignore a label.
     *
     * @param  array<int, string>  $permissions
     *
     * @throws ValidationException When the change would leave nobody able to manage users.
     */
    public function sync(string $roleName, array $permissions, ?string $label = null): void
    {
        $permissions = array_values(array_unique([...$permissions, ...$this->lockedFor($roleName)]));

        $this->guardAgainstOrphanedUserManagement($roleName, $permissions);

        DB::transaction(function () use ($roleName, $permissions, $label): void {
            $role = Role::findOrCreate($roleName, $this->guard());

            if ($label !== null && $this->isCustom($roleName)) {
                $role->forceFill(['label' => $label])->save();
            }

            $role->syncPermissions($permissions);
        });

        // Permission checks are cached; without this the change would not take effect
        // until the cache expired on its own.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Delete a custom role.
     *
     * @throws ValidationException When the role is built-in or still assigned to users.
     */
    public function delete(string $roleName): void
    {
        if (! $this->isCustom($roleName)) {
            throw ValidationException::withMessages([
                'role' => 'Built-in roles cannot be deleted.',
            ]);
        }

        $role = Role::findByName($roleName, $this->guard());

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => 'Reassign the users holding this role before deleting it.',
            ]);
        }

        $role->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Whether the role was created by an administrator rather than declared by the enum.
     */
    public function isCustom(string $roleName): bool
    {
        return UserRole::tryFrom($roleName) === null;
    }

    /**
     * Human-readable name for a role.
     */
    public function labelFor(Role $role): string
    {
        return UserRole::tryFrom($role->name)?->label()
            ?? ($role->getAttribute('label') ?: Str::headline($role->name));
    }

    /**
     * Permissions the given role is not allowed to give up.
     *
     * @return array<int, string>
     */
    public function lockedFor(string $roleName): array
    {
        if ($roleName !== UserRole::Admin->value) {
            return [];
        }

        return array_map(
            static fn (UserPermission $permission): string => $permission->value,
            self::ADMIN_LOCKED,
        );
    }

    /**
     * Roles in display order: built-ins in enum order, then custom roles by label.
     *
     * Built-in roles missing from the database (seeder not run yet) are skipped.
     *
     * @return Collection<int, Role>
     */
    private function orderedRoles(bool $withPermissions = false): Collection
    {
        $roles = Role::query()
            ->where('guard_name', $this->guard())
            ->when($withPermissions, fn ($q) => $q->with('permissions:id,name'))
            ->get();

        $builtIn = UserRole::values();

        return $roles
            ->sortBy(fn (Role $role): string => in_array($role->name, $builtIn, true)
                ? sprintf('0-%02d', array_search($role->name, $builtIn, true))
                : '1-'.mb_strtolower($this->labelFor($role)))
            ->values();
    }

    /**
     * A role name derived from the label that is not already taken.
     */
    private function uniqueNameFor(string $label): string
    {
        $base = Str::slug($label) ?: 'role';
        $name = $base;
        $suffix = 2;

        while (UserRole::tryFrom($name) !== null || Role::query()->where('name', $name)->exists()) {
            $name = "{$base}-{$suffix}";
            $suffix++;
        }

        return $name;
    }

    /**
     * The guard every application role belongs to.
     */
    private function guard(): string
    {
        return config('auth.defaults.guard', 'web');
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
    private function guardAgainstOrphanedUserManagement(string $roleName, array $permissions): void
    {
        if (in_array(UserPermission::ManageUsers->value, $permissions, true)) {
            return;
        }

        $othersCanManage = Role::query()
            ->where('name', '!=', $roleName)
            ->whereHas('permissions', fn ($q) => $q->where('name', UserPermission::ManageUsers->value))
            ->exists();

        if (! $othersCanManage) {
            throw ValidationException::withMessages([
                'permissions' => 'At least one role must keep the "Manage users" permission.',
            ]);
        }
    }
}
