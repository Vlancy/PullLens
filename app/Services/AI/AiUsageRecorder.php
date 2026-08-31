<?php

namespace App\Services\AI;

use App\Enums\AI\AiOperation;
use App\Models\AI\AiUsageRecord;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Usage;
use Throwable;

/**
 * Writes the ledger entry for a call to an AI provider.
 *
 * Recording is strictly best-effort: a failure here must never take down the work
 * that just succeeded. Losing one accounting row is a nuisance; losing a completed
 * PR review because the accounting row would not insert is a bug.
 */
class AiUsageRecorder
{
    public function __construct(private readonly AiCostCalculator $costs) {}

    /**
     * Record one call.
     *
     * @param  array<string, mixed>  $context  Optional pull_request, review, user_id, and a
     *                                         model override for calls made against an
     *                                         ad-hoc provider configuration.
     */
    public function record(
        AiOperation $operation,
        ?ResolvedAiProvider $provider,
        Usage $usage,
        ?int $durationMs = null,
        array $context = [],
        bool $succeeded = true,
    ): ?AiUsageRecord {
        try {
            $model = $context['model']
                ?? $provider?->model
                ?? $provider?->provider->default_model;

            $pullRequest = $context['pull_request'] ?? null;
            $review = $context['review'] ?? null;

            return AiUsageRecord::create([
                'operation' => $operation->value,
                'ai_provider_id' => $provider?->provider->id,
                'provider_driver' => $provider?->provider->provider_driver?->value,
                'model' => $model,
                'git_repository_id' => $pullRequest instanceof PullRequest
                    ? $pullRequest->git_repository_id
                    : ($context['git_repository_id'] ?? null),
                'pull_request_id' => $pullRequest instanceof PullRequest ? $pullRequest->id : null,
                'pull_request_review_id' => $review instanceof PullRequestReview ? $review->id : null,
                'user_id' => $context['user_id'] ?? null,
                'prompt_tokens' => $usage->promptTokens,
                'completion_tokens' => $usage->completionTokens,
                'cache_write_tokens' => $usage->cacheWriteInputTokens,
                'cache_read_tokens' => $usage->cacheReadInputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
                'total_tokens' => $this->totalTokens($usage),
                'cost_usd' => $this->costs->cost($model, $usage),
                'cost_is_estimated' => true,
                'duration_ms' => $durationMs,
                'succeeded' => $succeeded,
            ]);
        } catch (Throwable $e) {
            Log::warning('ai_usage.record_failed', [
                'operation' => $operation->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Every token the call was billed for.
     *
     * Cache reads are included: they are cheaper than fresh input, but they are not
     * free, and excluding them would make a cached review look like it consumed
     * almost nothing.
     */
    private function totalTokens(Usage $usage): int
    {
        return $usage->promptTokens
            + $usage->completionTokens
            + $usage->cacheWriteInputTokens
            + $usage->cacheReadInputTokens
            + $usage->reasoningTokens;
    }
}
