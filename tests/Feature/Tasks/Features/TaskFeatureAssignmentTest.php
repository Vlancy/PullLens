<?php

use App\Ai\Agents\PullRequestReviewAgent;
use App\Enums\GIT\AttributionSource;
use App\Enums\GIT\RepositoryTagKind;
use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestTask;
use App\Models\GIT\TaskFeature;
use App\Services\Tasks\Features\FeatureCandidateProvider;
use App\Services\Tasks\Features\FeatureResolver;
use App\Services\Tasks\TaskRecorder;
use App\Support\Tasks\FeatureScope;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a pull request and a review for it.
 *
 * @return array{0: PullRequest, 1: PullRequestReview}
 */
function tfPr(GitRepository $repository, int $number): array
{
    $pullRequest = PullRequest::query()->create([
        'git_repository_id' => $repository->id,
        'provider_pr_id' => $number * 100,
        'number' => $number,
        'title' => "PR {$number}",
        'state' => 'open',
        'author_login' => 'octo',
        'author_name' => 'Octo Cat',
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

    return [$pullRequest, $review];
}

/**
 * Build a model task payload with a feature suggestion.
 *
 * @param  array<string, mixed>|null  $feature
 * @param  array<int, string>  $files
 */
function tfTask(string $key, ?array $feature, array $files = ['app/Foo.php'], ?string $title = null): array
{
    $task = [
        'dedupe_key' => $key,
        'title' => $title ?? ucfirst(str_replace('-', ' ', $key)),
        'type' => 'feature',
        'description' => 'Work.',
        'estimated_hours' => 2,
        'files' => $files,
        'links' => [],
    ];

    if ($feature !== null) {
        $task['feature'] = $feature;
    }

    return $task;
}

test('review assigns tasks to existing feature by slug', function () {
    $repository = GitRepository::factory()->create();
    $feature = app(FeatureResolver::class)->resolve(FeatureScope::forRepository($repository), ['new_name' => 'Auth system']);
    [$pr, $review] = tfPr($repository, 1);

    app(TaskRecorder::class)->record($pr, $review, [
        tfTask('add-login', ['existing_slug' => 'auth-system', 'new_name' => null, 'new_description' => null]),
    ]);

    $task = PullRequestTask::query()->firstOrFail();

    expect($task->task_feature_id)->toBe($feature->id)
        ->and($task->feature_source)->toBe(AttributionSource::Ai)
        ->and(TaskFeature::query()->count())->toBe(1);
});

test('review creates new feature', function () {
    $repository = GitRepository::factory()->create();
    [$pr, $review] = tfPr($repository, 1);

    app(TaskRecorder::class)->record($pr, $review, [
        tfTask('add-search', ['existing_slug' => null, 'new_name' => 'Search', 'new_description' => 'Find things.']),
    ]);

    $task = PullRequestTask::query()->with('feature')->firstOrFail();

    expect($task->feature->name)->toBe('Search')
        ->and($task->feature->description)->toBe('Find things.')
        ->and($task->feature_source)->toBe(AttributionSource::Ai);
});

test('tasks from two repos in one project share a feature', function () {
    $project = Project::factory()->create();
    $a = GitRepository::factory()->create(['project_id' => $project->id]);
    $b = GitRepository::factory()->create(['project_id' => $project->id]);
    [$prA, $reviewA] = tfPr($a, 1);
    [$prB, $reviewB] = tfPr($b, 1);
    $input = ['existing_slug' => null, 'new_name' => 'Billing', 'new_description' => null];

    app(TaskRecorder::class)->record($prA, $reviewA, [tfTask('one', $input)]);
    app(TaskRecorder::class)->record($prB, $reviewB, [tfTask('two', $input)]);

    expect(TaskFeature::query()->count())->toBe(1)
        ->and(PullRequestTask::query()->pluck('task_feature_id')->unique())->toHaveCount(1);
});

test('manual assignment not overwritten on re-review', function () {
    $repository = GitRepository::factory()->create();
    $resolver = app(FeatureResolver::class);
    $scope = FeatureScope::forRepository($repository);
    $manual = $resolver->resolve($scope, ['new_name' => 'Manual pick']);
    [$pr, $review] = tfPr($repository, 1);
    $recorder = app(TaskRecorder::class);

    $recorder->record($pr, $review, [tfTask('add-login', ['new_name' => 'Auth system'])]);
    PullRequestTask::query()->update(['task_feature_id' => $manual->id, 'feature_source' => AttributionSource::Manual]);

    $recorder->record($pr, $review, [tfTask('add-login', ['new_name' => 'Auth system'])]);

    $task = PullRequestTask::query()->firstOrFail();

    expect($task->task_feature_id)->toBe($manual->id)
        ->and($task->feature_source)->toBe(AttributionSource::Manual);
});

test('manual assignment carried to re-keyed task with overlapping files', function () {
    $repository = GitRepository::factory()->create();
    $manual = app(FeatureResolver::class)->resolve(FeatureScope::forRepository($repository), ['new_name' => 'Manual pick']);
    [$pr, $review] = tfPr($repository, 1);
    $recorder = app(TaskRecorder::class);

    $recorder->record($pr, $review, [tfTask('old-key', ['new_name' => 'Auth'], ['app/A.php', 'app/B.php'])]);
    PullRequestTask::query()->update(['task_feature_id' => $manual->id, 'feature_source' => AttributionSource::Manual]);

    $recorder->record($pr, $review, [
        tfTask('unrelated', ['new_name' => 'Auth'], ['app/Z.php'], 'Something else entirely'),
        tfTask('new-key', ['new_name' => 'Auth'], ['app/B.php', 'app/C.php']),
    ]);

    $tasks = PullRequestTask::query()->get()->keyBy('dedupe_key');

    expect($tasks)->toHaveCount(2)
        ->and($tasks['new-key']->task_feature_id)->toBe($manual->id)
        ->and($tasks['new-key']->feature_source)->toBe(AttributionSource::Manual)
        ->and($tasks['unrelated']->feature_source)->toBe(AttributionSource::Ai);
});

test('prompt includes project and tags context', function () {
    $project = Project::factory()->create(['name' => 'Acme Platform', 'description' => 'The Acme product']);
    $repository = GitRepository::factory()->create(['project_id' => $project->id]);
    $repository->tags()->create(['name' => 'laravel', 'kind' => RepositoryTagKind::cases()[0], 'source' => AttributionSource::Manual]);
    $feature = app(FeatureResolver::class)->resolve(FeatureScope::forRepository($repository), ['new_name' => 'Auth system']);
    [$pr, $review] = tfPr($repository, 1);
    app(TaskRecorder::class)->record($pr, $review, [tfTask('add-login', ['existing_slug' => $feature->slug])]);

    $context = app(FeatureCandidateProvider::class)->forRepository($repository);
    $prompt = (new PullRequestReviewAgent)->buildPrompt('diff', [], null, [], [], $context);

    expect($context['features'][0])->toMatchArray(['slug' => 'auth-system', 'task_count' => 1])
        ->and($context['project']['name'])->toBe('Acme Platform')
        ->and($prompt)->toContain('FEATURES')
        ->and($prompt)->toContain('auth-system')
        ->and($prompt)->toContain('laravel')
        ->and($prompt)->toContain('Acme Platform');
});

test('missing feature key leaves task ungrouped', function () {
    $repository = GitRepository::factory()->create();
    [$pr, $review] = tfPr($repository, 1);

    app(TaskRecorder::class)->record($pr, $review, [tfTask('add-login', null)]);

    $task = PullRequestTask::query()->firstOrFail();

    expect($task->task_feature_id)->toBeNull()
        ->and($task->feature_source)->toBeNull()
        ->and(TaskFeature::query()->count())->toBe(0);
});

test('resolver recovers when a concurrent insert wins the race inside an outer transaction', function () {
    $repository = GitRepository::factory()->create();
    $scope = FeatureScope::forRepository($repository);
    $fired = false;

    // Simulates another worker committing the same slug right after our lookup missed
    // and before our insert (the listener fires after the lookup SELECT, outside the
    // savepoint the resolver opens for its insert).
    DB::listen(function (QueryExecuted $query) use (&$fired): void {
        if ($fired || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'task_features')) {
            return;
        }

        $fired = true;
        DB::table('task_features')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => null,
            'git_repository_id' => null,
            'scope_key' => $query->bindings[0],
            'name' => 'Raced',
            'slug' => 'search',
            'source' => 'ai',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $resolved = DB::transaction(function () use ($scope): ?TaskFeature {
        $feature = app(FeatureResolver::class)->resolve($scope, ['new_name' => 'Search']);

        // The outer transaction must still be usable afterwards.
        expect(TaskFeature::query()->count())->toBe(1);

        return $feature;
    });

    expect($resolved->name)->toBe('Raced')
        ->and(TaskFeature::query()->count())->toBe(1);
});

test('feature with no tasks sorts after one with tasks', function () {
    $repository = GitRepository::factory()->create();
    $resolver = app(FeatureResolver::class);
    $scope = FeatureScope::forRepository($repository);
    $resolver->resolve($scope, ['new_name' => 'Aardvark empty']);
    [$pr, $review] = tfPr($repository, 1);
    app(TaskRecorder::class)->record($pr, $review, [tfTask('x', ['new_name' => 'Zebra busy'])]);

    $slugs = array_column(app(FeatureCandidateProvider::class)->forRepository($repository)['features'], 'slug');

    expect($slugs)->toBe(['zebra-busy', 'aardvark-empty']);
});

test('title similarity carries a manual assignment when no files overlap', function () {
    $repository = GitRepository::factory()->create();
    $manual = app(FeatureResolver::class)->resolve(FeatureScope::forRepository($repository), ['new_name' => 'Manual pick']);
    [$pr, $review] = tfPr($repository, 1);
    $recorder = app(TaskRecorder::class);

    $recorder->record($pr, $review, [tfTask('old', ['new_name' => 'Auth'], ['a.php'], 'Added login form validation')]);
    PullRequestTask::query()->update(['task_feature_id' => $manual->id, 'feature_source' => AttributionSource::Manual]);

    $recorder->record($pr, $review, [
        tfTask('new', ['new_name' => 'Auth'], ['b.php'], 'Added login form validations'),
        tfTask('other', ['new_name' => 'Auth'], ['c.php'], 'Zzz qqq'),
    ]);

    $tasks = PullRequestTask::query()->get()->keyBy('dedupe_key');

    expect($tasks['new']->task_feature_id)->toBe($manual->id)
        ->and($tasks['other']->feature_source)->toBe(AttributionSource::Ai);
});

test('dissimilar title with no file overlap does not carry', function () {
    $repository = GitRepository::factory()->create();
    $manual = app(FeatureResolver::class)->resolve(FeatureScope::forRepository($repository), ['new_name' => 'Manual pick']);
    [$pr, $review] = tfPr($repository, 1);
    $recorder = app(TaskRecorder::class);

    $recorder->record($pr, $review, [tfTask('old', ['new_name' => 'Auth'], ['a.php'], 'Added login form validation')]);
    PullRequestTask::query()->update(['task_feature_id' => $manual->id, 'feature_source' => AttributionSource::Manual]);

    $recorder->record($pr, $review, [tfTask('new', ['new_name' => 'Auth'], ['b.php'], 'Zzz qqq')]);

    $task = PullRequestTask::query()->firstOrFail();

    expect($task->feature_source)->toBe(AttributionSource::Ai)
        ->and($task->task_feature_id)->not->toBe($manual->id);
});
