<?php

use App\Enums\GIT\FindingSource;
use App\Enums\GIT\GitProvider;
use App\Enums\GIT\PullRequestState;
use App\Enums\GIT\Scanner;
use App\Enums\GIT\SecurityScanStatus;
use App\Models\AI\AiProvider;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\SecurityScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Feature tests render real Inertia pages. Without this they would fail whenever
    // the Vite manifest is missing or stale, turning an asset-build concern into a
    // test failure that says nothing about the behaviour under test.
    //
    // The public-surface switches are pinned off for the same reason. The landing
    // page, the hosted guide, robots.txt and the sitemap are all read through them,
    // and without this the suite would answer to whatever .env the machine happens
    // to carry - an instance that runs privately would fail two dozen tests that say
    // nothing about the change under test. Tests for the switched-on behaviour turn
    // them back on themselves.
    ->beforeEach(function () {
        $this->withoutVite();

        config()->set('pulllens.homepage_login', false);
        config()->set('pulllens.hide_login', false);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Create a tracked repository, optionally pinned to an AI provider and model.
 */
function aiSettingsRepository(?AiProvider $provider = null, ?string $model = null, string $name = 'PullLens'): GitRepository
{
    $account = GitAccount::query()->firstOrCreate(
        ['provider' => GitProvider::Github, 'provider_user_id' => '123'],
        ['access_token' => 'token', 'connected_at' => now()],
    );

    return GitRepository::query()->create([
        'git_account_id' => $account->id,
        'provider' => GitProvider::Github,
        'provider_repo_id' => random_int(1, 999999),
        'owner_login' => 'vlancy',
        'name' => $name,
        'full_name' => 'vlancy/'.$name,
        'ai_provider_id' => $provider?->id,
        'ai_model' => $model,
    ]);
}

/**
 * Create a tracked repository for secret-scanning tests.
 */
function secretScanRepository(array $attributes = []): GitRepository
{
    $account = GitAccount::query()->firstOrCreate(
        ['provider' => GitProvider::Github, 'provider_user_id' => '777'],
        ['access_token' => 'token', 'connected_at' => now()],
    );

    return GitRepository::query()->create([
        'git_account_id' => $account->id,
        'provider' => GitProvider::Github,
        'provider_repo_id' => random_int(1, 999999),
        'owner_login' => 'octocat',
        'name' => 'app',
        'full_name' => 'octocat/app',
        'default_branch' => 'main',
        'reviews_enabled' => true,
        ...$attributes,
    ]);
}

/**
 * Create an open pull request number 7 targeting main.
 */
function secretScanPullRequest(GitRepository $repository, array $attributes = []): PullRequest
{
    return PullRequest::query()->create([
        'git_repository_id' => $repository->id,
        'provider_pr_id' => random_int(1, 999999),
        'number' => 7,
        'title' => 'Add config',
        'state' => PullRequestState::Open->value,
        'author_login' => 'octocat',
        'source_branch' => 'feature/config',
        'target_branch' => 'main',
        'head_sha' => 'head-sha-1',
        'opened_at' => now()->subHour(),
        ...$attributes,
    ]);
}

/**
 * Create an open gitleaks finding with no AI review behind it.
 */
function gitleaksFinding(PullRequest $pullRequest, array $attributes = []): PullRequestReviewFinding
{
    return PullRequestReviewFinding::query()->create([
        'pull_request_review_id' => null,
        'pull_request_id' => $pullRequest->id,
        'git_repository_id' => $pullRequest->git_repository_id,
        'source' => FindingSource::Gitleaks->value,
        'dedupe_key' => 'gitleaks:aws-access-token:config/app.php:'.substr(md5(uniqid()), 0, 16),
        'title' => 'Secret detected: AWS Access Key',
        'severity' => 'critical',
        'category' => 'security',
        'file' => 'config/app.php',
        'line' => 3,
        'confidence' => 1.0,
        'explanation' => 'Rule `aws-access-token` matched `REDACTED`.',
        'suggested_fix' => 'Rotate the credential.',
        ...$attributes,
    ]);
}

/**
 * Create a running security scan of pull request head head-sha-1 for one scanner.
 */
function securityScan(PullRequest $pullRequest, Scanner $scanner, array $attributes = []): SecurityScan
{
    return SecurityScan::query()->create([
        'pull_request_id' => $pullRequest->id,
        'git_repository_id' => $pullRequest->git_repository_id,
        'scanner' => $scanner,
        'head_sha' => 'head-sha-1',
        'status' => SecurityScanStatus::Running,
        ...$attributes,
    ]);
}
