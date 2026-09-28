<?php

use App\Jobs\GIT\SyncFindingReaction;
use App\Jobs\GIT\SyncFindingReactions;
use Illuminate\Support\Facades\Queue;

it('syncs reactions only for AI review findings, since helpfulness measures the AI', function () {
    Queue::fake();
    $pullRequest = secretScanPullRequest(secretScanRepository());
    $posted = ['is_posted' => true, 'provider_comment_id' => 555];
    $ai = gitleaksFinding($pullRequest, ['source' => 'ai', 'dedupe_key' => 'ai-1', ...$posted]);
    gitleaksFinding($pullRequest, $posted);
    gitleaksFinding($pullRequest, ['source' => 'trivy', 'dedupe_key' => 'trivy:vuln:composer.lock:a:1:CVE-1', ...$posted]);

    (new SyncFindingReactions)->handle();

    Queue::assertPushed(SyncFindingReaction::class, 1);
    Queue::assertPushed(SyncFindingReaction::class, fn ($job) => $job->findingId === $ai->id);
});
