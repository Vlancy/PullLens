<?php

namespace App\Models\GIT;

use App\Enums\GIT\FindingCategory;
use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSeverity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'pull_request_review_id',
    'pull_request_id',
    'git_repository_id',
    'dedupe_key',
    'title',
    'severity',
    'category',
    'file',
    'file_language',
    'line',
    'confidence',
    'explanation',
    'suggested_fix',
    'is_posted',
    'is_helpful',
    'provider_comment_id',
    'resolved_at',
    'resolution_type',
])]
class PullRequestReviewFinding extends Model
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
            'severity' => FindingSeverity::class,
            'category' => FindingCategory::class,
            'resolution_type' => FindingResolutionType::class,
            'line' => 'integer',
            'confidence' => 'float',
            'is_posted' => 'boolean',
            'is_helpful' => 'boolean',
            'provider_comment_id' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * The pull request review this pull request review finding belongs to.
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(PullRequestReview::class, 'pull_request_review_id');
    }

    /**
     * The pull request this pull request review finding belongs to.
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    /**
     * The git repository this pull request review finding belongs to.
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
    }

    /**
     * The pull request comments belonging to this pull request review finding.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(PullRequestComment::class, 'pull_request_review_finding_id');
    }
}
