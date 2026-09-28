<?php

use App\Enums\GIT\FindingCategory;
use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\FindingSource;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\Users\User;
use App\Services\Findings\FindingFilterOptionsService;

/**
 * An open gitleaks finding on a fresh pull request.
 */
function openSecretFinding(): PullRequestReviewFinding
{
    $pullRequest = secretScanPullRequest(secretScanRepository());

    return PullRequestReviewFinding::query()->create([
        'pull_request_id' => $pullRequest->id,
        'git_repository_id' => $pullRequest->git_repository_id,
        'source' => FindingSource::Gitleaks->value,
        'dedupe_key' => 'gitleaks:aws-access-token:config/app.php:0123456789abcdef',
        'title' => 'Secret detected: AWS Access Key',
        'severity' => FindingSeverity::Critical->value,
        'category' => FindingCategory::Security->value,
        'file' => 'config/app.php',
        'line' => 2,
        'confidence' => 1.0,
        'explanation' => 'gitleaks rule matched.',
        'suggested_fix' => 'Rotate it.',
    ]);
}

it('does not offer the system-only secret removed reason for manual resolution', function () {
    $offered = array_column(app(FindingFilterOptionsService::class)->resolutionTypes(), 'value');

    expect($offered)->not->toContain(FindingResolutionType::SecretRemoved->value)
        ->and($offered)->toContain(FindingResolutionType::FalsePositive->value);
});

it('rejects secret removed when a finding is resolved by hand', function () {
    $finding = openSecretFinding();
    $this->actingAs(User::factory()->admin()->create());

    $this->post("/admin/findings/{$finding->id}/resolve", ['resolution_type' => 'secret_removed'])
        ->assertSessionHasErrors('resolution_type');

    expect($finding->fresh()->resolved_at)->toBeNull();
});

it('rejects secret removed when findings are resolved in bulk', function () {
    $finding = openSecretFinding();
    $this->actingAs(User::factory()->admin()->create());

    $this->post('/admin/findings/bulk-resolve', ['finding_ids' => [$finding->id], 'resolution_type' => 'secret_removed'])
        ->assertSessionHasErrors('resolution_type');

    expect($finding->fresh()->resolved_at)->toBeNull();
});

it('still accepts a manual reason', function () {
    $finding = openSecretFinding();
    $this->actingAs(User::factory()->admin()->create());

    $this->post("/admin/findings/{$finding->id}/resolve", ['resolution_type' => 'false_positive'])
        ->assertSessionHasNoErrors();

    expect($finding->fresh()->resolution_type)->toBe(FindingResolutionType::FalsePositive);
});

it('only treats a human dismissal as keeping a secret finding resolved', function (FindingResolutionType $type, bool $dismisses) {
    expect($type->dismissesSecret())->toBe($dismisses);
})->with([
    'false positive' => [FindingResolutionType::FalsePositive, true],
    "won't fix" => [FindingResolutionType::WontFix, true],
    'acknowledged' => [FindingResolutionType::Acknowledged, true],
    'fix submitted' => [FindingResolutionType::FixSubmitted, false],
    'fix confirmed' => [FindingResolutionType::FixConfirmed, false],
    'secret removed' => [FindingResolutionType::SecretRemoved, false],
]);
