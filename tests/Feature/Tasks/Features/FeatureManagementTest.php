<?php

use App\Enums\GIT\AttributionSource;
use App\Enums\Users\UserPermission;
use App\Enums\Users\UserRole;
use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestTask;
use App\Models\GIT\TaskFeature;
use App\Models\Users\User;
use App\Services\Projects\ProjectService;
use App\Services\Tasks\Features\FeatureManager;
use App\Services\Tasks\Features\FeatureResolver;
use App\Services\Tasks\TaskRecorder;
use App\Support\Tasks\FeatureScope;

/**
 * Create a pull request with a review and record one task under the given AI feature.
 *
 * @return array{0: PullRequest, 1: PullRequestReview}
 */
function fmPr(GitRepository $repository, int $number): array
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
 * Build a task payload naming a feature.
 *
 * @param  array<string, mixed>  $feature
 * @return array<string, mixed>
 */
function fmPayload(string $key, array $feature): array
{
    return [
        'dedupe_key' => $key,
        'title' => ucfirst(str_replace('-', ' ', $key)),
        'type' => 'feature',
        'description' => 'Work.',
        'estimated_hours' => 2,
        'files' => ["app/{$key}.php"],
        'links' => [],
        'feature' => $feature,
    ];
}

/**
 * Record one task in a fresh pull request, grouped under an AI feature by name.
 */
function fmTask(GitRepository $repository, int $number, string $key, string $featureName): PullRequestTask
{
    [$pr, $review] = fmPr($repository, $number);
    app(TaskRecorder::class)->record($pr, $review, [fmPayload($key, ['new_name' => $featureName])]);

    return PullRequestTask::query()->where('dedupe_key', $key)->firstOrFail();
}

test('rename marks manual and later review keeps name', function () {
    $repository = GitRepository::factory()->create();
    $task = fmTask($repository, 1, 'add-login', 'Auth system');
    $feature = $task->feature;

    $this->actingAs(User::factory()->manager()->create())
        ->put(route('tasks.features.update', $feature), ['name' => 'Sign-in', 'description' => 'Logging people in.'])
        ->assertRedirect()
        ->assertSessionHas('status');

    $feature->refresh();
    expect($feature->name)->toBe('Sign-in')
        ->and($feature->description)->toBe('Logging people in.')
        ->and($feature->slug)->toBe('auth-system')
        ->and($feature->source)->toBe(AttributionSource::Manual);

    [$pr, $review] = fmPr($repository, 2);
    app(TaskRecorder::class)->record($pr, $review, [
        fmPayload('add-logout', ['existing_slug' => 'auth-system', 'new_name' => 'Auth system']),
    ]);

    expect(TaskFeature::query()->count())->toBe(1)
        ->and($feature->fresh()->name)->toBe('Sign-in')
        ->and(PullRequestTask::query()->where('dedupe_key', 'add-logout')->value('task_feature_id'))->toBe($feature->id);
});

test('merge moves tasks and future slug references resolve to target', function () {
    $repository = GitRepository::factory()->create();
    $a = fmTask($repository, 1, 'one', 'Login');
    $b = fmTask($repository, 2, 'two', 'Auth');
    PullRequestTask::query()->whereKey($a->id)->update(['feature_source' => AttributionSource::Manual->value]);
    $from = $a->feature;
    $into = $b->feature;

    $this->actingAs(User::factory()->manager()->create())
        ->post(route('tasks.features.merge', $from), ['into_id' => $into->id])
        ->assertRedirect()
        ->assertSessionHas('status');

    $a->refresh();
    expect($a->task_feature_id)->toBe($into->id)
        ->and($a->feature_source)->toBe(AttributionSource::Manual)
        ->and($b->fresh()->feature_source)->toBe(AttributionSource::Ai)
        ->and($from->fresh()->merged_into_id)->toBe($into->id);

    $resolved = app(FeatureResolver::class)->resolve(FeatureScope::forRepository($repository), ['existing_slug' => 'login']);
    expect($resolved->id)->toBe($into->id);
});

test('merge into self or other scope is 422', function () {
    $repository = GitRepository::factory()->create();
    $other = GitRepository::factory()->create();
    $feature = fmTask($repository, 1, 'one', 'Login')->feature;
    $sibling = fmTask($repository, 2, 'two', 'Auth')->feature;
    $foreign = fmTask($other, 3, 'three', 'Billing')->feature;

    $this->actingAs(User::factory()->manager()->create());

    $this->post(route('tasks.features.merge', $feature), ['into_id' => $feature->id])
        ->assertSessionHasErrors('into_id');
    $this->post(route('tasks.features.merge', $feature), ['into_id' => $foreign->id])
        ->assertSessionHasErrors('into_id');

    // A target whose merge chain leads back to the source is the same as merging into self.
    $sibling->forceFill(['merged_into_id' => $feature->id])->save();
    $this->post(route('tasks.features.merge', $feature), ['into_id' => $sibling->id])
        ->assertSessionHasErrors('into_id');

    expect($feature->fresh()->merged_into_id)->toBeNull();
});

test('move task marks manual', function () {
    $repository = GitRepository::factory()->create();
    $task = fmTask($repository, 1, 'one', 'Login');
    $target = fmTask($repository, 2, 'two', 'Auth')->feature;
    $foreign = fmTask(GitRepository::factory()->create(), 3, 'three', 'Billing')->feature;

    $this->actingAs(User::factory()->manager()->create());

    $this->put(route('tasks.feature.update', $task), ['feature_id' => $target->id])
        ->assertRedirect()
        ->assertSessionHas('status');

    $task->refresh();
    expect($task->task_feature_id)->toBe($target->id)
        ->and($task->feature_source)->toBe(AttributionSource::Manual);

    $this->put(route('tasks.feature.update', $task), ['new_name' => 'Brand new'])->assertRedirect();

    $task->refresh();
    expect($task->feature->name)->toBe('Brand new')
        ->and($task->feature->source)->toBe(AttributionSource::Manual)
        ->and($task->feature->scope_key)->toBe(FeatureScope::forRepository($repository)->key())
        ->and($task->feature_source)->toBe(AttributionSource::Manual);

    $this->put(route('tasks.feature.update', $task), ['feature_id' => $foreign->id])
        ->assertSessionHasErrors('feature_id');

    $this->put(route('tasks.feature.update', $task), ['feature_id' => null])->assertRedirect();
    expect($task->fresh()->task_feature_id)->toBeNull()
        ->and($task->fresh()->feature_source)->toBe(AttributionSource::Manual);
});

test('repo joining project promotes features and merges same slug', function () {
    $project = Project::factory()->create();
    $member = GitRepository::factory()->create(['project_id' => $project->id]);
    $joining = GitRepository::factory()->create();
    $projectAuth = fmTask($member, 1, 'p-auth', 'Auth')->feature;
    $repoAuthTask = fmTask($joining, 2, 'r-auth', 'Auth');
    $repoBillingTask = fmTask($joining, 3, 'r-billing', 'Billing');
    $repoAuth = $repoAuthTask->feature;

    app(ProjectService::class)->assignRepositories($project, [$member->id, $joining->id]);

    $billing = $repoBillingTask->fresh()->feature;
    expect($repoAuthTask->fresh()->task_feature_id)->toBe($projectAuth->id)
        ->and(TaskFeature::query()->find($repoAuth->id))->toBeNull()
        ->and($billing->project_id)->toBe($project->id)
        ->and($billing->git_repository_id)->toBeNull()
        ->and($billing->scope_key)->toBe("project:{$project->id}")
        ->and(TaskFeature::query()->where('scope_key', "repository:{$joining->id}")->count())->toBe(0);

    // The joined repository's next review resolves the promoted feature by slug.
    $resolved = app(FeatureResolver::class)->resolve(FeatureScope::forRepository($joining->fresh()), ['existing_slug' => 'billing']);
    expect($resolved->id)->toBe($billing->id);
});

test('manual name wins on promote', function () {
    $project = Project::factory()->create();
    $member = GitRepository::factory()->create(['project_id' => $project->id]);
    $joining = GitRepository::factory()->create();
    $projectAuth = fmTask($member, 1, 'p-auth', 'Auth')->feature;
    $repoAuth = fmTask($joining, 2, 'r-auth', 'Auth')->feature;
    app(FeatureManager::class)->rename($repoAuth, 'Authentication', null);

    app(ProjectService::class)->assignRepositories($project, [$member->id, $joining->id]);

    $projectAuth->refresh();
    expect($projectAuth->name)->toBe('Authentication')
        ->and($projectAuth->source)->toBe(AttributionSource::Manual)
        ->and(PullRequestTask::query()->pluck('task_feature_id')->unique()->all())->toBe([$projectAuth->id]);
});

test('repo leaving project keeps feature names under repo', function () {
    $project = Project::factory()->create();
    $stays = GitRepository::factory()->create(['project_id' => $project->id]);
    $leaves = GitRepository::factory()->create(['project_id' => $project->id]);
    $shared = fmTask($stays, 1, 's-auth', 'Auth')->feature;
    $leavingTask = fmTask($leaves, 2, 'l-auth', 'Auth');
    app(FeatureManager::class)->rename($shared, 'Sign-in', null);
    PullRequestTask::query()->whereKey($leavingTask->id)->update(['feature_source' => AttributionSource::Manual->value]);

    app(ProjectService::class)->assignRepositories($project, [$stays->id]);

    $leavingTask->refresh();
    $feature = $leavingTask->feature;
    expect($feature->id)->not->toBe($shared->id)
        ->and($feature->name)->toBe('Sign-in')
        ->and($feature->slug)->toBe('auth')
        ->and($feature->source)->toBe(AttributionSource::Manual)
        ->and($feature->project_id)->toBeNull()
        ->and($feature->git_repository_id)->toBe($leaves->id)
        ->and($feature->scope_key)->toBe("repository:{$leaves->id}")
        ->and($leavingTask->feature_source)->toBe(AttributionSource::Manual)
        ->and(PullRequestTask::query()->where('dedupe_key', 's-auth')->value('task_feature_id'))->toBe($shared->id);
});

test('deleting project keeps tasks grouped', function () {
    $project = Project::factory()->create();
    [$a, $b] = GitRepository::factory()->count(2)->create(['project_id' => $project->id]);
    $taskA = fmTask($a, 1, 'a-auth', 'Auth');
    $taskB = fmTask($b, 2, 'b-auth', 'Auth');
    expect($taskA->task_feature_id)->toBe($taskB->task_feature_id);

    app(ProjectService::class)->delete($project);

    $featureA = $taskA->fresh()->feature;
    $featureB = $taskB->fresh()->feature;
    expect($featureA)->not->toBeNull()
        ->and($featureB)->not->toBeNull()
        ->and($featureA->name)->toBe('Auth')
        ->and($featureA->git_repository_id)->toBe($a->id)
        ->and($featureB->git_repository_id)->toBe($b->id)
        ->and(TaskFeature::query()->whereNotNull('project_id')->count())->toBe(0);
});

test('member gets 403', function () {
    $repository = GitRepository::factory()->create();
    $task = fmTask($repository, 1, 'one', 'Login');
    $feature = $task->feature;

    $this->actingAs(User::factory()->member()->create());

    $this->put(route('tasks.features.update', $feature), ['name' => 'X'])->assertForbidden();
    $this->post(route('tasks.features.merge', $feature), ['into_id' => $feature->id])->assertForbidden();
    $this->put(route('tasks.feature.update', $task), ['feature_id' => null])->assertForbidden();
});

test('features and tasks outside the viewer scope are 404', function () {
    $project = Project::factory()->create();
    $granted = GitRepository::factory()->create(['project_id' => $project->id]);
    $sibling = GitRepository::factory()->create(['project_id' => $project->id]);
    $hidden = GitRepository::factory()->create();
    $projectFeature = fmTask($sibling, 1, 'one', 'Login')->feature;
    $hiddenTask = fmTask($hidden, 2, 'two', 'Billing');

    $user = User::factory()->withRole(UserRole::Contributor)->create();
    $user->givePermissionTo(UserPermission::ManageRepositories->value);
    $user->repositories()->attach($granted->id, ['access_level' => 'manage']);
    $this->actingAs($user);

    $this->put(route('tasks.features.update', $hiddenTask->feature), ['name' => 'X'])->assertNotFound();
    $this->put(route('tasks.feature.update', $hiddenTask), ['feature_id' => null])->assertNotFound();
    $this->post(route('tasks.features.merge', $hiddenTask->feature), ['into_id' => $hiddenTask->feature->id])->assertNotFound();

    // A project feature is visible through any visible repository of the project.
    $this->put(route('tasks.features.update', $projectFeature), ['name' => 'Sign-in'])->assertRedirect();
    expect($projectFeature->fresh()->name)->toBe('Sign-in');
});

test('pruneEmpty removes empty ai features of the given project only', function () {
    $project = Project::factory()->create();
    $repository = GitRepository::factory()->create(['project_id' => $project->id]);
    $scope = FeatureScope::forRepository($repository);
    $resolver = app(FeatureResolver::class);
    $busy = fmTask($repository, 1, 'one', 'Busy')->feature;
    $emptyAi = $resolver->resolve($scope, ['new_name' => 'Empty ai']);
    $emptyManual = $resolver->resolve($scope, ['new_name' => 'Empty manual']);
    $emptyManual->forceFill(['source' => AttributionSource::Manual])->save();
    $target = $resolver->resolve($scope, ['new_name' => 'Merge target']);
    $merged = $resolver->resolve($scope, ['new_name' => 'Merged away']);
    $merged->forceFill(['merged_into_id' => $target->id])->save();
    $elsewhere = $resolver->resolve(FeatureScope::forRepository(GitRepository::factory()->create()), ['new_name' => 'Empty elsewhere']);
    $otherProject = Project::factory()->create();
    $otherEmpty = $resolver->resolve(new FeatureScope($otherProject->id, null), ['new_name' => 'Other project empty']);

    expect(app(FeatureManager::class)->pruneEmpty())->toBe(0);

    $pruned = app(FeatureManager::class)->pruneEmpty($project->id);

    expect($pruned)->toBe(1)
        ->and(TaskFeature::query()->find($emptyAi->id))->toBeNull()
        ->and(TaskFeature::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$busy->id, $emptyManual->id, $target->id, $merged->id, $elsewhere->id, $otherEmpty->id])->sort()->values()->all());
});

test('deleting a repository prunes features left empty', function () {
    $project = Project::factory()->create();
    [$a, $b] = GitRepository::factory()->count(2)->create(['project_id' => $project->id]);
    $onlyA = fmTask($a, 1, 'a-one', 'Only a')->feature;
    $shared = fmTask($a, 2, 'a-two', 'Shared')->feature;
    fmTask($b, 3, 'b-two', 'Shared');

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('integrations.repositories.destroy', $a))
        ->assertRedirect();

    expect(TaskFeature::query()->find($onlyA->id))->toBeNull()
        ->and(TaskFeature::query()->find($shared->id))->not->toBeNull();
});

test('admin can rename a project feature whose project has no repositories', function () {
    $project = Project::factory()->create();
    $feature = app(FeatureResolver::class)->resolve(new FeatureScope($project->id, null), ['new_name' => 'Orphan']);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('tasks.features.update', $feature), ['name' => 'Adopted'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($feature->fresh()->name)->toBe('Adopted');
});

test('rename to a name another feature in the scope already uses is rejected', function () {
    $repository = GitRepository::factory()->create();
    $feature = fmTask($repository, 1, 'one', 'Login')->feature;
    $active = fmTask($repository, 2, 'two', 'Auth')->feature;
    $merged = fmTask($repository, 3, 'three', 'Old billing')->feature;
    $merged->forceFill(['merged_into_id' => $active->id])->save();
    fmTask(GitRepository::factory()->create(), 4, 'four', 'Payments');

    $this->actingAs(User::factory()->manager()->create());

    $this->put(route('tasks.features.update', $feature), ['name' => 'AUTH'])
        ->assertSessionHasErrors('name');
    $this->put(route('tasks.features.update', $feature), ['name' => 'Old Billing'])
        ->assertSessionHasErrors('name');
    expect($feature->fresh()->name)->toBe('Login');

    // Its own slug and a slug used only in another scope are fine.
    $this->put(route('tasks.features.update', $feature), ['name' => 'LOGIN'])->assertSessionHasNoErrors();
    $this->put(route('tasks.features.update', $feature), ['name' => 'Payments'])->assertSessionHasNoErrors();
    expect($feature->fresh()->name)->toBe('Payments');
});
