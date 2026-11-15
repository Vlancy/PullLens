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
use Illuminate\Support\Facades\DB;
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

test('project filter narrows both views', function () {
    $storefront = Project::factory()->create(['name' => 'Storefront']);
    $web = GitRepository::factory()->create(['project_id' => $storefront->id, 'full_name' => 'acme/web']);
    $api = GitRepository::factory()->create(['project_id' => $storefront->id, 'full_name' => 'acme/api']);
    $loose = GitRepository::factory()->create(['full_name' => 'acme/tools']);
    $checkout = tbfFeature($web, 'Checkout');
    $scripts = tbfFeature($loose, 'Scripts');

    tbfTask($web, $checkout);
    tbfTask($api, $checkout, ['status' => 'reworked']);
    tbfTask($loose, $scripts);
    tbfTask($loose, null);

    $this->actingAs(User::factory()->manager()->create());

    $this->get("/tasks?view=list&project={$storefront->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.project', $storefront->id)
            ->has('tasks', 2)
            ->where('summary.total', 2)
            ->where('pagination.total', 2)
            ->etc());

    $this->get("/tasks?project={$storefront->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('features.data', 1)
            ->where('features.data.0.id', $checkout->id)
            ->where('features.data.0.tasks', 2)
            ->where('features.data.0.came_back', 1)
            ->where('summary.total', 2)
            ->has('feature_groups', 1)
            ->where('feature_groups.0.key', "project:{$storefront->id}")
            ->etc());

    $this->get('/tasks?view=list&project=none')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.project', 'none')
            ->has('tasks', 2)
            ->etc());

    $this->get('/tasks?project=none')
        ->assertInertia(fn (Assert $page) => $page
            ->has('features.data', 2)
            ->where('features.data.0.id', $scripts->id)
            ->where('features.data.1.id', 'ungrouped')
            ->has('feature_groups', 1)
            ->where('feature_groups.0.key', "repository:{$loose->id}")
            ->etc());
});

test('project filter rejects unknown values', function () {
    $this->actingAs(User::factory()->manager()->create());

    $this->get('/tasks?project=nonsense')->assertSessionHasErrors('project');
    $this->get('/tasks?project='.Str::uuid())->assertSessionHasErrors('project');
});

test('projects prop only lists projects with visible repositories', function () {
    $beta = Project::factory()->create(['name' => 'Beta']);
    $alpha = Project::factory()->create(['name' => 'Alpha']);
    $hiddenProject = Project::factory()->create(['name' => 'Hidden']);
    Project::factory()->create(['name' => 'Empty']);
    $granted = GitRepository::factory()->create(['project_id' => $beta->id]);
    $grantedToo = GitRepository::factory()->create(['project_id' => $alpha->id]);
    GitRepository::factory()->create(['project_id' => $hiddenProject->id]);
    GitRepository::factory()->create();

    $user = User::factory()->withRole(UserRole::Contributor)->create();
    $user->repositories()->attach([
        $granted->id => ['access_level' => 'view'],
        $grantedToo->id => ['access_level' => 'view'],
    ]);

    $this->actingAs($user)
        ->get('/tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('projects', [
                ['id' => $alpha->id, 'name' => 'Alpha'],
                ['id' => $beta->id, 'name' => 'Beta'],
            ])
            ->where('has_repositories_without_project', false)
            ->etc());

    $this->actingAs(User::factory()->manager()->create())
        ->get('/tasks')
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects', 3)
            ->where('projects.0.name', 'Alpha')
            ->where('projects.2.name', 'Hidden')
            ->where('has_repositories_without_project', true)
            ->etc());
});

test('feature rows are ordered by group with group totals', function () {
    $zeta = Project::factory()->create(['name' => 'Zeta']);
    $alpha = Project::factory()->create(['name' => 'Alpha']);
    $zetaRepo = GitRepository::factory()->create(['project_id' => $zeta->id]);
    $alphaWeb = GitRepository::factory()->create(['project_id' => $alpha->id]);
    $alphaApi = GitRepository::factory()->create(['project_id' => $alpha->id]);
    $tools = GitRepository::factory()->create(['full_name' => 'acme/tools']);
    $apps = GitRepository::factory()->create(['full_name' => 'acme/apps']);

    $search = tbfFeature($zetaRepo, 'Search');
    $older = tbfFeature($alphaWeb, 'Older');
    $newer = tbfFeature($alphaApi, 'Newer');
    $scripts = tbfFeature($tools, 'Scripts');
    $builds = tbfFeature($apps, 'Builds');

    tbfTask($zetaRepo, $search, ['delivered_at' => now()]);
    tbfTask($alphaWeb, $older, ['delivered_at' => now()->subDays(10)]);
    tbfTask($alphaApi, $older, ['delivered_at' => now()->subDays(9), 'status' => 'reverted', 'type' => 'bugfix']);
    tbfTask($alphaApi, $newer, ['delivered_at' => now()->subDays(2)]);
    tbfTask($alphaWeb, $newer, ['status' => 'in_progress', 'delivered_at' => null]);
    tbfTask($tools, $scripts);
    tbfTask($apps, $builds);
    tbfTask($apps, null);

    $this->actingAs(User::factory()->manager()->create());

    $this->get('/tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('features.data', 6)
            ->where('features.data.0.id', $newer->id)
            ->where('features.data.0.group_key', "project:{$alpha->id}")
            ->where('features.data.0.group_label', 'Alpha')
            ->where('features.data.0.group_kind', 'project')
            ->where('features.data.1.id', $older->id)
            ->where('features.data.2.id', $search->id)
            ->where('features.data.2.group_label', 'Zeta')
            ->where('features.data.3.id', $builds->id)
            ->where('features.data.3.group_label', 'acme/apps')
            ->where('features.data.3.group_kind', 'repository')
            ->where('features.data.4.id', $scripts->id)
            ->where('features.data.5.id', 'ungrouped')
            ->where('features.data.5.group_key', null)
            ->has('feature_groups', 4)
            ->where('feature_groups.0', [
                'key' => "project:{$alpha->id}",
                'label' => 'Alpha',
                'kind' => 'project',
                'features' => 2,
                'tasks' => 4,
                'shipped' => 3,
                'came_back' => 1,
            ])
            ->where('feature_groups.1.label', 'Zeta')
            ->where('feature_groups.1.features', 1)
            ->where('feature_groups.1.tasks', 1)
            ->where('feature_groups.2.label', 'acme/apps')
            ->where('feature_groups.2.kind', 'repository')
            ->where('feature_groups.2.tasks', 1)
            ->where('feature_groups.3.label', 'acme/tools')
            ->etc());

    // Groups follow the filters: only the reverted bug fix is left.
    $this->get('/tasks?type=bugfix')
        ->assertInertia(fn (Assert $page) => $page
            ->has('feature_groups', 1)
            ->where('feature_groups.0.key', "project:{$alpha->id}")
            ->where('feature_groups.0.features', 1)
            ->where('feature_groups.0.tasks', 1)
            ->where('feature_groups.0.shipped', 1)
            ->where('feature_groups.0.came_back', 1)
            ->etc());

    $this->get('/tasks?view=list')
        ->assertInertia(fn (Assert $page) => $page->missing('feature_groups')->etc());
});

test('contributor never sees groups or projects outside their scope', function () {
    $storefront = Project::factory()->create(['name' => 'Storefront']);
    $secret = Project::factory()->create(['name' => 'Secret']);
    $granted = GitRepository::factory()->create(['project_id' => $storefront->id]);
    $sibling = GitRepository::factory()->create(['project_id' => $storefront->id]);
    $hidden = GitRepository::factory()->create(['project_id' => $secret->id]);
    $hiddenLoose = GitRepository::factory()->create();

    $shared = tbfFeature($granted, 'Checkout');
    tbfTask($granted, $shared);
    tbfTask($sibling, $shared);
    tbfTask($hidden, tbfFeature($hidden, 'Vault'));
    tbfTask($hiddenLoose, tbfFeature($hiddenLoose, 'Loose'));

    $user = User::factory()->withRole(UserRole::Contributor)->create();
    $user->repositories()->attach($granted->id, ['access_level' => 'view']);

    $this->actingAs($user)
        ->get('/tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('projects', [['id' => $storefront->id, 'name' => 'Storefront']])
            ->has('features.data', 1)
            ->has('feature_groups', 1)
            ->where('feature_groups.0.label', 'Storefront')
            ->where('feature_groups.0.tasks', 1)
            ->etc());

    // Filtering to a project they cannot see yields nothing rather than its tasks.
    $this->actingAs($user)
        ->get("/tasks?project={$secret->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('features.data', 0)
            ->has('feature_groups', 0)
            ->where('summary.total', 0)
            ->etc());
});

test('feature groups cover every page and follow the row order', function () {
    $alpha = Project::factory()->create(['name' => 'Alpha']);
    $alphaRepo = GitRepository::factory()->create(['project_id' => $alpha->id]);
    // Punctuation that collations disagree on, so group and row order must come from the same SQL.
    $plain = GitRepository::factory()->create(['full_name' => 'vlancy/x']);
    $suffixed = GitRepository::factory()->create(['full_name' => 'vlancyhq/y']);

    foreach (range(1, 20) as $index) {
        tbfTask($alphaRepo, tbfFeature($alphaRepo, "Alpha {$index}"), ['status' => $index <= 3 ? 'reworked' : 'delivered']);
    }

    foreach (range(1, 6) as $index) {
        tbfTask($plain, tbfFeature($plain, "Plain {$index}"));
        tbfTask($suffixed, tbfFeature($suffixed, "Suffixed {$index}"));
    }

    $this->actingAs(User::factory()->manager()->create());

    $rows = [];
    $groups = null;

    foreach ([1, 2] as $page) {
        $this->get("/tasks?features_page={$page}")
            ->assertOk()
            ->assertInertia(function (Assert $inertia) use (&$rows, &$groups) {
                $props = $inertia->toArray()['props'];
                $rows = [...$rows, ...$props['features']['data']];
                $groups = $props['feature_groups'];

                return $inertia;
            });
    }

    expect($rows)->toHaveCount(32)
        ->and(array_column($groups, 'features'))->toBe([20, 6, 6])
        ->and(array_sum(array_column($groups, 'tasks')))->toBe(32)
        ->and($groups[0])->toMatchArray(['key' => "project:{$alpha->id}", 'tasks' => 20, 'came_back' => 3]);

    // Groups appear in exactly the order their rows do across both pages.
    $rowGroups = array_values(array_unique(array_column($rows, 'group_key')));

    expect($rowGroups)->toBe(array_column($groups, 'key'));
});

test('filter options on the board stay within the viewer scope', function () {
    $project = Project::factory()->create(['name' => 'Storefront']);
    $disabledOnly = Project::factory()->create(['name' => 'Paused']);
    $granted = GitRepository::factory()->create(['project_id' => $project->id, 'full_name' => 'acme/granted']);
    $paused = GitRepository::factory()->reviewsDisabled()->create(['project_id' => $disabledOnly->id]);
    $loosePaused = GitRepository::factory()->reviewsDisabled()->create();
    GitRepository::factory()->create(['full_name' => 'acme/hidden']);

    $user = User::factory()->withRole(UserRole::Contributor)->create();
    $user->repositories()->attach([
        $granted->id => ['access_level' => 'view'],
        $paused->id => ['access_level' => 'view'],
        $loosePaused->id => ['access_level' => 'view'],
    ]);

    $this->actingAs($user)
        ->get('/tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('repositories', 1)
            ->where('repositories.0.id', $granted->id)
            ->where('repositories.0.project_id', $project->id)
            ->where('projects', [['id' => $project->id, 'name' => 'Storefront']])
            ->where('has_repositories_without_project', false)
            ->etc());
});

test('feature rows carry the branch of their latest pull request', function () {
    $repository = GitRepository::factory()->create();
    $login = tbfFeature($repository, 'Login');
    $billing = tbfFeature($repository, 'Billing');

    $older = tbfTask($repository, $login, ['delivered_at' => now()->subDays(5)]);
    $older->pullRequest->update(['source_branch' => 'feat/old', 'target_branch' => 'main']);

    $latest = tbfTask($repository, $login, ['delivered_at' => now()->subDay()]);
    $latest->pullRequest->update([
        'source_branch' => 'feat/login',
        'target_branch' => 'dev',
        'state' => 'merged',
        'merged_at' => now()->subDay(),
        'web_url' => 'https://git.example/pr/1',
    ]);

    // Undelivered work orders by when it was seen, so this open PR is Billing's latest.
    $open = tbfTask($repository, $billing, ['status' => 'in_progress', 'delivered_at' => null]);
    $open->pullRequest->update(['source_branch' => 'feat/billing', 'target_branch' => 'dev', 'state' => 'open']);
    tbfTask($repository, $billing, ['delivered_at' => now()->subDays(10)]);

    tbfTask($repository, null);

    $this->actingAs(User::factory()->manager()->create())
        ->get('/tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('features.data', 3)
            ->where('features.data.0.id', $billing->id)
            ->where('features.data.0.last_branch', [
                'source' => 'feat/billing',
                'target' => 'dev',
                'state' => 'open',
                'number' => $open->pullRequest->number,
                'url' => null,
            ])
            ->where('features.data.1.id', $login->id)
            ->where('features.data.1.last_branch', [
                'source' => 'feat/login',
                'target' => 'dev',
                'state' => 'merged',
                'number' => $latest->pullRequest->number,
                'url' => 'https://git.example/pr/1',
            ])
            ->where('features.data.2.id', 'ungrouped')
            ->where('features.data.2.last_branch', null)
            ->etc());
});

test('last branch follows the board filters', function () {
    $repository = GitRepository::factory()->create();
    $login = tbfFeature($repository, 'Login');

    $bugfix = tbfTask($repository, $login, ['type' => 'bugfix', 'delivered_at' => now()->subDays(4)]);
    $bugfix->pullRequest->update(['source_branch' => 'fix/login', 'target_branch' => 'main']);

    $feature = tbfTask($repository, $login, ['type' => 'feature', 'delivered_at' => now()->subDay()]);
    $feature->pullRequest->update(['source_branch' => 'feat/login', 'target_branch' => 'dev']);

    $this->actingAs(User::factory()->manager()->create());

    $this->get('/tasks')
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.data.0.last_branch.source', 'feat/login')
            ->etc());

    $this->get('/tasks?type=bugfix')
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.data.0.last_branch.source', 'fix/login')
            ->where('features.data.0.last_branch.target', 'main')
            ->etc());
});

test('last branch only reads pull requests the contributor can see', function () {
    $project = Project::factory()->create(['name' => 'Storefront']);
    $granted = GitRepository::factory()->create(['project_id' => $project->id]);
    $sibling = GitRepository::factory()->create(['project_id' => $project->id]);
    $shared = tbfFeature($granted, 'Checkout');

    $visible = tbfTask($granted, $shared, ['delivered_at' => now()->subDays(3)]);
    $visible->pullRequest->update(['source_branch' => 'feat/visible']);

    $hidden = tbfTask($sibling, $shared, ['delivered_at' => now()->subHour()]);
    $hidden->pullRequest->update(['source_branch' => 'feat/hidden']);

    $user = User::factory()->withRole(UserRole::Contributor)->create();
    $user->repositories()->attach($granted->id, ['access_level' => 'view']);

    $this->actingAs($user)
        ->get('/tasks')
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.data.0.last_branch.source', 'feat/visible')
            ->etc());
});

test('last branch adds one query regardless of the feature count', function () {
    $repository = GitRepository::factory()->create();
    $this->actingAs(User::factory()->manager()->create());

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/tasks')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    tbfTask($repository, tbfFeature($repository, 'One'));
    // The first request warms the permission cache; measure from the second.
    $count();
    $one = $count();

    foreach (['Two', 'Three', 'Four'] as $name) {
        tbfTask($repository, tbfFeature($repository, $name));
    }

    expect($count())->toBe($one);
});

test('task rows carry their pull request branches', function () {
    $repository = GitRepository::factory()->create();
    $task = tbfTask($repository, null);
    $task->pullRequest->update(['source_branch' => 'feat/x', 'target_branch' => 'dev', 'merged_at' => now()]);

    $this->actingAs(User::factory()->manager()->create())
        ->get('/tasks?view=list')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tasks.0.pull_request.source_branch', 'feat/x')
            ->where('tasks.0.pull_request.target_branch', 'dev')
            ->has('tasks.0.pull_request.merged_at')
            ->etc());
});
