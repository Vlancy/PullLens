<?php

namespace App\Policies\GIT;

use App\Enums\Users\UserPermission;
use App\Models\GIT\GitRepository;
use App\Models\Users\User;

/**
 * Authorization for a single repository.
 *
 * Two independent gates apply, and both must pass:
 *
 *   1. The functional permission — may this user view findings / trigger reviews at all?
 *   2. Repository scope — may they see *this* repository?
 *
 * A user holding `repositories.view-all` clears the second gate for everything;
 * otherwise it is decided by the grant pivot and its access level.
 */
class GitRepositoryPolicy
{
    /**
     * Whether the user may open this repository.
     */
    public function view(User $user, GitRepository $repository): bool
    {
        return $this->isVisible($user, $repository);
    }

    /**
     * Whether the user may queue an AI review against this repository.
     */
    public function manage(User $user, GitRepository $repository): bool
    {
        return $this->canChange($user, $repository, UserPermission::TriggerReviews);
    }

    /**
     * Whether the user may resolve findings raised in this repository.
     *
     * Kept distinct from manage(): resolving a finding and spending AI credit are
     * different actions behind different permissions, and an administrator editing the
     * role matrix may reasonably grant one without the other.
     */
    public function resolveFindings(User $user, GitRepository $repository): bool
    {
        return $this->canChange($user, $repository, UserPermission::ResolveFindings);
    }

    /**
     * Shared rule for state-changing actions: the functional permission, plus — for a
     * scoped user — an explicit `manage` grant. A `view` grant is read-only.
     */
    private function canChange(User $user, GitRepository $repository, UserPermission $permission): bool
    {
        if (! $user->hasPermission($permission)) {
            return false;
        }

        if (! $user->isRepositoryScoped()) {
            return true;
        }

        return $user->accessLevelFor($repository)?->allowsManagement() === true;
    }

    /**
     * Whether the repository falls inside the user's visibility scope.
     */
    private function isVisible(User $user, GitRepository $repository): bool
    {
        if (! $user->isRepositoryScoped()) {
            return true;
        }

        return $user->accessLevelFor($repository) !== null;
    }
}
