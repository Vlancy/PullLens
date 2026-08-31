<?php

namespace App\Services\Users;

use App\Enums\Users\RepositoryAccessLevel;
use App\Enums\Users\UserRole;
use App\Models\Users\User;
use App\Repositories\Contracts\Users\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Owns the lifecycle of administrator-provisioned accounts.
 *
 * Controllers stay thin and transport-focused; the invariants that make an account
 * valid — exactly one role, verified on creation, password only replaced when a new
 * one is supplied — live here and are applied atomically.
 */
class UserAccountManager
{
    /**
     * Inject the user repository interface this class delegates to.
     */
    public function __construct(private readonly UserRepositoryInterface $users) {}

    /**
     * Create an account and grant it a role.
     *
     * The account is marked verified immediately: an administrator vouched for the
     * address, and there is no self-service flow to click a verification link from.
     *
     * @param  array<string, mixed>  $attributes  Validated name/email/password.
     * @param  array<string, string>  $repositoryGrants  Repository id => access level.
     */
    public function create(array $attributes, UserRole $role, array $repositoryGrants = []): User
    {
        return DB::transaction(function () use ($attributes, $role, $repositoryGrants): User {
            $user = $this->users->create($attributes);

            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$role->value]);

            $this->syncRepositoryGrants($user, $repositoryGrants);

            return $user->refresh();
        });
    }

    /**
     * Update an account's attributes and role.
     *
     * `syncRoles` rather than `assignRole`: a user holds exactly one role, so the
     * previous one must be revoked in the same operation.
     *
     * @param  array<string, mixed>  $attributes  Validated attributes; omit `password` to keep the current one.
     * @param  array<string, string>  $repositoryGrants  Repository id => access level.
     */
    public function update(User $user, array $attributes, UserRole $role, array $repositoryGrants = []): User
    {
        return DB::transaction(function () use ($user, $attributes, $role, $repositoryGrants): User {
            $this->users->update($user, $attributes);

            $user->syncRoles([$role->value]);

            // Roles are re-read before deciding what to do with grants: promoting a
            // Contributor to Member changes whether grants are meaningful at all.
            $this->syncRepositoryGrants($user->refresh(), $repositoryGrants);

            return $user->refresh();
        });
    }

    /**
     * Replace a user's per-repository grants.
     *
     * Grants only mean anything for a user who lacks `repositories.view-all`; for
     * anyone else repository access is already global. Rather than storing rows that
     * silently do nothing, the grants are cleared — so if that user is later demoted to
     * a scoped role, they start from no access rather than inheriting a stale set
     * somebody granted long ago.
     *
     * @param  array<string, string>  $grants  Repository id => access level.
     */
    private function syncRepositoryGrants(User $user, array $grants): void
    {
        if (! $user->isRepositoryScoped()) {
            $user->repositories()->detach();

            return;
        }

        $payload = [];

        foreach ($grants as $repositoryId => $level) {
            $payload[$repositoryId] = [
                'access_level' => (RepositoryAccessLevel::tryFrom($level) ?? RepositoryAccessLevel::View)->value,
            ];
        }

        $user->repositories()->sync($payload);
    }

    /**
     * Permanently remove an account.
     *
     * Role assignments cascade with the user row, so no explicit cleanup is required.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->users->delete($user);
        });
    }
}
