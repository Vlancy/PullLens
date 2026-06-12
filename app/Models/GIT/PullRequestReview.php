<?php

namespace App\Models\GIT;

use App\Enums\GIT\ReviewIntensity;
use App\Enums\GIT\ReviewTrigger;
use App\Enums\GIT\ReviewVerdict;
use App\Models\AI\AiProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'pull_request_id',
    'head_sha',
    'ai_provider_id',
    'ai_model',
    'schema_version',
    'walkthrough',
    'diagram',
    'detected_stack',
    'suggested_labels',
    'skipped_files',
    'summary',
    'verdict',
    'risk_level',
    'review_intensity',
    'follow_up_questions',
    'triggered_by',
    'posted_to_provider',
    'provider_review_id',
    'review_duration_ms',
    'reviewed_at',
])]
class PullRequestReview extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'detected_stack' => 'array',
            'suggested_labels' => 'array',
            'skipped_files' => 'array',
            'follow_up_questions' => 'array',
            'verdict' => ReviewVerdict::class,
            'review_intensity' => ReviewIntensity::class,
            'triggered_by' => ReviewTrigger::class,
            'posted_to_provider' => 'boolean',
            'provider_review_id' => 'integer',
            'review_duration_ms' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(PullRequestReviewFinding::class);
    }
}
