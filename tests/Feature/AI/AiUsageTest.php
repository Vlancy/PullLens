<?php

use App\Enums\AI\AiOperation;
use App\Models\AI\AiUsageRecord;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\Users\User;
use App\Services\AI\AiCostCalculator;
use App\Services\AI\AiUsageRecorder;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Responses\Data\Usage;

/*
| Regression guard first: the review token columns were added with the code that
| writes them, then dropped by a later migration while the job and model kept using
| them. Any fresh install failed on every review with "column does not exist", so no
| review was ever persisted.
*/

test('reviews can store the token counts the job writes', function () {
    expect(Schema::hasColumn('pull_request_reviews', 'prompt_tokens'))->toBeTrue()
        ->and(Schema::hasColumn('pull_request_reviews', 'completion_tokens'))->toBeTrue();

    $repository = GitRepository::factory()->create();

    $pr = PullRequest::query()->create([
        'git_repository_id' => $repository->id,
        'provider_pr_id' => 1, 'number' => 1, 'title' => 't', 'state' => 'open',
        'author_login' => 'octo', 'source_branch' => 's', 'target_branch' => 'main',
        'opened_at' => now(),
    ]);

    $review = PullRequestReview::create([
        'pull_request_id' => $pr->id,
        'walkthrough' => 'w', 'detected_stack' => [], 'suggested_labels' => [],
        'skipped_files' => [], 'summary' => 's', 'verdict' => 'comment',
        'risk_level' => 'low', 'review_intensity' => 'balanced',
        'follow_up_questions' => [], 'triggered_by' => 'auto',
        'prompt_tokens' => 12_345,
        'completion_tokens' => 678,
    ]);

    expect($review->fresh()->prompt_tokens)->toBe(12_345);
});

test('a call is recorded with its tokens and estimated cost', function () {
    $usage = new Usage(promptTokens: 10_000, completionTokens: 2_000);

    $record = app(AiUsageRecorder::class)->record(
        AiOperation::PullRequestReview,
        null,
        $usage,
        4_200,
        ['model' => 'claude-sonnet-4-5-20250929'],
    );

    expect($record)->not->toBeNull()
        ->and($record->total_tokens)->toBe(12_000)
        // 10k input at $3/M + 2k output at $15/M = $0.03 + $0.03
        ->and(round((float) $record->cost_usd, 4))->toBe(0.06)
        ->and($record->duration_ms)->toBe(4_200);
});

test('cache tokens are counted and priced separately', function () {
    $usage = new Usage(
        promptTokens: 1_000,
        completionTokens: 500,
        cacheWriteInputTokens: 2_000,
        cacheReadInputTokens: 8_000,
    );

    $record = app(AiUsageRecorder::class)->record(
        AiOperation::PullRequestReview,
        null,
        $usage,
        null,
        ['model' => 'claude-sonnet-4-5'],
    );

    // Cache reads are billed, so they belong in the total.
    expect($record->total_tokens)->toBe(11_500)
        ->and($record->cache_read_tokens)->toBe(8_000);

    // 8k cached reads at $0.30/M cost far less than 8k fresh input at $3/M.
    $cached = (float) $record->cost_usd;
    $fresh = app(AiCostCalculator::class)->cost(
        'claude-sonnet-4-5',
        new Usage(promptTokens: 9_000, completionTokens: 500),
    );

    expect($cached)->toBeLessThan($fresh);
});

test('an unpriced model records tokens but never invents a cost', function () {
    $record = app(AiUsageRecorder::class)->record(
        AiOperation::AssistantChat,
        null,
        new Usage(promptTokens: 500, completionTokens: 100),
        null,
        ['model' => 'some-self-hosted-model'],
    );

    expect($record->total_tokens)->toBe(600)
        ->and($record->cost_usd)->toBeNull();
});

test('a dated model revision resolves through its base price entry', function () {
    $calculator = app(AiCostCalculator::class);

    expect($calculator->hasPricing('claude-sonnet-4-5-20250929'))->toBeTrue()
        ->and($calculator->hasPricing('gpt-4o-mini-2024-07-18'))->toBeTrue()
        ->and($calculator->hasPricing('totally-unknown'))->toBeFalse();
});

test('a malformed context is ignored rather than raising', function () {
    // Recording must never take down the work that just succeeded, so an unusable
    // context is dropped and the tokens are still accounted for.
    $record = app(AiUsageRecorder::class)->record(
        AiOperation::PullRequestReview,
        null,
        new Usage(promptTokens: 1),
        null,
        ['pull_request' => 'not-a-model'],
    );

    expect($record)->not->toBeNull()
        ->and($record->pull_request_id)->toBeNull()
        ->and($record->total_tokens)->toBe(1);
});

test('the usage report aggregates by operation, model and repository', function () {
    $repository = GitRepository::factory()->create(['full_name' => 'octo/demo']);

    AiUsageRecord::query()->create([
        'operation' => AiOperation::PullRequestReview->value,
        'model' => 'claude-sonnet-4-5',
        'git_repository_id' => $repository->id,
        'prompt_tokens' => 30_000, 'completion_tokens' => 3_000, 'total_tokens' => 33_000,
        'cost_usd' => 0.135, 'duration_ms' => 9_000,
    ]);

    AiUsageRecord::query()->create([
        'operation' => AiOperation::AssistantChat->value,
        'model' => 'claude-sonnet-4-5',
        'prompt_tokens' => 500, 'completion_tokens' => 200, 'total_tokens' => 700,
        'cost_usd' => 0.0045,
    ]);

    $this->actingAs(User::factory()->admin()->create());

    $this->get('/reports/ai-usage?period=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.calls', 2)
            ->where('stats.tokens', 33_700)
            ->where('by_operation.0.operation', 'pull_request_review')
            ->where('by_repository.0.repository', 'octo/demo')
            ->has('largest_calls', 2)
            ->etc());
});

test('the usage report is closed to a user without reports access', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/reports/ai-usage')->assertForbidden();
});
