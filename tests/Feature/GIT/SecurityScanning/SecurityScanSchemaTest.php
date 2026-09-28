<?php

use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSource;
use App\Enums\GIT\Scanner;
use App\Enums\GIT\SecurityScanStatus;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\SecurityScan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('keeps existing gitleaks scans and their findings when the scan table is generalised', function () {
    $findingsMigration = require database_path('migrations/2026_09_29_100001_link_findings_to_security_scans.php');
    $scansMigration = require database_path('migrations/2026_09_29_100000_generalise_secret_scans_into_security_scans.php');

    // Roll back to the shape production has today, seed it, then migrate forward.
    $findingsMigration->down();
    $scansMigration->down();

    $pullRequest = secretScanPullRequest(secretScanRepository());
    $scanId = (string) Str::uuid();
    $findingId = (string) Str::uuid();

    DB::table('secret_scans')->insert([
        'id' => $scanId,
        'pull_request_id' => $pullRequest->id,
        'git_repository_id' => $pullRequest->git_repository_id,
        'head_sha' => 'head-sha-1',
        'status' => 'completed',
        'findings_count' => 1,
        'files_scanned' => 1,
        'files_skipped' => 0,
        'gitleaks_version' => '8.28.0',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('pull_request_review_findings')->insert([
        'id' => $findingId,
        'pull_request_id' => $pullRequest->id,
        'git_repository_id' => $pullRequest->git_repository_id,
        'secret_scan_id' => $scanId,
        'source' => 'gitleaks',
        'dedupe_key' => 'gitleaks:aws-access-token:config/app.php:0123456789abcdef',
        'title' => 'Secret detected: AWS Access Key',
        'severity' => 'critical',
        'category' => 'security',
        'file' => 'config/app.php',
        'line' => 2,
        'confidence' => 1.0,
        'explanation' => 'gitleaks rule matched.',
        'suggested_fix' => 'Rotate it.',
        'is_posted' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $scansMigration->up();
    $findingsMigration->up();

    $scan = SecurityScan::query()->findOrFail($scanId);
    $finding = PullRequestReviewFinding::query()->findOrFail($findingId);

    expect($scan->scanner)->toBe(Scanner::Gitleaks)
        ->and($scan->scanner_version)->toBe('8.28.0')
        ->and($scan->status)->toBe(SecurityScanStatus::Completed)
        ->and($finding->security_scan_id)->toBe($scanId)
        ->and($finding->securityScan->is($scan))->toBeTrue()
        ->and($finding->metadata)->toBe(['kind' => 'secret', 'rule_id' => 'aws-access-token']);
});

it('turns vulnerability scanning on for new repositories by default', function () {
    expect(secretScanRepository()->fresh()->vulnerability_scanning_enabled)->toBeTrue();
});

it('describes each scanner', function (Scanner $scanner, string $check, string $ref, string $prefix, FindingSource $source) {
    expect($scanner->checkName())->toBe($check)
        ->and($scanner->notesRef())->toBe($ref)
        ->and($scanner->logPrefix())->toBe($prefix)
        ->and($scanner->findingSource())->toBe($source);
})->with([
    'gitleaks' => [Scanner::Gitleaks, 'PullLens / Secrets', 'notes/gitleaks', 'secret_scan', FindingSource::Gitleaks],
    'trivy' => [Scanner::Trivy, 'PullLens / Vulnerabilities', 'notes/trivy', 'vulnerability_scan', FindingSource::Trivy],
]);

it('labels trivy findings as vulnerabilities', function () {
    expect(FindingSource::Trivy->value)->toBe('trivy')
        ->and(FindingSource::Trivy->label())->toBe('Vulnerabilities');
});

it('knows the system-only fixed in a later push resolution', function () {
    expect(FindingResolutionType::FixedInLaterPush->value)->toBe('fixed_in_later_push')
        ->and(FindingResolutionType::FixedInLaterPush->label())->toBe('Fixed in a later push')
        ->and(FindingResolutionType::FixedInLaterPush->staysDismissed())->toBeFalse()
        ->and(FindingResolutionType::manualValues())->not->toContain('fixed_in_later_push')
        ->and(FindingResolutionType::manualValues())->not->toContain('secret_removed');
});

it('stores structured metadata on a finding', function () {
    $finding = gitleaksFinding(secretScanPullRequest(secretScanRepository()), [
        'metadata' => ['kind' => 'secret', 'rule_id' => 'aws-access-token'],
    ]);

    expect($finding->fresh()->metadata)->toBe(['kind' => 'secret', 'rule_id' => 'aws-access-token']);
});
