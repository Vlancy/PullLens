<?php

use App\Ai\Agents\FeatureGroupingAgent;
use App\Enums\AI\AiOperation;
use App\Enums\GIT\AttributionSource;
use App\Jobs\Tasks\GroupTaskFeatures;
use App\Models\AI\AiProvider;
use App\Models\AI\AiUsageRecord;
use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestTask;
use App\Models\GIT\TaskFeature;
use App\Models\Users\User;
use App\Services\Tasks\Features\FeatureBackfillService;
use App\Services\Tasks\Features\FeatureResolver;
use App\Support\Tasks\FeatureScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Create an enabled default AI provider so grouping can resolve one.
 */
function fbAiProvider(): AiProvider
{
    return AiProvider::query()->create([
        'provider_driver' => 'openai',
        'name' => 'Default OpenAI',
        'credentials' => ['api_key' => 'key'],
        'default_model' => 'gpt-4o-mini',
        'is_default' => true,
        'is_enabled' => true,
    ]);
}

/**
 * Create a stored task in a fresh pull request, with no feature unless given.
 *
 * @param  array<string, mixed>  $attributes
 */
function fbTask(GitRepository $repository, string $title, array $attributes = []): PullRequestTask
{
    static $number = 0;
    $number++;

    $pullRequest = PullRequest::query()->create([
        'git_repository_id' => $repository->id,
        'provider_pr_id' => $number * 100,
        'number' => $number,
        'title' => "PR {$number}",
        'state' => 'open',
        'author_login' => 'octo',
        'source_branch' => "feature/{$number}",
        'target_branch' => 'main',
        'opened_at' => now()->subDays(2),
    ]);

    $review = PullRequestReview::query()->create([
        'pull_request_id' => $pullRequest->id,
        'walkthrough' => 'w',
        'detected_stack' => [],
        'suggested_labels' => [],
        'skipped_files' => [],
        'summary' => 's',
        'verdict' => 'comment',
        'risk_level' => 'low',
        'review_intensity' => 'balanced',
        'follow_up_questions' => [],
        'triggered_by' => 'auto',
        'reviewed_at' => now(),
    ]);

    return PullRequestTask::query()->create([
        'pull_request_review_id' => $review->id,
        'pull_request_id' => $pullRequest->id,
        'git_repository_id' => $repository->id,
        'dedupe_key' => "task-{$number}",
        'title' => $title,
        'type' => 'feature',
        'status' => 'in_progress',
        'description' => "{$title} work.",
        'estimated_hours' => 1,
        'files' => [],
        ...$attributes,
    ]);
}

/**
 * Begin a grouping run for the scope without running its job, returning the run id.
 */
function fbBegin(FeatureScope $scope): string
{
    Queue::fake();
    $backfill = app(FeatureBackfillService::class);
    $backfill->start($scope);

    return Cache::get($backfill->cacheKey($scope))['run_id'];
}

test('preview counts ungrouped tasks and batches', function () {
    $repository = GitRepository::factory()->create();
    $other = GitRepository::factory()->create();
    $feature = app(FeatureResolver::class)->resolve(FeatureScope::forRepository($repository), ['new_name' => 'Auth system']);

    foreach (range(1, 30) as $i) {
        fbTask($repository, "Task {$i}");
    }
    fbTask($repository, 'Grouped', ['task_feature_id' => $feature->id, 'feature_source' => AttributionSource::Ai]);
    fbTask($repository, 'Kept apart', ['feature_source' => AttributionSource::Manual]);
    fbTask($other, 'Elsewhere');

    $this->actingAs(User::factory()->manager()->create())
        ->getJson(route('projects.feature-grouping.preview', ['repository_id' => $repository->id]))
        ->assertOk()
        ->assertJson(['ungrouped' => 30, 'batches' => 2, 'running' => false, 'processed' => 0]);

    $project = Project::factory()->create();
    $a = GitRepository::factory()->create(['project_id' => $project->id]);
    $b = GitRepository::factory()->create(['project_id' => $project->id]);
    fbTask($a, 'One');
    fbTask($b, 'Two');

    expect(app(FeatureBackfillService::class)->preview(new FeatureScope($project->id, null)))
        ->toMatchArray(['ungrouped' => 2, 'batches' => 1, 'running' => false, 'processed' => 0]);
});

test('start dispatches once while running', function () {
    Queue::fake();
    fbAiProvider();
    $repository = GitRepository::factory()->create();
    fbTask($repository, 'Add login');
    $manager = User::factory()->manager()->create();

    $this->actingAs($manager)
        ->post(route('projects.feature-grouping.start'), ['repository_id' => $repository->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->actingAs($manager)
        ->post(route('projects.feature-grouping.start'), ['repository_id' => $repository->id])
        ->assertRedirect();

    Queue::assertPushedTimes(GroupTaskFeatures::class, 1);

    expect(app(FeatureBackfillService::class)->preview(new FeatureScope(null, $repository->id)))
        ->toMatchArray(['running' => true, 'processed' => 0]);
});

test('start fails without an ai provider', function () {
    Queue::fake();
    $repository = GitRepository::factory()->create();
    fbTask($repository, 'Add login');

    $this->actingAs(User::factory()->manager()->create())
        ->post(route('projects.feature-grouping.start'), ['repository_id' => $repository->id])
        ->assertSessionHasErrors(['scope' => 'No AI provider configured']);

    Queue::assertNothingPushed();
});

test('start rejects a repository that belongs to a project', function () {
    Queue::fake();
    fbAiProvider();
    $project = Project::factory()->create();
    $repository = GitRepository::factory()->create(['project_id' => $project->id]);

    $this->actingAs(User::factory()->manager()->create())
        ->post(route('projects.feature-grouping.start'), ['repository_id' => $repository->id])
        ->assertSessionHasErrors('repository_id');

    Queue::assertNothingPushed();
});

test('runBatch assigns tasks and ignores foreign task ids', function () {
    fbAiProvider();
    $project = Project::factory()->create();
    $a = GitRepository::factory()->create(['project_id' => $project->id]);
    $b = GitRepository::factory()->create(['project_id' => $project->id]);
    $scope = new FeatureScope($project->id, null);
    $existing = app(FeatureResolver::class)->resolve($scope, ['new_name' => 'Auth system']);

    $login = fbTask($a, 'Add login');
    $invoice = fbTask($b, 'Send invoices');
    $foreign = fbTask(GitRepository::factory()->create(), 'Outside');

    FeatureGroupingAgent::fake([[
        'assignments' => [
            ['task_id' => $login->id, 'existing_slug' => 'auth-system', 'new_name' => null, 'new_description' => null],
            ['task_id' => $invoice->id, 'existing_slug' => null, 'new_name' => 'Billing', 'new_description' => 'Charging customers.'],
            ['task_id' => $foreign->id, 'existing_slug' => null, 'new_name' => 'Hijack', 'new_description' => null],
        ],
    ]]);

    $count = app(FeatureBackfillService::class)->runBatch($scope, fbBegin($scope));

    expect($count)->toBe(2)
        ->and($login->fresh()->task_feature_id)->toBe($existing->id)
        ->and($login->fresh()->feature_source)->toBe(AttributionSource::Ai)
        ->and($invoice->fresh()->feature->name)->toBe('Billing')
        ->and($invoice->fresh()->feature->scope_key)->toBe($scope->key())
        ->and($foreign->fresh()->task_feature_id)->toBeNull()
        ->and(TaskFeature::query()->where('slug', 'hijack')->exists())->toBeFalse()
        ->and(AiUsageRecord::query()->where('operation', AiOperation::FeatureGrouping->value)->count())->toBe(1);

    FeatureGroupingAgent::assertPrompted(fn ($prompt) => $prompt->contains('auth-system') && $prompt->contains('Send invoices'));
});

test('manual tasks skipped', function () {
    fbAiProvider();
    $repository = GitRepository::factory()->create();
    $scope = new FeatureScope(null, $repository->id);
    $manual = fbTask($repository, 'Hand sorted', ['feature_source' => AttributionSource::Manual]);
    $open = fbTask($repository, 'Add search');

    FeatureGroupingAgent::fake([[
        'assignments' => [
            ['task_id' => $manual->id, 'existing_slug' => null, 'new_name' => 'Search', 'new_description' => null],
            ['task_id' => $open->id, 'existing_slug' => null, 'new_name' => 'Search', 'new_description' => null],
        ],
    ]]);

    app(FeatureBackfillService::class)->runBatch($scope, fbBegin($scope));

    expect($manual->fresh()->task_feature_id)->toBeNull()
        ->and($manual->fresh()->feature_source)->toBe(AttributionSource::Manual)
        ->and($open->fresh()->feature->name)->toBe('Search');

    FeatureGroupingAgent::assertNotPrompted(fn ($prompt) => $prompt->contains($manual->id));
});

test('loop terminates when agent returns no assignments', function () {
    fbAiProvider();
    $repository = GitRepository::factory()->create();
    $scope = new FeatureScope(null, $repository->id);

    foreach (range(1, 30) as $i) {
        fbTask($repository, "Task {$i}");
    }

    FeatureGroupingAgent::fake([['assignments' => []], ['assignments' => []]]);

    // The sync queue runs the whole job chain inline.
    app(FeatureBackfillService::class)->start($scope);

    expect(PullRequestTask::query()->whereNotNull('task_feature_id')->count())->toBe(0)
        ->and(AiUsageRecord::query()->where('operation', AiOperation::FeatureGrouping->value)->count())->toBe(2)
        ->and(app(FeatureBackfillService::class)->preview($scope))
        ->toMatchArray(['running' => false, 'processed' => 30, 'ungrouped' => 30]);
});

test('command groups a repository', function () {
    fbAiProvider();
    $repository = GitRepository::factory()->create();
    $task = fbTask($repository, 'Add login');

    FeatureGroupingAgent::fake([[
        'assignments' => [
            ['task_id' => $task->id, 'existing_slug' => null, 'new_name' => 'Auth system', 'new_description' => 'Signing in.'],
        ],
    ]]);

    $this->artisan('tasks:group-features', ['--repository' => $repository->id])
        ->assertSuccessful();

    expect($task->fresh()->feature->name)->toBe('Auth system')
        ->and($task->fresh()->feature->scope_key)->toBe("repository:{$repository->id}");
});

test('command rejects a repository inside a project', function () {
    $project = Project::factory()->create();
    $repository = GitRepository::factory()->create(['project_id' => $project->id]);

    $this->artisan('tasks:group-features', ['--repository' => $repository->id])
        ->assertFailed();
});

test('member forbidden', function () {
    $repository = GitRepository::factory()->create();
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->getJson(route('projects.feature-grouping.preview', ['repository_id' => $repository->id]))
        ->assertForbidden();
    $this->actingAs($member)
        ->post(route('projects.feature-grouping.start'), ['repository_id' => $repository->id])
        ->assertForbidden();
});

test('projects page lists grouping status per project and unassigned repository', function () {
    $project = Project::factory()->create();
    $inProject = GitRepository::factory()->create(['project_id' => $project->id]);
    $loose = GitRepository::factory()->create();
    fbTask($inProject, 'One');
    fbTask($loose, 'Two');
    fbTask($loose, 'Three');

    $this->actingAs(User::factory()->manager()->create())
        ->get(route('projects.index'))
        ->assertInertia(fn ($page) => $page
            ->where('projects.0.grouping.ungrouped', 1)
            ->has('unassigned_repositories', 1)
            ->where('unassigned_repositories.0.id', $loose->id)
            ->where('unassigned_repositories.0.grouping.ungrouped', 2)
            ->where('unassigned_repositories.0.grouping.batches', 1));
});

test('a run silent for too long no longer blocks a restart', function () {
    Queue::fake();
    fbAiProvider();
    $repository = GitRepository::factory()->create();
    fbTask($repository, 'Add login');
    $scope = new FeatureScope(null, $repository->id);
    $backfill = app(FeatureBackfillService::class);

    $backfill->start($scope);
    $this->travel(16)->minutes();

    expect($backfill->preview($scope)['running'])->toBeFalse();

    $backfill->start($scope);

    Queue::assertPushedTimes(GroupTaskFeatures::class, 2);
});

test('a job from a superseded run does nothing', function () {
    fbAiProvider();
    $repository = GitRepository::factory()->create();
    fbTask($repository, 'Add login');
    $scope = new FeatureScope(null, $repository->id);
    $backfill = app(FeatureBackfillService::class);

    $oldRun = fbBegin($scope);
    $this->travel(16)->minutes();
    $backfill->start($scope);
    $state = Cache::get($backfill->cacheKey($scope));

    expect($state['run_id'])->not->toBe($oldRun);

    FeatureGroupingAgent::fake([['assignments' => []]]);

    (new GroupTaskFeatures(null, $repository->id, $oldRun))->handle($backfill);

    FeatureGroupingAgent::assertNeverPrompted();
    Queue::assertPushedTimes(GroupTaskFeatures::class, 2);

    expect(Cache::get($backfill->cacheKey($scope)))->toBe($state)
        ->and($backfill->runBatch($scope, $oldRun))->toBe(0);
});

test('command refuses while a ui run is going', function () {
    fbAiProvider();
    $repository = GitRepository::factory()->create();
    fbTask($repository, 'Add login');
    fbBegin(new FeatureScope(null, $repository->id));

    FeatureGroupingAgent::fake([['assignments' => []]]);

    $this->artisan('tasks:group-features', ['--repository' => $repository->id])
        ->assertFailed();

    FeatureGroupingAgent::assertNeverPrompted();
});

test('finishing a run prunes empty ai features in a repository scope', function () {
    fbAiProvider();
    $repository = GitRepository::factory()->create();
    $scope = new FeatureScope(null, $repository->id);
    $resolver = app(FeatureResolver::class);
    $empty = $resolver->resolve($scope, ['new_name' => 'Unused']);
    $used = $resolver->resolve($scope, ['new_name' => 'Used']);
    $manual = $resolver->resolve($scope, ['new_name' => 'Hand made']);
    $manual->forceFill(['source' => AttributionSource::Manual])->save();
    fbTask($repository, 'Add login', ['task_feature_id' => $used->id, 'feature_source' => AttributionSource::Ai]);
    fbTask($repository, 'Open task');

    $backfill = app(FeatureBackfillService::class);
    $backfill->finish($scope, fbBegin($scope));

    expect(TaskFeature::query()->whereKey($empty->id)->exists())->toBeFalse()
        ->and(TaskFeature::query()->whereKey($used->id)->exists())->toBeTrue()
        ->and(TaskFeature::query()->whereKey($manual->id)->exists())->toBeTrue();
});
