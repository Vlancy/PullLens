<?php

namespace App\Models\GIT;

use App\Enums\GIT\TaskType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discrete unit of work the reviewer identified in a pull request.
 *
 * Tasks answer "who did what" over a period. They are derived from the same AI call
 * that produces the review, so they cost nothing extra, and they carry the PR and
 * (through it) the commits that delivered them.
 */
#[Fillable([
    'pull_request_review_id',
    'pull_request_id',
    'git_repository_id',
    'dedupe_key',
    'title',
    'type',
    'description',
    'estimated_hours',
    'files',
    'author_login',
    'author_name',
    'author_avatar_url',
    'delivered_at',
])]
class PullRequestTask extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'files' => 'array',
            'estimated_hours' => 'float',
            'delivered_at' => 'datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PullRequestReview::class, 'pull_request_review_id');
    }

    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
    }

    /**
     * The commits that delivered this task.
     *
     * A task inherits its pull request's commits: the stored data maps commits to a
     * PR, not to individual files, so attributing a commit to one task within a
     * multi-task PR is not something the schema can support honestly.
     *
     * @return HasMany<PullRequestCommit, $this>
     */
    public function commits(): HasMany
    {
        return $this->hasMany(PullRequestCommit::class, 'pull_request_id', 'pull_request_id');
    }

    /**
     * Only tasks that actually shipped — the PR was merged.
     *
     * Open PRs describe intended work, which would inflate a "what did we deliver"
     * report, so the reports scope to this by default.
     *
     * @param  Builder<PullRequestTask>  $query
     * @return Builder<PullRequestTask>
     */
    public function scopeDelivered(Builder $query): Builder
    {
        return $query->whereNotNull('delivered_at');
    }

    /**
     * Tasks delivered inside a date window.
     *
     * @param  Builder<PullRequestTask>  $query
     * @return Builder<PullRequestTask>
     */
    public function scopeDeliveredBetween(Builder $query, ?\DateTimeInterface $from, ?\DateTimeInterface $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->where('delivered_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('delivered_at', '<=', $to));
    }
}
