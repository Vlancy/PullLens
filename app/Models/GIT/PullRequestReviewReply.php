<?php

namespace App\Models\GIT;

use App\Enums\GIT\CommentReplyType;
use App\Enums\GIT\CommentSuggestedResolution;
use App\Models\AI\AiProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pull_request_comment_id',
    'pull_request_review_id',
    'ai_provider_id',
    'ai_model',
    'schema_version',
    'reply',
    'reply_type',
    'addressed_finding_key',
    'confidence',
    'requires_author_action',
    'suggested_resolution',
    'posted_to_provider',
    'provider_comment_id',
    'replied_at',
])]
class PullRequestReviewReply extends Model
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
            'reply_type' => CommentReplyType::class,
            'suggested_resolution' => CommentSuggestedResolution::class,
            'confidence' => 'float',
            'requires_author_action' => 'boolean',
            'posted_to_provider' => 'boolean',
            'provider_comment_id' => 'integer',
            'replied_at' => 'datetime',
        ];
    }

    /**
     * The pull request comment this pull request review reply belongs to.
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(PullRequestComment::class, 'pull_request_comment_id');
    }

    /**
     * The pull request review this pull request review reply belongs to.
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(PullRequestReview::class, 'pull_request_review_id');
    }

    /**
     * The ai provider this pull request review reply belongs to.
     */
    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }
}
