<?php

namespace App\Models\GIT;

use App\Enums\GIT\PullRequestCommentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'pull_request_id',
    'pull_request_review_finding_id',
    'provider_comment_id',
    'provider_in_reply_to_id',
    'comment_type',
    'author_login',
    'author_type',
    'body',
    'is_pull_lens',
    'provider_created_at',
    'provider_updated_at',
])]
class PullRequestComment extends Model
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
            'provider_comment_id' => 'integer',
            'provider_in_reply_to_id' => 'integer',
            'comment_type' => PullRequestCommentType::class,
            'is_pull_lens' => 'boolean',
            'provider_created_at' => 'datetime',
            'provider_updated_at' => 'datetime',
        ];
    }

    /**
     * The pull request this pull request comment belongs to.
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    /**
     * The pull request review finding this pull request comment belongs to.
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(PullRequestReviewFinding::class, 'pull_request_review_finding_id');
    }

    /**
     * The pull request review reply associated with this pull request comment.
     */
    public function reply(): HasOne
    {
        return $this->hasOne(PullRequestReviewReply::class, 'pull_request_comment_id');
    }
}
