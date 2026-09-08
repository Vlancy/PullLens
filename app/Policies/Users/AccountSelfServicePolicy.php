<?php

namespace App\Policies\Users;

use App\Enums\Users\UserRole;
use App\Models\Users\User;
use Illuminate\Auth\Access\Response;

/**
 * Rules governing what a user may do to their *own* account.
 *
 * Separate from UserPolicy, which governs administering *other* people's accounts.
 */
class AccountSelfServicePolicy
{
    /**
     * Whether the user may delete their own account.
     *
     * Refused for the last remaining administrator: public registration is disabled,
     * so deleting the final admin would leave the installation with no way to create
     * another one - an unrecoverable state, not merely an inconvenient one.
     */
    public function deleteOwnAccount(User $user): Response
    {
        if (! $user->isAdmin()) {
            return Response::allow();
        }

        $remainingAdmins = User::query()
            ->role(UserRole::Admin->value)
            ->whereKeyNot($user->getKey())
            ->count();

        return $remainingAdmins > 0
            ? Response::allow()
            : Response::deny('You are the only administrator. Promote another user before deleting your account.');
    }
}
