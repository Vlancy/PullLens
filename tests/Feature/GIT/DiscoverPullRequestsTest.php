<?php

use App\Enums\GIT\GitProvider;
use App\Enums\GIT\PullRequestState;
use App\Jobs\GIT\DiscoverPullRequests;
use App\Jobs\GIT\ReviewPullRequest;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestEvent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function discoveryRepository(array $attributes = []): GitRepository
{
    $account = GitAccount::query()->firstOrCreate(
        ['provider' => GitProvider::Github, 'provider_user_id' => '900'],
        ['access_token' => 'token', 'connected_at' => now()],
    );

    return GitRepository::query()->create([
        'git_account_id' => $account->id,
        'provider' => GitProvider::Github,
        'provider_repo_id' => random_int(1, 999999),
        'owner_login' => 'octocat',
        'name' => 'discovery',
        'full_name' => 'octocat/discovery',
        'default_branch' => 'main',
        'reviews_enabled' => true,
        'auto_review_on_open' => true,
        ...$attributes,
    ]);
}

/**
 * The list endpoint the discovery pass walks, followed by the per-PR detail read
 * it makes for each pull request it has never seen.
 */
function fakeGithubOpenPullRequest(int $id = 5001, int $number = 7, string $base = 'main'): void
{
    $detail = [
        'id' => $id,
        'number' => $number,
        'title' => 'Add discovery sync',
        'body' => 'Body text.',
        'state' => 'open',
        'draft' => false,
        'user' => ['login' => 'octocat', 'type' => 'User', 'name' => 'Octo Cat', 'avatar_url' => null],
        'head' => ['ref' => 'feature/discovery', 'sha' => 'head-sha-1'],
        'base' => ['ref' => $base],
        'html_url' => 'https://github.com/octocat/discovery/pull/'.$number,
        'additions' => 42,
        'deletions' => 7,
        'changed_files' => 3,
        'commits' => 2,
        'labels' => [],
        'created_at' => now()->subDay()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
        'closed_at' => null,
        'merged_at' => null,
    ];

    Http::fake([
        'api.github.com/repos/octocat/discovery/pulls/'.$number => Http::response($detail),
        'api.github.com/repos/octocat/discovery/pulls*' => Http::response([$detail]),
    ]);
}

/**
 * Run the discovery pass in-process. Queue::fake() intercepts dispatch_sync for a
 * ShouldQueue job, so the job is resolved through the container instead - which
 * also keeps ReviewPullRequest dispatches visible to the fake.
 */
function runDiscovery(string $gitRepositoryId): void
{
    app()->call([new DiscoverPullRequests($gitRepositoryId), 'handle']);
}

it('records an open pull request that PullLens never saw', function () {
    $repository = discoveryRepository();
    fakeGithubOpenPullRequest();
    Queue::fake();

    runDiscovery($repository->id);

    $pullRequest = PullRequest::query()->where('git_repository_id', $repository->id)->sole();

    expect($pullRequest->provider_pr_id)->toBe(5001)
        ->and($pullRequest->number)->toBe(7)
        ->and($pullRequest->state)->toBe(PullRequestState::Open)
        ->and($pullRequest->changed_files_count)->toBe(3)
        ->and($pullRequest->target_branch)->toBe('main');
});

it('creates no duplicate pull request or event when the sync runs twice', function () {
    $repository = discoveryRepository();
    fakeGithubOpenPullRequest();
    Queue::fake();

    runDiscovery($repository->id);
    runDiscovery($repository->id);

    expect(PullRequest::query()->where('git_repository_id', $repository->id)->count())->toBe(1)
        ->and(PullRequestEvent::query()->where('git_repository_id', $repository->id)->count())->toBe(1);
});

it('leaves a pull request it already holds untouched', function () {
    $repository = discoveryRepository();
    fakeGithubOpenPullRequest();
    Queue::fake();

    $existing = PullRequest::query()->create([
        'git_repository_id' => $repository->id,
        'provider_pr_id' => 5001,
        'number' => 7,
        'title' => 'Original title',
        'state' => PullRequestState::Open->value,
        'author_login' => 'octocat',
        'source_branch' => 'feature/discovery',
        'target_branch' => 'main',
        'opened_at' => now()->subDay(),
    ]);

    runDiscovery($repository->id);

    expect($existing->fresh()->title)->toBe('Original title')
        ->and(PullRequest::query()->where('git_repository_id', $repository->id)->count())->toBe(1);
});

it('queues a review for a discovered pull request the repository would review', function () {
    $repository = discoveryRepository();
    fakeGithubOpenPullRequest();
    Queue::fake();

    runDiscovery($repository->id);

    Queue::assertPushed(ReviewPullRequest::class, 1);
});

it('queues no review when the repository does not review on open', function () {
    $repository = discoveryRepository(['auto_review_on_open' => false]);
    fakeGithubOpenPullRequest();
    Queue::fake();

    runDiscovery($repository->id);

    Queue::assertNotPushed(ReviewPullRequest::class);
    expect(PullRequest::query()->where('git_repository_id', $repository->id)->count())->toBe(1);
});

it('queues no review when the pull request targets an untracked branch', function () {
    $repository = discoveryRepository(['tracked_branches' => ['release']]);
    fakeGithubOpenPullRequest();
    Queue::fake();

    runDiscovery($repository->id);

    Queue::assertNotPushed(ReviewPullRequest::class);
});

it('queues no review when reviews are disabled for the repository', function () {
    $repository = discoveryRepository(['reviews_enabled' => false]);
    fakeGithubOpenPullRequest();
    Queue::fake();

    runDiscovery($repository->id);

    Queue::assertNotPushed(ReviewPullRequest::class);
});

it('survives a failing GitHub call without throwing', function () {
    $repository = discoveryRepository();
    Http::fake(['api.github.com/*' => Http::response([], 500)]);
    Queue::fake();

    runDiscovery($repository->id);

    expect(PullRequest::query()->where('git_repository_id', $repository->id)->count())->toBe(0);
    Queue::assertNotPushed(ReviewPullRequest::class);
});

it('is unique per repository so a double click cannot scan twice', function () {
    $repository = discoveryRepository();

    expect(new DiscoverPullRequests($repository->id))
        ->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new DiscoverPullRequests($repository->id))->uniqueId())->toBe($repository->id);
});
