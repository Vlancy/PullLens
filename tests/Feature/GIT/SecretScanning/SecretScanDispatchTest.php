<?php

use App\Jobs\GIT\ScanPullRequestSecrets;
use App\Services\Git\Webhooks\Handlers\PullRequestEventHandler;
use Illuminate\Support\Facades\Queue;

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
