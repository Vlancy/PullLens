<?php

namespace App\Policies\Users;

use App\Enums\Users\UserPermission;
use App\Models\Users\User;

/**
 * Authorization rules for administering other user accounts.
 *
 * Two invariants are enforced here rather than in the controller so that every
 * entry point (HTTP, console, future API) gets them for free:
 *
 *   1. The actor must hold the users.manage permission.
 *   2. Nobody may edit or delete their own account through the admin panel -
 *      self-service changes belong in personal settings, and this prevents an
 *      administrator from locking themselves out or escalating in place.
 */
class UserPolicy
{
    /**
     * Whether the actor may list user accounts.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(UserPermission::ManageUsers);
    }

    /**
     * Whether the actor may open a user account.
     */
    public function view(User $actor): bool
    {
        return $actor->hasPermission(UserPermission::ManageUsers);
    }

    /**
     * Whether the actor may provision a new account.
     */
    public function create(User $actor): bool
    {
        return $actor->hasPermission(UserPermission::ManageUsers);
    }

    /**
     * Whether the actor may edit this account.
     *
     * Editing your own account here is refused; personal changes belong in settings.
     */
    public function update(User $actor, User $target): bool
    {
        return $actor->hasPermission(UserPermission::ManageUsers)
            && ! $actor->is($target);
    }

    /**
     * Whether the actor may delete this account.
     *
     * Deleting your own account here is refused, so nobody can lock themselves out.
     */
    public function delete(User $actor, User $target): bool
    {
        return $actor->hasPermission(UserPermission::ManageUsers)
            && ! $actor->is($target);
    }
}
