<?php

namespace App\Models\AI;

use App\Enums\AI\AiOperation;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to an AI provider, with what it consumed and what it cost.
 */
#[Fillable([
    'operation',
    'ai_provider_id',
    'provider_driver',
    'model',
    'git_repository_id',
    'pull_request_id',
    'pull_request_review_id',
    'user_id',
    'prompt_tokens',
    'completion_tokens',
    'cache_write_tokens',
    'cache_read_tokens',
    'reasoning_tokens',
    'total_tokens',
    'cost_usd',
    'cost_is_estimated',
    'duration_ms',
    'succeeded',
])]
class AiUsageRecord extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => AiOperation::class,
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost_usd' => 'decimal:8',
            'cost_is_estimated' => 'boolean',
            'duration_ms' => 'integer',
            'succeeded' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
    }

    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PullRequestReview::class, 'pull_request_review_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Records created on or after the given moment.
     *
     * @param  Builder<AiUsageRecord>  $query
     * @return Builder<AiUsageRecord>
     */
    public function scopeSince(Builder $query, ?\DateTimeInterface $since): Builder
    {
        return $query->when($since, fn (Builder $q) => $q->where('created_at', '>=', $since));
    }

    /**
     * Records for operations nobody explicitly asked for — the spend that grows on
     * its own and is therefore worth watching.
     *
     * @param  Builder<AiUsageRecord>  $query
     * @return Builder<AiUsageRecord>
     */
    public function scopeAutomatic(Builder $query): Builder
    {
        $automatic = array_values(array_filter(
            AiOperation::cases(),
            static fn (AiOperation $operation): bool => $operation->isAutomatic(),
        ));

        return $query->whereIn('operation', array_column($automatic, 'value'));
    }
}
