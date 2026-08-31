<?php

namespace App\Models\GIT;

use App\Enums\GIT\TaskStatus;
use App\Enums\GIT\TaskTrackerProvider;
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
    'status',
    'description',
    'estimated_hours',
    'rework_count',
    'revision_count',
    'files',
    'author_login',
    'author_name',
    'author_avatar_url',
    'delivered_at',
    'first_delivered_at',
    'reverted_at',
    'external_provider',
    'external_key',
    'external_url',
])]
class PullRequestTask extends Model
{
    use HasUuids;

    /**
     * Attribute casts for this model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'external_provider' => TaskTrackerProvider::class,
            'files' => 'array',
            'estimated_hours' => 'float',
            'rework_count' => 'integer',
            'revision_count' => 'integer',
            'delivered_at' => 'datetime',
            'first_delivered_at' => 'datetime',
            'reverted_at' => 'datetime',
        ];
    }

    /**
     * The pull request review this pull request task belongs to.
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(PullRequestReview::class, 'pull_request_review_id');
    }

    /**
     * The pull request this pull request task belongs to.
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    /**
     * The git repository this pull request task belongs to.
     */
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
     * Links where this task is the newer one — what it fixes, extends or reverts.
     *
     * @return HasMany<PullRequestTaskLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(PullRequestTaskLink::class, 'task_id');
    }

    /**
     * Links pointing at this task — what later work did to it.
     *
     * This is the side that answers "did this come back?".
     *
     * @return HasMany<PullRequestTaskLink, $this>
     */
    public function inboundLinks(): HasMany
    {
        return $this->hasMany(PullRequestTaskLink::class, 'related_task_id');
    }

    /**
     * Whether the task shipped and nothing has come back to it since.
     */
    public function isFirstTimeRight(): bool
    {
        return $this->status?->isCleanDelivery() === true;
    }

    /**
     * The status implied by this task's current links and delivery state.
     *
     * Derived rather than set, so the lifecycle cannot drift from the evidence: the
     * worst outcome any inbound link implies wins, otherwise it is simply delivered
     * or still in progress.
     */
    public function deriveStatus(): TaskStatus
    {
        $base = $this->delivered_at !== null ? TaskStatus::Delivered : TaskStatus::InProgress;

        foreach ($this->inboundLinks as $link) {
            $implied = $link->relation?->impliedStatusForTarget();

            if ($implied !== null && $implied->rank() > $base->rank()) {
                $base = $implied;
            }
        }

        return $base;
    }

    /**
     * Restrict to tasks whose title, description or external key matches a keyword.
     *
     * LIKE wildcards in the keyword are escaped, so searching for "%" looks for a
     * literal percent sign rather than matching everything.
     *
     * @param  Builder<PullRequestTask>  $query
     * @return Builder<PullRequestTask>
     */
    public function scopeSearch(Builder $query, ?string $keyword): Builder
    {
        if (blank($keyword)) {
            return $query;
        }

        $escaped = addcslashes(trim($keyword), '%_\\');

        return $query->where(function (Builder $inner) use ($escaped): void {
            $inner->where('title', 'like', "%{$escaped}%")
                ->orWhere('description', 'like', "%{$escaped}%")
                ->orWhere('external_key', 'like', "%{$escaped}%");
        });
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
     * Tasks that shipped and never came back — the first-time-right set.
     *
     * @param  Builder<PullRequestTask>  $query
     * @return Builder<PullRequestTask>
     */
    public function scopeFirstTimeRight(Builder $query): Builder
    {
        return $query->where('status', TaskStatus::Delivered->value);
    }

    /**
     * Tasks a later task had to fix or revert.
     *
     * @param  Builder<PullRequestTask>  $query
     * @return Builder<PullRequestTask>
     */
    public function scopeNeededRework(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TaskStatus::Reworked->value,
            TaskStatus::Reverted->value,
        ]);
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
