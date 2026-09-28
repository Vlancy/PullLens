<?php

use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSource;
use App\Enums\GIT\PullRequestCommentType;
use App\Enums\GIT\Scanner;
use App\Enums\GIT\SecurityScanStatus;
use App\Jobs\GIT\CheckFindingResolutions;
use App\Jobs\GIT\DisputePullRequestFinding;
use App\Jobs\GIT\ScanPullRequestVulnerabilities;
use App\Models\GIT\PullRequestComment;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\SecurityScan;
use App\Services\Git\VulnerabilityScanning\TrivyFailed;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->cache = storage_path('framework/testing/trivy-job-cache-'.uniqid());
    config()->set('pulllens.vulnerability_scanning.cache_dir', $this->cache);
    File::ensureDirectoryExists($this->cache.'/db');
    file_put_contents($this->cache.'/db/metadata.json', json_encode(['UpdatedAt' => now()->subHour()->toIso8601String()]));
});

afterEach(fn () => File::deleteDirectory($this->cache));

/**
 * Fake trivy answering the head and base runs of the scan job with fixtures.
 */
function fakeTrivyForJob(string $head, ?string $base = null, bool $installed = true, int $exitCode = 0): void
{
    Process::fake(function (PendingProcess $process) use ($head, $base, $installed, $exitCode) {
        $command = (array) $process->command;

        if (in_array('--version', $command, true)) {
            return $installed ? Process::result('Version: 0.74.0') : Process::result('', 'not found', 127);
        }

        if ($exitCode !== 0) {
            return Process::result('', 'db locked', $exitCode);
        }

        $fixture = basename($process->path) === 'base' ? ($base ?? 'empty.json') : $head;
        file_put_contents($command[array_search('--output', $command, true) + 1], file_get_contents(base_path("tests/Fixtures/Trivy/{$fixture}")));

        return Process::result('');
    });
}

/**
 * Fake every GitHub call the vulnerability scan job makes for PR #7 changing $files; $overrides win.
 */
function fakeGitHubForVulnerabilityScan(array $files = [['filename' => 'composer.lock', 'status' => 'modified']], array $overrides = []): void
{
    app()->forgetInstance(Factory::class);
    Http::clearResolvedInstance(Factory::class);

    $base = 'api.github.com/repos/octocat/app';

    if ($overrides !== []) {
        Http::fake($overrides);
    }

    Http::fake([
        "{$base}/compare/*" => Http::response(['merge_base_commit' => ['sha' => 'merge-base-sha']]),
        "{$base}/pulls/7/files*" => Http::response($files),
        "{$base}/contents/*" => Http::response('{}'),
        "{$base}/check-runs/*" => Http::response([]),
        "{$base}/check-runs" => Http::response(['id' => 99], 201),
        "{$base}/pulls/7/reviews" => Http::response(['id' => 1], 200),
        "{$base}/pulls/7/comments" => Http::response(['id' => 555], 201),
        "{$base}/git/ref/notes/trivy" => Http::response([], 404),
        "{$base}/git/blobs" => Http::response(['sha' => 'blob1'], 201),
        "{$base}/git/trees" => Http::response(['sha' => 'tree1'], 201),
        "{$base}/git/commits" => Http::response(['sha' => 'notes1'], 201),
        "{$base}/git/refs" => Http::response([], 201),
    ]);
}

/**
 * Run the vulnerability scan job synchronously for a pull request head.
 */
function runVulnerabilityScan(string $pullRequestId, string $headSha = 'head-sha-1'): void
{
    app()->call([new ScanPullRequestVulnerabilities($pullRequestId, $headSha), 'handle']);
}

it('records a new high vulnerability with its metadata and fails the check', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-head.json', 'composer-base.json');

    runVulnerabilityScan($pullRequest->id);

    $scan = SecurityScan::query()->sole();
    $finding = PullRequestReviewFinding::query()->sole();

    expect($scan->scanner)->toBe(Scanner::Trivy)
        ->and($scan->status)->toBe(SecurityScanStatus::Completed)
        ->and($scan->scanner_version)->toBe('0.74.0')
        ->and($scan->notes_commit_sha)->toBe('notes1')
        ->and($finding->source)->toBe(FindingSource::Trivy)
        ->and($finding->title)->toBe('CVE-2022-24775 in guzzlehttp/psr7@1.8.3')
        ->and($finding->severity->value)->toBe('high')
        ->and($finding->category->value)->toBe('security')
        ->and($finding->file)->toBe('composer.lock')
        ->and($finding->line)->toBe(130)
        ->and($finding->dedupe_key)->toBe('trivy:vuln:composer.lock:guzzlehttp/psr7:1.8.3:CVE-2022-24775')
        ->and($finding->suggested_fix)->toBe('Upgrade guzzlehttp/psr7 to 1.8.4 or later.')
        ->and($finding->metadata)->toBe([
            'kind' => 'vulnerability', 'rule_id' => 'CVE-2022-24775', 'package' => 'guzzlehttp/psr7',
            'installed_version' => '1.8.3', 'fixed_version' => '1.8.4', 'url' => 'https://avd.aquasec.com/nvd/cve-2022-24775',
        ]);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/check-runs') && $r['name'] === 'PullLens / Vulnerabilities');
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/check-runs/99')
        && $r['conclusion'] === 'failure' && $r['output']['annotations'][0]['start_line'] === 130);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls/7/comments') && $r['path'] === 'composer.lock' && $r['line'] === 130);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/refs') && $r['ref'] === 'refs/notes/trivy');
});

it('reads the base copies at the merge base of the pull request', function () {
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-head.json', 'composer-base.json');

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository())->id);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/compare/main...head-sha-1'));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/contents/composer.lock') && $r['ref'] === 'merge-base-sha');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/contents/') && $r['ref'] === 'main');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r['output']['summary'] ?? '', 'merge base'));
});

it('falls back to the target branch tip and says so when the merge base is unknown', function () {
    fakeGitHubForVulnerabilityScan(overrides: ['api.github.com/repos/octocat/app/compare/*' => Http::response([], 500)]);
    fakeTrivyForJob('composer-head.json', 'composer-base.json');

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository())->id);

    expect(PullRequestReviewFinding::query()->count())->toBe(1);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/contents/composer.lock') && $r['ref'] === 'main');
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/check-runs/99')
        && str_contains($r['output']['summary'], 'Compared against the tip of main because the merge base could not be determined.'));
});

it('passes the check when the base already had every problem', function () {
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-base.json', 'composer-base.json');

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository())->id);

    expect(PullRequestReviewFinding::query()->count())->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['conclusion'] === 'success');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/pulls/7/reviews') || str_contains($r->url(), '/git/'));
});

it('is neutral, not failing, when only medium problems are new', function () {
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-base.json', 'empty.json');

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository())->id);

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['conclusion'] === 'neutral'
        && $r['output']['annotations'][0]['annotation_level'] === 'warning');
});

it('names a misconfiguration without a line in the summary only, once', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    fakeGitHubForVulnerabilityScan([['filename' => 'Dockerfile', 'status' => 'added']]);
    fakeTrivyForJob('dockerfile.json');

    runVulnerabilityScan($pullRequest->id, 'head-sha-1');

    $rootUser = PullRequestReviewFinding::query()->where('dedupe_key', 'trivy:config:Dockerfile:DS-0002:-')->sole();

    expect($rootUser->line)->toBeNull()->and($rootUser->is_posted)->toBeTrue()->and($rootUser->provider_comment_id)->toBeNull();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls/7/reviews') && str_contains($r['body'], "Image user should not be 'root'"));
    // The line-less finding gets no inline comment, while the one with a line does.
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/pulls/7/comments') && str_contains((string) ($r['body'] ?? ''), "Image user should not be 'root'"));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls/7/comments') && str_contains((string) ($r['body'] ?? ''), "':latest' tag used"));
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && count($r['output']['annotations']) === 1);

    $pullRequest->update(['head_sha' => 'head-sha-2']);
    fakeGitHubForVulnerabilityScan([['filename' => 'Dockerfile', 'status' => 'added']]);
    fakeTrivyForJob('dockerfile.json');
    runVulnerabilityScan($pullRequest->id, 'head-sha-2');

    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/pulls/7/reviews'));
});

it('resolves a problem a later push fixed and says so in its thread', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-head.json', 'composer-base.json');
    runVulnerabilityScan($pullRequest->id, 'head-sha-1');

    $pullRequest->update(['head_sha' => 'head-sha-2']);
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-base.json', 'composer-base.json');
    runVulnerabilityScan($pullRequest->id, 'head-sha-2');

    $finding = PullRequestReviewFinding::query()->sole();

    expect($finding->resolution_type)->toBe(FindingResolutionType::FixedInLaterPush);
    Http::assertSent(fn (Request $r) => ($r['in_reply_to'] ?? null) === 555 && str_contains($r['body'], 'Fixed in a later push'));
});

it('keeps a dismissed vulnerability resolved on the next push', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-head.json', 'composer-base.json');
    runVulnerabilityScan($pullRequest->id, 'head-sha-1');
    PullRequestReviewFinding::query()->sole()->update(['resolved_at' => now(), 'resolution_type' => FindingResolutionType::WontFix]);

    $pullRequest->update(['head_sha' => 'head-sha-2']);
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-head.json', 'composer-base.json');
    runVulnerabilityScan($pullRequest->id, 'head-sha-2');

    expect(PullRequestReviewFinding::query()->count())->toBe(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['conclusion'] === 'success');
});

it('skips with a neutral check and a refresh hint when the database is stale', function () {
    file_put_contents($this->cache.'/db/metadata.json', json_encode(['UpdatedAt' => now()->subDays(5)->toIso8601String()]));
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-head.json');

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository())->id);

    expect(SecurityScan::query()->sole()->status)->toBe(SecurityScanStatus::Skipped)
        ->and(PullRequestReviewFinding::query()->count())->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['conclusion'] === 'neutral'
        && str_contains($r['output']['summary'], 'php artisan pulllens:update-trivy-db'));
    Process::assertNotRan(fn (PendingProcess $p) => in_array('fs', (array) $p->command, true));
});

it('skips without a check run when trivy is not installed', function () {
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-head.json', installed: false);

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository())->id);

    expect(SecurityScan::query()->sole()->status)->toBe(SecurityScanStatus::Skipped);
    Http::assertNothingSent();
});

it('passes with a clear title when no scannable file changed', function () {
    fakeGitHubForVulnerabilityScan([['filename' => 'app/Models/User.php', 'status' => 'modified']]);
    fakeTrivyForJob('composer-head.json');

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository())->id);

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['conclusion'] === 'success'
        && $r['output']['title'] === 'No dependency or infrastructure files changed');
});

it('records a failure, marks the check neutral and rethrows when trivy breaks', function () {
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-head.json', exitCode: 1);

    expect(fn () => runVulnerabilityScan(secretScanPullRequest(secretScanRepository())->id))->toThrow(TrivyFailed::class);

    expect(SecurityScan::query()->sole()->status)->toBe(SecurityScanStatus::Failed);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/check-runs/99') && $r['conclusion'] === 'neutral');
});

it('does nothing when the repository turned vulnerability scanning off', function () {
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-head.json');

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository(['vulnerability_scanning_enabled' => false]))->id);

    expect(SecurityScan::query()->count())->toBe(0);
});

it('skips a head that is no longer the pull request head', function () {
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-head.json');

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository(), ['head_sha' => 'head-sha-2'])->id, 'head-sha-1');

    expect(SecurityScan::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('runs one vulnerability scan per pull request at a time, apart from the secret scan', function () {
    $job = new ScanPullRequestVulnerabilities('pr-123', 'head-sha-1');
    $overlap = collect($job->middleware())->first(fn ($m) => $m instanceof WithoutOverlapping);

    expect($overlap->key)->toBe('trivy:pr-123')
        ->and($job->uniqueId())->toBe('trivy:pr-123:head-sha-1')
        ->and($job->maxExceptions)->toBe(2)
        ->and($job->timeout)->toBe(180)
        ->and(abs($job->retryUntil()->getTimestamp() - now()->addMinutes(15)->getTimestamp()))->toBeLessThan(5);
});

it('keeps vulnerability findings out of the AI resolution flow', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-head.json', 'composer-base.json');
    runVulnerabilityScan($pullRequest->id);

    app()->call([new CheckFindingResolutions($pullRequest->id, 'head-sha-2'), 'handle']);

    expect(PullRequestReviewFinding::query()->sole()->resolved_at)->toBeNull();
});

it('does not dispute a vulnerability finding when someone replies to its comment', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-head.json', 'composer-base.json');
    runVulnerabilityScan($pullRequest->id);

    $finding = PullRequestReviewFinding::query()->sole();

    $comment = PullRequestComment::query()->create([
        'pull_request_id' => $pullRequest->id,
        'pull_request_review_finding_id' => $finding->id,
        'provider_comment_id' => 556,
        'provider_in_reply_to_id' => 555,
        'comment_type' => PullRequestCommentType::ReviewComment->value,
        'author_login' => 'octocat',
        'author_type' => 'User',
        'body' => 'We do not use the vulnerable code path.',
        'is_pull_lens' => false,
        'provider_created_at' => now(),
    ]);

    fakeGitHubForVulnerabilityScan();

    app()->call([new DisputePullRequestFinding($comment->id), 'handle']);

    expect($finding->source)->toBe(FindingSource::Trivy)
        ->and($finding->fresh()->resolved_at)->toBeNull();
    Http::assertNothingSent();
});
