<?php

use App\Enums\GIT\AttributionSource;
use App\Enums\Users\UserPermission;
use App\Enums\Users\UserRole;
use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestTask;
use App\Models\GIT\PullRequestTaskLink;
use App\Models\GIT\TaskFeature;
use App\Models\Users\User;
use App\Support\Tasks\FeatureScope;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a feature in the scope of the given repository.
 */
function tbfFeature(GitRepository $repository, string $name): TaskFeature
{
    $scope = FeatureScope::forRepository($repository);

    return TaskFeature::query()->create([
        'project_id' => $scope->projectId,
        'git_repository_id' => $scope->repositoryId,
        'scope_key' => $scope->key(),
        'name' => $name,
        'slug' => Str::slug($name),
        'source' => AttributionSource::Ai,
    ]);
}

/**
 * Create a task, with its pull request and review, in the given repository.
 *
 * @param  array<string, mixed>  $attributes
 */
function tbfTask(GitRepository $repository, ?TaskFeature $feature, array $attributes = []): PullRequestTask
{
    static $number = 0;
    $number++;

    $pullRequest = PullRequest::query()->create([
        'git_repository_id' => $repository->id,
        'provider_pr_id' => $number * 100,
        'number' => $number,
        'title' => "PR {$number}",
        'state' => 'merged',
        'author_login' => 'octo',
        'author_name' => 'Octo Cat',
        'source_branch' => "feature/{$number}",
        'target_branch' => 'main',
        'opened_at' => now()->subDays(3),
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
        'title' => "Task {$number}",
        'type' => 'feature',
        'status' => 'delivered',
        'author_login' => 'octo',
        'author_name' => 'Octo Cat',
        'delivered_at' => now()->subDay(),
        'task_feature_id' => $feature?->id,
        'feature_source' => $feature === null ? null : AttributionSource::Ai,
        ...$attributes,
    ]);
}

test('features view lists rollup counts', function () {
    $repository = GitRepository::factory()->create(['full_name' => 'acme/web']);
    $login = tbfFeature($repository, 'Login');
    $billing = tbfFeature($repository, 'Billing');

    tbfTask($repository, $login, ['author_login' => 'ann', 'author_name' => 'Ann']);
    tbfTask($repository, $login, ['status' => 'reworked', 'rework_count' => 2, 'author_login' => 'ann', 'author_name' => 'Ann']);
    tbfTask($repository, $login, ['status' => 'reverted', 'author_login' => 'bob', 'author_name' => 'Bob']);
    tbfTask($repository, $login, ['status' => 'in_progress', 'delivered_at' => null, 'author_login' => 'bob', 'author_name' => 'Bob']);
    tbfTask($repository, $login, ['author_login' => 'ann', 'author_name' => 'Ann']);
    tbfTask($repository, $billing, ['delivered_at' => now()->subDays(20)]);

    $this->actingAs(User::factory()->manager()->create())
        ->get('/tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tasks/index')
            ->where('filters.view', 'features')
            ->where('can_manage_features', true)
            ->has('features.data', 2)
            ->where('features.data.0.id', $login->id)
            ->where('features.data.0.name', 'Login')
            ->where('features.data.0.scope_label', 'acme/web')
            ->where('features.data.0.scope_key', "repository:{$repository->id}")
            ->where('features.data.0.source', 'ai')
            ->where('features.data.0.tasks', 5)
            ->where('features.data.0.shipped', 4)
            ->where('features.data.0.came_back', 2)
            ->where('features.data.0.contributors.0.login', 'ann')
            ->where('features.data.0.contributors.1.login', 'bob')
            ->has('features.data.0.contributors', 2)
            ->where('features.data.1.id', $billing->id)
            ->where('features.data.1.tasks', 1)
            ->where('features.data.1.came_back', 0)
            ->has('features.data.1.last_activity_at')
            ->where('features.pagination.total', 2)
            ->has('feature_options', 2)
            ->etc());
});

test('filters narrow counts', function () {
    $repository = GitRepository::factory()->create();
    $login = tbfFeature($repository, 'Login');
    $billing = tbfFeature($repository, 'Billing');

    tbfTask($repository, $login, ['type' => 'bugfix']);
    tbfTask($repository, $login, ['type' => 'feature']);
    tbfTask($repository, $login, ['type' => 'bugfix', 'status' => 'reworked']);
    tbfTask($repository, $billing, ['type' => 'feature']);

    $this->actingAs(User::factory()->manager()->create())
        ->get('/tasks?type=bugfix')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('features.data', 1)
            ->where('features.data.0.id', $login->id)
            ->where('features.data.0.tasks', 2)
            ->where('features.data.0.came_back', 1)
            ->etc());
});

test('ungrouped row appears last', function () {
    $repository = GitRepository::factory()->create();
    $old = tbfFeature($repository, 'Old work');
    tbfTask($repository, $old, ['delivered_at' => now()->subDays(30)]);
    tbfTask($repository, null, ['delivered_at' => now()]);
    tbfTask($repository, null, ['status' => 'reworked', 'delivered_at' => now()]);

    $this->actingAs(User::factory()->manager()->create());

    $this->get('/tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('features.data', 2)
            ->where('features.data.0.id', $old->id)
            ->where('features.data.1.id', 'ungrouped')
            ->where('features.data.1.name', 'Ungrouped')
            ->where('features.data.1.tasks', 2)
            ->where('features.data.1.came_back', 1)
            ->etc());

    // Spread over two pages of features, the ungrouped row is only on the last one.
    foreach (range(1, 25) as $index) {
        tbfTask($repository, tbfFeature($repository, "Feature {$index}"));
    }

    $this->get('/tasks')
        ->assertInertia(fn (Assert $page) => $page
            ->has('features.data', 25)
            ->where('features.pagination.last_page', 2)
            ->etc());

    $this->get('/tasks?features_page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('features.data', 2)
            ->where('features.data.0.id', $old->id)
            ->where('features.data.1.id', 'ungrouped')
            ->etc());
});

test('contributor sees only granted repos in a project feature', function () {
    $project = Project::factory()->create(['name' => 'Storefront']);
    $granted = GitRepository::factory()->create(['project_id' => $project->id]);
    $sibling = GitRepository::factory()->create(['project_id' => $project->id]);
    $hidden = GitRepository::factory()->create();
    $shared = tbfFeature($granted, 'Checkout');
    $foreign = tbfFeature($hidden, 'Secret');

    tbfTask($granted, $shared);
    tbfTask($sibling, $shared);
    tbfTask($sibling, $shared);
    tbfTask($hidden, $foreign);

    $user = User::factory()->withRole(UserRole::Contributor)->create();
    $user->repositories()->attach($granted->id, ['access_level' => 'view']);

    $this->actingAs($user)
        ->get('/tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_manage_features', false)
            ->has('features.data', 1)
            ->where('features.data.0.id', $shared->id)
            ->where('features.data.0.scope_label', 'Storefront')
            ->where('features.data.0.tasks', 1)
            ->where('feature_options', [])
            ->etc());
});

test('feature options only cover visible scopes', function () {
    $project = Project::factory()->create(['name' => 'Storefront']);
    $granted = GitRepository::factory()->create(['project_id' => $project->id]);
    $hidden = GitRepository::factory()->create();
    $shared = tbfFeature($granted, 'Checkout');
    tbfFeature($hidden, 'Secret');

    $user = User::factory()->withRole(UserRole::Contributor)->create();
    $user->givePermissionTo(UserPermission::ManageRepositories->value);
    $user->repositories()->attach($granted->id, ['access_level' => 'manage']);

    $this->actingAs($user)
        ->get('/tasks')
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_manage_features', true)
            ->has('feature_options', 1)
            ->where('feature_options.0.id', $shared->id)
            ->where('feature_options.0.scope_key', "project:{$project->id}")
            ->where('feature_options.0.scope_label', 'Storefront')
            ->etc());
});

test('feature filter returns its tasks', function () {
    $repository = GitRepository::factory()->create();
    $login = tbfFeature($repository, 'Login');
    $billing = tbfFeature($repository, 'Billing');
    $inLogin = tbfTask($repository, $login);
    tbfTask($repository, $billing);
    $loose = tbfTask($repository, null);

    $this->actingAs(User::factory()->manager()->create());

    $this->get("/tasks?feature={$login->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 1)
            ->where('tasks.0.id', $inLogin->id)
            ->where('tasks.0.feature.id', $login->id)
            ->where('tasks.0.feature.name', 'Login')
            ->where('tasks.0.feature_source', 'ai')
            ->where('tasks.0.scope_key', "repository:{$repository->id}")
            ->where('pagination.feature', $login->id)
            // The rollup itself is not narrowed by the expanded feature.
            ->has('features.data', 3)
            ->etc());

    $this->get('/tasks?feature=ungrouped')
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 1)
            ->where('tasks.0.id', $loose->id)
            ->where('tasks.0.feature', null)
            ->where('pagination.feature', 'ungrouped')
            ->etc());

    // The board's "linked tasks" pivot sends related_to without the feature, so a
    // fix grouped elsewhere (here: ungrouped) is listed alongside the original.
    PullRequestTaskLink::query()->create([
        'task_id' => $loose->id,
        'related_task_id' => $inLogin->id,
        'relation' => 'fixes',
        'reason' => 'r',
        'confidence' => 0.9,
        'source' => 'ai',
    ]);

    $this->get("/tasks?view=list&related_to={$inLogin->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks', 1)
            ->where('tasks.0.id', $loose->id)
            ->where('pagination.feature', null)
            ->etc());

    // Kept, the feature filter would hide it - which is why the pivot drops it.
    $this->get("/tasks?view=list&related_to={$inLogin->id}&feature={$login->id}")
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 0)->etc());

    $this->get('/tasks?feature=nonsense')->assertSessionHasErrors('feature');
    $this->get('/tasks?view=grid')->assertSessionHasErrors('view');
});

test('list view still works', function () {
    $repository = GitRepository::factory()->create();
    tbfTask($repository, tbfFeature($repository, 'Login'));
    tbfTask($repository, null);

    $this->actingAs(User::factory()->member()->create())
        ->get('/tasks?view=list')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.view', 'list')
            ->missing('features')
            ->where('can_manage_features', false)
            ->has('tasks', 2)
            ->where('summary.total', 2)
            ->where('pagination.total', 2)
            ->etc());
});
