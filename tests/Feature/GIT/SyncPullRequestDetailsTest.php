<?php

use App\Enums\GIT\ContributorRole;
use App\Enums\GIT\GitProvider;
use App\Jobs\GIT\SyncPullRequestDetails;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestCommit;
use App\Models\GIT\PullRequestContributor;
use App\Models\GIT\PullRequestFile;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function detailsPullRequest(): PullRequest
{
    $account = GitAccount::query()->create([
        'provider' => GitProvider::Github,
        'provider_user_id' => '901',
        'access_token' => 'token',
        'connected_at' => now(),
    ]);

    $repository = GitRepository::query()->create([
        'git_account_id' => $account->id,
        'provider' => GitProvider::Github,
        'provider_repo_id' => 4242,
        'owner_login' => 'octocat',
        'name' => 'details',
        'full_name' => 'octocat/details',
        'default_branch' => 'main',
    ]);

    return PullRequest::query()->create([
        'git_repository_id' => $repository->id,
        'provider_pr_id' => 777,
        'number' => 7,
        'title' => 'Large change',
        'state' => 'open',
        'author_login' => 'octocat',
        'source_branch' => 'feature/large',
        'target_branch' => 'main',
        'opened_at' => now(),
    ]);
}

function listedCommit(string $sha): array
{
    return [
        'sha' => $sha,
        'commit' => ['message' => "Commit {$sha}", 'author' => ['name' => 'Octo', 'email' => 'o@x.test', 'date' => now()->toIso8601String()]],
        'author' => ['login' => 'octocat', 'id' => 1, 'avatar_url' => null],
    ];
}

function fakeDetailsApi(array $shas): void
{
    $responses = [
        'api.github.com/repos/octocat/details/pulls/7/commits*' => Http::response(array_map(listedCommit(...), $shas)),
        'api.github.com/repos/octocat/details/pulls/7/files*' => Http::response([
            ['filename' => 'app/Big.php', 'status' => 'modified', 'additions' => 3, 'deletions' => 1, 'patch' => '@@'],
        ]),
    ];

    foreach ($shas as $sha) {
        $responses["api.github.com/repos/octocat/details/commits/{$sha}"] = Http::response([
            'sha' => $sha,
            'stats' => ['additions' => 5, 'deletions' => 2],
            'files' => [['filename' => "src/{$sha}.php"]],
        ]);
    }

    Http::fake($responses);
}

function runDetailsSync(PullRequest $pullRequest): void
{
    app()->call([new SyncPullRequestDetails($pullRequest->id), 'handle']);
}

it('fetches detail only for commits it has not stored yet', function () {
    $pullRequest = detailsPullRequest();

    PullRequestCommit::query()->create([
        'pull_request_id' => $pullRequest->id,
        'sha' => 'aaa1111',
        'short_sha' => 'aaa1111',
        'message' => 'Commit aaa1111',
        'committed_at' => now(),
        'additions' => 5,
        'deletions' => 2,
        'changed_files_count' => 1,
        'files' => ['src/aaa1111.php'],
    ]);

    fakeDetailsApi(['aaa1111', 'bbb2222']);

    runDetailsSync($pullRequest);

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/commits/aaa1111'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/commits/bbb2222'));

    expect(PullRequestCommit::where('pull_request_id', $pullRequest->id)->count())->toBe(2)
        ->and(PullRequestCommit::where('sha', 'bbb2222')->first()->files)->toBe(['src/bbb2222.php']);
});

it('refetches a stored commit whose file list predates the files column', function () {
    $pullRequest = detailsPullRequest();

    PullRequestCommit::query()->create([
        'pull_request_id' => $pullRequest->id,
        'sha' => 'aaa1111',
        'short_sha' => 'aaa1111',
        'message' => 'Commit aaa1111',
        'committed_at' => now(),
        'files' => null,
    ]);

    fakeDetailsApi(['aaa1111']);

    runDetailsSync($pullRequest);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/commits/aaa1111'));
    expect(PullRequestCommit::where('sha', 'aaa1111')->first()->files)->toBe(['src/aaa1111.php']);
});

it('syncs files even when commit detail fetching fails', function () {
    $pullRequest = detailsPullRequest();

    Http::fake([
        'api.github.com/repos/octocat/details/pulls/7/files*' => Http::response([
            ['filename' => 'app/Big.php', 'status' => 'modified', 'additions' => 3, 'deletions' => 1],
        ]),
        'api.github.com/repos/octocat/details/pulls/7/commits*' => Http::response([listedCommit('ccc3333')]),
        'api.github.com/repos/octocat/details/commits/*' => fn () => throw new RuntimeException('timed out'),
    ]);

    expect(fn () => runDetailsSync($pullRequest))->toThrow(RuntimeException::class);

    expect(PullRequestFile::where('pull_request_id', $pullRequest->id)->count())->toBe(1);
});

it('does not inflate contributor commit counts when re-run', function () {
    $pullRequest = detailsPullRequest();

    fakeDetailsApi(['aaa1111', 'bbb2222']);

    runDetailsSync($pullRequest);
    runDetailsSync($pullRequest);

    $coAuthor = PullRequestContributor::where('pull_request_id', $pullRequest->id)
        ->where('role', ContributorRole::CoAuthor->value)
        ->first();

    expect($coAuthor->commit_count)->toBe(2);
});
