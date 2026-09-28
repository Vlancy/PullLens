<?php

use App\Enums\GIT\PullRequestCommentType;
use App\Jobs\GIT\CheckFindingResolutions;
use App\Jobs\GIT\DisputePullRequestFinding;
use App\Models\GIT\PullRequestComment;
use Illuminate\Support\Facades\Http;

it('does not resolve a secret finding just because its file changed', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    $finding = gitleaksFinding($pullRequest, ['is_posted' => true, 'provider_comment_id' => 555]);

    Http::fake([
        'api.github.com/repos/octocat/app/pulls/7/files*' => Http::response([
            ['filename' => 'config/app.php', 'status' => 'modified', 'patch' => "@@ -1 +1 @@\n-a\n+b"],
        ]),
        '*' => Http::response([], 201),
    ]);

    app()->call([new CheckFindingResolutions($pullRequest->id, 'head-sha-2'), 'handle']);

    expect($finding->fresh()->resolved_at)->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/pulls/7/comments'));
});

it('does not dispute a secret finding when someone replies to its comment', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    $finding = gitleaksFinding($pullRequest, ['is_posted' => true, 'provider_comment_id' => 555]);

    $comment = PullRequestComment::query()->create([
        'pull_request_id' => $pullRequest->id,
        'pull_request_review_finding_id' => $finding->id,
        'provider_comment_id' => 556,
        'provider_in_reply_to_id' => 555,
        'comment_type' => PullRequestCommentType::ReviewComment->value,
        'author_login' => 'octocat',
        'author_type' => 'User',
        'body' => 'This is a fake key, it is fine.',
        'is_pull_lens' => false,
        'provider_created_at' => now(),
    ]);

    Http::fake();

    app()->call([new DisputePullRequestFinding($comment->id), 'handle']);

    expect($finding->fresh()->resolved_at)->toBeNull();
    Http::assertNothingSent();
});
