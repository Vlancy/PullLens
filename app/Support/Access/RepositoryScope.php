<?php

namespace App\Support\Access;

use App\Models\GIT\GitRepository;
use App\Models\Users\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The set of repositories a request is allowed to touch.
 *
 * Exists to keep one dangerous distinction explicit everywhere it travels:
 *
 *   - **unrestricted** - the user may see every repository.
 *   - **restricted to []** - the user has been granted nothing and must see nothing.
 *
 * Passing a bare `array` around invites collapsing those two into "no filter", which
 * fails open. This value object makes the unrestricted case something a caller has to
 * ask for by name.
 */
final readonly class RepositoryScope
{
    /**
     * Create the instance.
     *
     * @param  array<int, string>|null  $repositoryIds  Null means unrestricted.
     */
    private function __construct(private ?array $repositoryIds) {}

    /**
     * Every repository, with no filtering.
     */
    public static function unrestricted(): self
    {
        return new self(null);
    }

    /**
     * Only the given repositories.
     *
     * @param  array<int, string>  $repositoryIds
     */
    public static function only(array $repositoryIds): self
    {
        return new self(array_values(array_unique($repositoryIds)));
    }

    /**
     * The scope implied by a user's permissions and repository grants.
     */
    public static function forUser(?User $user): self
    {
        if ($user === null) {
            return self::only([]);
        }

        $visible = $user->visibleRepositoryIds();

        return $visible === null ? self::unrestricted() : self::only($visible);
    }

    /**
     * The scope of repositories a user may *change*, as opposed to merely read.
     *
     * A read-only (`view`) grant is excluded, so a scoped user cannot resolve findings
     * or queue reviews on a repository they were only given visibility into.
     */
    public static function forUserManagement(?User $user): self
    {
        if ($user === null) {
            return self::only([]);
        }

        $manageable = $user->manageableRepositoryIds();

        return $manageable === null ? self::unrestricted() : self::only($manageable);
    }

    /**
     * Whether this scope filters anything at all.
     */
    public function isRestricted(): bool
    {
        return $this->repositoryIds !== null;
    }

    /**
     * Whether the scope permits nothing, making any query pointless.
     */
    public function isEmpty(): bool
    {
        return $this->repositoryIds === [];
    }

    /**
     * Whether one repository falls inside the scope.
     */
    public function allows(string $repositoryId): bool
    {
        return $this->repositoryIds === null || in_array($repositoryId, $this->repositoryIds, true);
    }

    /**
     * The allowed ids, or null when unrestricted.
     *
     * @return array<int, string>|null
     */
    public function ids(): ?array
    {
        return $this->repositoryIds;
    }

    /**
     * Narrow this scope by an additional, user-chosen repository filter.
     *
     * A filter for a repository outside the scope yields an empty scope rather than
     * widening it - a hand-edited `repository_id` cannot escape the user's grants.
     */
    public function intersect(?string $repositoryId): self
    {
        if ($repositoryId === null) {
            return $this;
        }

        return $this->allows($repositoryId) ? self::only([$repositoryId]) : self::only([]);
    }

    /**
     * Constrain a query whose table carries the given repository foreign key.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyTo(Builder $query, string $column = 'git_repository_id'): Builder
    {
        return $this->repositoryIds === null
            ? $query
            : $query->whereIn($column, $this->repositoryIds);
    }

    /**
     * Constrain a query over the repositories table itself.
     *
     * @param  Builder<GitRepository>  $query
     * @return Builder<GitRepository>
     */
    public function applyToRepositories(Builder $query, string $column = 'id'): Builder
    {
        return $this->applyTo($query, $column);
    }
}
