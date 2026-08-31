<?php

namespace App\Support\Presenters\Users;

use App\Models\GIT\GitRepository;
use App\Models\Users\User;
use Illuminate\Support\Collection;

/**
 * Turns User models into the plain arrays the Inertia pages consume.
 *
 * Presenting in one place keeps the shape consistent across pages and guarantees
 * that sensitive columns (password hash, two-factor secret, remember token) are
 * never serialized by accident.
 */
final class UserPresenter
{
    /**
     * Serialize for the front end.
     *
     * @return array<string, mixed>
     */
    public static function toArray(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
            // Null when the user can see every repository, so the UI can distinguish
            // "unrestricted" from "granted nothing".
            'repository_grants' => self::repositoryGrants($user),
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
            'update_url' => route('admin.users.update', $user->id),
            'destroy_url' => route('admin.users.destroy', $user->id),
        ];
    }

    /**
     * The user's per-repository grants as repository id => access level, or null when
     * repository access is global for them.
     *
     * @return array<string, string>|null
     */
    private static function repositoryGrants(User $user): ?array
    {
        if (! $user->isRepositoryScoped()) {
            return null;
        }

        return $user->repositories
            ->mapWithKeys(static fn (GitRepository $repository): array => [
                $repository->id => (string) $repository->pivot->access_level,
            ])
            ->all();
    }

    /**
     * Serialize a set of records for the front end.
     *
     * @param  iterable<int, User>  $users
     * @return array<int, array<string, mixed>>
     */
    public static function collection(iterable $users): array
    {
        return Collection::make($users)
            ->map(static fn (User $user): array => self::toArray($user))
            ->values()
            ->all();
    }
}
