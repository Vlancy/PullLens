<?php

namespace App\Models\GIT;

use App\Enums\GIT\PullRequestState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'git_repository_id',
    'provider_pr_id',
    'number',
    'title',
    'description',
    'state',
    'is_draft',
    'author_login',
    'author_name',
    'author_avatar_url',
    'source_branch',
    'target_branch',
    'web_url',
    'additions',
    'deletions',
    'changed_files_count',
    'commits_count',
    'labels',
    'opened_at',
    'closed_at',
    'merged_at',
    'merged_by_login',
    'merge_commit_sha',
    'head_sha',
    'provider_updated_at',
    'last_synced_at',
])]
class PullRequest extends Model
{
    use HasUuids;

    /**
     * Attribute casts for this model.
     *      *
     *      * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_pr_id' => 'integer',
            'number' => 'integer',
            'state' => PullRequestState::class,
            'is_draft' => 'boolean',
            'additions' => 'integer',
            'deletions' => 'integer',
            'changed_files_count' => 'integer',
            'commits_count' => 'integer',
            'labels' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'merged_at' => 'datetime',
            'provider_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * The git repository this pull belongs to.
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
    }

    /**
     * The pull request commits belonging to this pull.
     */
    public function commits(): HasMany
    {
        return $this->hasMany(PullRequestCommit::class);
    }

    /**
     * The pull request contributors belonging to this pull.
     */
    public function contributors(): HasMany
    {
        return $this->hasMany(PullRequestContributor::class);
    }

    /**
     * The pull request files belonging to this pull.
     */
    public function files(): HasMany
    {
        return $this->hasMany(PullRequestFile::class);
    }

    /**
     * The pull request reviews belonging to this pull.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(PullRequestReview::class);
    }

    /**
     * Findings raised against this pull request across all of its reviews.
     *
     * Findings carry `pull_request_id` directly, so this avoids hopping through the
     * reviews relation when only the PR-level total is needed.
     */
    public function findings(): HasMany
    {
        return $this->hasMany(PullRequestReviewFinding::class);
    }

    /**
     * The pull request review associated with this pull.
     */
    public function latestReview(): HasOne
    {
        // latestOfMany() / ofMany() both generate MAX(id) as a tiebreaker,
        // which PostgreSQL rejects for UUID columns. Use a correlated subquery
        // instead - equally efficient for typical page sizes and eager-loading safe.
        return $this->hasOne(PullRequestReview::class)
            ->whereNotNull('reviewed_at')
            ->whereRaw(
                '"pull_request_reviews"."reviewed_at" = ('
                .'select max(r."reviewed_at") from "pull_request_reviews" r '
                .'where r."pull_request_id" = "pull_request_reviews"."pull_request_id" '
                .'and r."reviewed_at" is not null'
                .')'
            )
            ->latest('reviewed_at');
    }

    /**
     * The pull request comments belonging to this pull.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(PullRequestComment::class);
    }

    /**
     * The units of work this pull request delivered.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(PullRequestTask::class);
    }

    /**
     * The pull request events belonging to this pull.
     */
    public function events(): HasMany
    {
        return $this->hasMany(PullRequestEvent::class);
    }
}
