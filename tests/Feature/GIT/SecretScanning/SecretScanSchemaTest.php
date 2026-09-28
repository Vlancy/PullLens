<?php

use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSource;
use App\Enums\GIT\SecurityScanStatus;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\SecurityScan;

it('stores a gitleaks finding that belongs to a scan instead of a review', function () {
    $repository = secretScanRepository();
    $pullRequest = secretScanPullRequest($repository);

    $scan = SecurityScan::query()->create([
        'pull_request_id' => $pullRequest->id,
        'git_repository_id' => $repository->id,
        'head_sha' => 'head-sha-1',
        'status' => SecurityScanStatus::Completed,
        'findings_count' => 1,
    ]);

    $finding = gitleaksFinding($pullRequest, ['security_scan_id' => $scan->id]);

    expect($finding->fresh()->source)->toBe(FindingSource::Gitleaks)
        ->and($finding->fresh()->pull_request_review_id)->toBeNull()
        ->and($finding->securityScan->is($scan))->toBeTrue()
        ->and($scan->findings()->count())->toBe(1);
});

it('defaults findings that do not name a source to ai', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());

    $finding = PullRequestReviewFinding::query()->create([
        'pull_request_id' => $pullRequest->id, 'git_repository_id' => $pullRequest->git_repository_id,
        'dedupe_key' => 'k', 'title' => 't', 'severity' => 'high', 'category' => 'correctness',
        'file' => 'a.php', 'confidence' => 0.9, 'explanation' => 'e', 'suggested_fix' => 'f',
    ]);

    expect($finding->fresh()->source)->toBe(FindingSource::Ai);
});

it('turns secret scanning on for new repositories by default', function () {
    expect(secretScanRepository()->fresh()->secret_scanning_enabled)->toBeTrue();
});

it('knows the secret removed resolution', function () {
    expect(FindingResolutionType::SecretRemoved->value)->toBe('secret_removed')
        ->and(FindingResolutionType::SecretRemoved->label())->toBe('Secret removed from diff');
});
