<?php

namespace App\Models\GIT;

use App\Enums\GIT\GitProvider;
use App\Enums\GIT\MergeMethod;
use App\Enums\GIT\ReviewIntensity;
use App\Enums\GIT\ReviewTone;
use App\Models\AI\AiProvider;
use App\Models\Users\User;
use App\Policies\GIT\GitRepositoryPolicy;
use Database\Factories\GIT\GitRepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UsePolicy(GitRepositoryPolicy::class)]
#[Fillable([
    'git_account_id',
    'provider',
    'installation_id',
    'provider_repo_id',
    'owner_login',
    'owner_type',
    'name',
    'full_name',
    'default_branch',
    'is_private',
    'web_url',
    'reviews_enabled',
    'record_all_activity',
    'auto_review_on_open',
    'auto_approve',
    'auto_apply_labels',
    'auto_fill_pr_description',
    'auto_enhance_pr_title',
    'allow_comment_replies',
    'auto_merge',
    'auto_merge_method',
    'review_language',
    'review_tone',
    'use_emoji',
    'base_branches',
    'tracked_branches',
    'ai_provider_id',
    'ai_model',
    'review_intensity',
    'webhook_hook_id',
])]
class GitRepository extends Model
{
    /** @use HasFactory<GitRepositoryFactory> */
    use HasFactory, HasUuids;

    /**
     * Return casts for the provider enum, identifiers, flags, and timestamps.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => GitProvider::class,
            'installation_id' => 'integer',
            'provider_repo_id' => 'integer',
            'is_private' => 'boolean',
            'reviews_enabled' => 'boolean',
            'record_all_activity' => 'boolean',
            'auto_review_on_open' => 'boolean',
            'auto_approve' => 'boolean',
            'auto_apply_labels' => 'boolean',
            'auto_fill_pr_description' => 'boolean',
            'auto_enhance_pr_title' => 'boolean',
            'allow_comment_replies' => 'boolean',
            'auto_merge' => 'boolean',
            'auto_merge_method' => MergeMethod::class,
            'review_intensity' => ReviewIntensity::class,
            'review_tone' => ReviewTone::class,
            'use_emoji' => 'boolean',
            'base_branches' => 'array',
            'tracked_branches' => 'array',
            'ai_provider_id' => 'string',
        ];
    }

    /**
     * AI provider override configured for this repository.
     *
     * @return BelongsTo<AiProvider, $this>
     */
    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }

    /**
     * The operator account whose token was used to select this repository.
     *
     * @return BelongsTo<GitAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(GitAccount::class, 'git_account_id');
    }

    /**
     * Branches discovered for this repository at selection time.
     *
     * @return HasMany<GitRepositoryBranch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(GitRepositoryBranch::class);
    }

    /**
     * Pull requests tracked for this repository.
     *
     * @return HasMany<PullRequest, $this>
     */
    public function pullRequests(): HasMany
    {
        return $this->hasMany(PullRequest::class);
    }

    /**
     * Users explicitly granted access to this repository.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'git_repository_user')
            ->withPivot('access_level')
            ->withTimestamps();
    }

    /**
     * Restrict a query to the repositories a user is allowed to see.
     *
     * Users holding `repositories.view-all` are unrestricted. Everyone else sees only
     * what the grant pivot lists — including, deliberately, nothing at all when they
     * have been granted nothing.
     *
     * @param  Builder<GitRepository>  $query
     * @return Builder<GitRepository>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        $visibleIds = $user->visibleRepositoryIds();

        return $visibleIds === null ? $query : $query->whereIn('id', $visibleIds);
    }

    /**
     * Review findings raised anywhere in this repository.
     *
     * @return HasMany<PullRequestReviewFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(PullRequestReviewFinding::class);
    }

    /**
     * Every commit seen for this repository, from pushes as well as pull requests.
     *
     * @return HasMany<RepositoryCommit, $this>
     */
    public function commits(): HasMany
    {
        return $this->hasMany(RepositoryCommit::class);
    }
}
