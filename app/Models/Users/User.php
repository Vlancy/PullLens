<?php

namespace App\Models\Users;

use App\Enums\Users\RepositoryAccessLevel;
use App\Enums\Users\UserPermission;
use App\Enums\Users\UserRole;
use App\Models\GIT\GitRepository;
use App\Policies\Users\UserPolicy;
use Database\Factories\Users\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * An authenticated operator of the application.
 *
 * Accounts are provisioned exclusively by an administrator - public self-service
 * registration is disabled (see config/fortify.php and the EnsureRegistrationIsDisabled
 * middleware), so there is no unauthenticated path that creates a User.
 */
#[UsePolicy(UserPolicy::class)]
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Repositories explicitly granted to this user.
     *
     * Only consulted for users who lack `repositories.view-all`; for everyone else
     * repository visibility is global and this relation is irrelevant.
     *
     * @return BelongsToMany<GitRepository, $this>
     */
    public function repositories(): BelongsToMany
    {
        return $this->belongsToMany(GitRepository::class, 'git_repository_user')
            ->withPivot('access_level')
            ->withTimestamps();
    }

    /**
     * Whether repository visibility is limited to explicit grants for this user.
     */
    public function isRepositoryScoped(): bool
    {
        return ! $this->hasPermission(UserPermission::ViewAllRepositories);
    }

    /**
     * Ids of the repositories this user may see, or null when they may see them all.
     *
     * Null and "empty array" mean very different things - unrestricted versus granted
     * nothing - so the distinction is preserved rather than collapsed to a list.
     *
     * @return array<int, string>|null
     */
    public function visibleRepositoryIds(): ?array
    {
        if (! $this->isRepositoryScoped()) {
            return null;
        }

        return $this->repositories()->pluck('git_repositories.id')->all();
    }

    /**
     * Ids of the repositories this user may change, or null when they may change all.
     *
     * Narrower than visibleRepositoryIds(): a `view` grant is read-only, so it does
     * not appear here.
     *
     * @return array<int, string>|null
     */
    public function manageableRepositoryIds(): ?array
    {
        if (! $this->isRepositoryScoped()) {
            return null;
        }

        return $this->repositories()
            ->wherePivot('access_level', RepositoryAccessLevel::Manage->value)
            ->pluck('git_repositories.id')
            ->all();
    }

    /**
     * The access level granted on one repository, or null when none was granted.
     */
    public function accessLevelFor(GitRepository $repository): ?RepositoryAccessLevel
    {
        $grant = $this->repositories()
            ->whereKey($repository->getKey())
            ->first();

        return $grant === null
            ? null
            : RepositoryAccessLevel::tryFrom((string) $grant->pivot->access_level);
    }

    /**
     * Whether the user holds the administrator role.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole(UserRole::Admin->value);
    }

    /**
     * Type-safe wrapper around the permission check, so call sites never pass a raw string.
     */
    public function hasPermission(UserPermission $permission): bool
    {
        return $this->can($permission->value);
    }

    /**
     * Assign a role using the enum rather than its string value.
     */
    public function assignRoleEnum(UserRole $role): self
    {
        $this->assignRole($role->value);

        return $this;
    }

    /**
     * Restrict a query to users matching a free-text search on name or email.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeSearch(Builder $query, ?string $keyword): Builder
    {
        if (blank($keyword)) {
            return $query;
        }

        // Escape LIKE wildcards so user input cannot widen the match.
        $escaped = addcslashes($keyword, '%_\\');

        return $query->where(function (Builder $inner) use ($escaped): void {
            $inner->where('name', 'like', "%{$escaped}%")
                ->orWhere('email', 'like', "%{$escaped}%");
        });
    }
}
