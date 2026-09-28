<?php

use App\Jobs\GIT\ScanPullRequestSecrets;
use App\Services\Git\Webhooks\Handlers\PullRequestEventHandler;
use Illuminate\Support\Facades\Queue;

function pullRequestPayload(string $action, string $sha = 'abc123'): array
{
    return [
        'action' => $action,
        'pull_request' => [
            'id' => 9001, 'number' => 7, 'title' => 'Add config', 'body' => '', 'state' => 'open', 'draft' => false,
            'user' => ['login' => 'octocat', 'type' => 'User'],
            'head' => ['ref' => 'feature/config', 'sha' => $sha],
            'base' => ['ref' => 'main'],
            'html_url' => 'https://github.com/octocat/app/pull/7',
            'additions' => 1, 'deletions' => 0, 'changed_files' => 1, 'commits' => 1, 'labels' => [],
            'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            'closed_at' => null, 'merged_at' => null,
        ],
    ];
}

it('queues a secret scan when a pull request brings new code', function (string $action) {
    Queue::fake();

    app(PullRequestEventHandler::class)->handle(secretScanRepository()->fresh(), pullRequestPayload($action));

    Queue::assertPushed(ScanPullRequestSecrets::class, fn ($job) => $job->headSha === 'abc123');
})->with(['opened', 'synchronize', 'reopened']);

it('scans even when AI reviews are off', function () {
    Queue::fake();

    app(PullRequestEventHandler::class)->handle(secretScanRepository(['reviews_enabled' => false])->fresh(), pullRequestPayload('opened'));

    Queue::assertPushed(ScanPullRequestSecrets::class);
});

it('does not scan when the repository turned it off', function () {
    Queue::fake();

    app(PullRequestEventHandler::class)->handle(secretScanRepository(['secret_scanning_enabled' => false])->fresh(), pullRequestPayload('opened'));

    Queue::assertNotPushed(ScanPullRequestSecrets::class);
});

it('does not scan for actions that bring no new code', function (string $action) {
    Queue::fake();

    app(PullRequestEventHandler::class)->handle(secretScanRepository()->fresh(), pullRequestPayload($action));

    Queue::assertNotPushed(ScanPullRequestSecrets::class);
})->with(['closed', 'edited', 'labeled']);
