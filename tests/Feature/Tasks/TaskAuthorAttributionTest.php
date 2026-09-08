<?php

use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestCommit;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestTask;
use App\Services\Tasks\TaskRecorder;

/*
| A task belongs to whoever wrote the code, not to whoever pressed "Create pull
| request". A lead who opens a PR on behalf of the team must not collect the team's
| work in the monthly report.
*/

function attributionPr(GitRepository $repository, array $attributes = []): array
{
    $pullRequest = PullRequest::query()->create([
        'git_repository_id' => $repository->id,
        'provider_pr_id' => 9001,
        'number' => 91,
        'title' => 'Release batch',
        'state' => 'merged',
        'author_login' => 'carol',
        'author_name' => 'Carol Lead',
        'author_avatar_url' => 'https://avatars.test/carol.png',
        'source_branch' => 'feature/batch',
        'target_branch' => 'main',
        'opened_at' => now()->subDays(3),
        'merged_at' => now()->subDay(),
        ...$attributes,
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

function attributionCommit(PullRequest $pullRequest, string $sha, ?string $login, array $files, array $attributes = []): PullRequestCommit
{
    return PullRequestCommit::query()->create([
        'pull_request_id' => $pullRequest->id,
        'sha' => str_pad($sha, 40, '0'),
        'short_sha' => substr(str_pad($sha, 40, '0'), 0, 7),
        'message' => "commit {$sha}",
        'author_login' => $login,
        'author_name' => $login === null ? null : ucfirst($login).' Dev',
        'author_avatar_url' => $login === null ? null : "https://avatars.test/{$login}.png",
        'committed_at' => now()->subDays(2),
        'files' => $files,
        ...$attributes,
    ]);
}

function attributionTask(string $key, array $files): array
{
    return [
        'dedupe_key' => $key,
        'title' => ucfirst(str_replace('-', ' ', $key)),
        'type' => 'feature',
        'description' => null,
        'estimated_hours' => 1,
        'files' => $files,
        'links' => [],
    ];
}

it('attributes each task to the commit author who touched its files', function () {
    $repository = GitRepository::factory()->create();
    [$pullRequest, $review] = attributionPr($repository);

    attributionCommit($pullRequest, 'a1', 'alice', ['app/Api.php']);
    attributionCommit($pullRequest, 'b1', 'bob', ['database/seeders/UsersTableSeeder.php']);

    app(TaskRecorder::class)->record($pullRequest, $review, [
        attributionTask('add-endpoint', ['app/Api.php']),
        attributionTask('fix-seeder', ['database/seeders/UsersTableSeeder.php']),
    ]);

    $tasks = PullRequestTask::query()->pluck('author_login', 'dedupe_key');

    expect($tasks['add-endpoint'])->toBe('alice')
        ->and($tasks['fix-seeder'])->toBe('bob');

    $endpoint = PullRequestTask::query()->where('dedupe_key', 'add-endpoint')->sole();

    expect($endpoint->author_name)->toBe('Alice Dev')
        ->and($endpoint->author_avatar_url)->toBe('https://avatars.test/alice.png');
});

it('falls back to the pull request commit author who did the most work when no file matches', function () {
    $repository = GitRepository::factory()->create();
    [$pullRequest, $review] = attributionPr($repository);

    attributionCommit($pullRequest, 'a1', 'alice', ['app/Api.php']);
    attributionCommit($pullRequest, 'a2', 'alice', ['app/Api.php']);
    attributionCommit($pullRequest, 'b1', 'bob', ['database/x.php']);

    app(TaskRecorder::class)->record($pullRequest, $review, [
        attributionTask('unmatched-work', ['resources/js/pages/tasks.tsx']),
    ]);

    expect(PullRequestTask::query()->sole()->author_login)->toBe('alice');
});

it('falls back to the pull request author when no commit carries an author', function () {
    $repository = GitRepository::factory()->create();
    [$pullRequest, $review] = attributionPr($repository);

    attributionCommit($pullRequest, 'a1', null, ['app/Api.php']);

    app(TaskRecorder::class)->record($pullRequest, $review, [
        attributionTask('add-endpoint', ['app/Api.php']),
    ]);

    $task = PullRequestTask::query()->sole();

    expect($task->author_login)->toBe('carol')
        ->and($task->author_name)->toBe('Carol Lead');
});

it('re-attributes the tasks already recorded against the pull request opener', function () {
    $repository = GitRepository::factory()->create();
    [$pullRequest, $review] = attributionPr($repository);

    attributionCommit($pullRequest, 'a1', 'alice', ['app/Api.php']);

    $task = PullRequestTask::query()->create([
        'pull_request_review_id' => $review->id,
        'pull_request_id' => $pullRequest->id,
        'git_repository_id' => $repository->id,
        'dedupe_key' => 'legacy-task',
        'title' => 'Legacy task',
        'type' => 'feature',
        'files' => ['app/Api.php'],
        'author_login' => 'carol',
        'author_name' => 'Carol Lead',
        'author_avatar_url' => 'https://avatars.test/carol.png',
    ]);

    $this->artisan('tasks:reattribute-authors')->assertSuccessful();

    expect($task->fresh()->author_login)->toBe('alice');
});
