<?php

use App\Enums\AI\AiOperation;
use App\Enums\AI\AiProviderDriver;
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
        ['model' => 'claude-sonnet-5'],
    );

    expect($record)->not->toBeNull()
        ->and($record->total_tokens)->toBe(12_000)
        // 10k input at $2/M + 2k output at $10/M = $0.02 + $0.02
        ->and(round((float) $record->cost_usd, 4))->toBe(0.04)
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
        ['model' => 'claude-sonnet-5'],
    );

    // Cache reads are billed, so they belong in the total.
    expect($record->total_tokens)->toBe(11_500)
        ->and($record->cache_read_tokens)->toBe(8_000);

    // 8k cached reads at $0.30/M cost far less than 8k fresh input at $3/M.
    $cached = (float) $record->cost_usd;
    $fresh = app(AiCostCalculator::class)->cost(
        'claude-sonnet-5',
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

    // Dated snapshot resolves via the dateless prefix.
    expect($calculator->hasPricing('claude-haiku-4-5-20251001'))->toBeTrue()
        // The Bedrock prefix is priced separately from the bare Claude id.
        ->and($calculator->hasPricing('anthropic.claude-sonnet-5'))->toBeTrue()
        ->and($calculator->hasPricing('totally-unknown'))->toBeFalse();
});

test('the longest matching price entry wins', function () {
    $calculator = app(AiCostCalculator::class);

    $usage = new Usage(promptTokens: 1_000_000);

    // "claude-opus-5" must not be matched by a shorter, cheaper prefix.
    expect($calculator->cost('claude-opus-5', $usage))->toBe(5.0)
        ->and($calculator->cost('claude-sonnet-5', $usage))->toBe(2.0)
        ->and($calculator->cost('claude-fable-5', $usage))->toBe(10.0);
});

test('a self-hosted model is priced at zero, not left unknown', function () {
    $calculator = app(AiCostCalculator::class);

    // Zero is the true price for local inference, and must be distinguishable
    // from "no price on file".
    expect($calculator->hasPricing('qwen3-coder:30b'))->toBeTrue()
        ->and($calculator->cost('qwen3-coder:30b', new Usage(promptTokens: 500_000)))->toBe(0.0);
});

test('a model with no confirmed price records no cost', function () {
    $calculator = app(AiCostCalculator::class);

    // GPT-5.6 and Gemini 3.x rates are deliberately not in the table until
    // confirmed, so their calls record tokens with a null cost.
    expect($calculator->hasPricing('gpt-5.6-terra'))->toBeFalse()
        ->and($calculator->cost('gemini-3.7-flash', new Usage(promptTokens: 1_000)))->toBeNull();
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

test('the usage report renders on its default period, with and without data', function (bool $withData) {
    // The default period applies a date filter, and the by-repository breakdown joins
    // git_repositories — which also has a created_at. An unqualified reference there is
    // ambiguous and 500s. Exercising the page WITHOUT ?period=all is what catches it.
    if ($withData) {
        $repository = GitRepository::factory()->create();

        AiUsageRecord::query()->create([
            'operation' => AiOperation::PullRequestReview->value,
            'model' => 'claude-sonnet-5',
            'git_repository_id' => $repository->id,
            'prompt_tokens' => 1_000, 'completion_tokens' => 100, 'total_tokens' => 1_100,
            'cost_usd' => 0.003,
        ]);
    }

    $this->actingAs(User::factory()->admin()->create());

    $this->get('/reports/ai-usage')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats.calls', $withData ? 1 : 0)->etc());
})->with([true, false]);

test('every report page renders on its default period', function (string $route) {
    // Guards the whole family against the same class of failure: a page that is only
    // ever tested with an explicit period never exercises its date filter.
    $this->actingAs(User::factory()->admin()->create());

    $this->get($route)->assertOk();
})->with([
    '/reports/ai-usage',
    '/reports/tasks',
    '/reports',
    '/reports/repositories',
]);

test('the usage report is closed to a user without reports access', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/reports/ai-usage')->assertForbidden();
});

test('every driver recommends a model that appears in its select list', function () {
    // The PHP default and the TypeScript select list are maintained separately;
    // a driver whose default is not offered would silently fall back to a blank.
    $list = file_get_contents(resource_path('js/lib/ai-models.ts'));

    foreach (AiProviderDriver::cases() as $driver) {
        expect($list)->toContain("'{$driver->recommendedModel()}'");
    }
});

test('no retired model is still offered as a default', function () {
    // Gemini 2.5 shuts down October 2026; Groq retired the Llama 3.x production
    // models in August 2026. Both were previous defaults here.
    $defaults = array_map(
        static fn (AiProviderDriver $driver): string => $driver->recommendedModel(),
        AiProviderDriver::cases(),
    );

    foreach (['gemini-2.5-pro', 'gemini-2.0-flash', 'llama-3.3-70b-versatile', 'claude-sonnet-4-6'] as $retired) {
        expect($defaults)->not->toContain($retired);
    }
});
