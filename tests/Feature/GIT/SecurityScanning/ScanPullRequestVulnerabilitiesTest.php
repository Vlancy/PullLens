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
use App\Services\Git\VulnerabilityScanning\TrivyScanner;
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
 * Fake trivy answering the head and base runs of the scan job with fixtures (a file name, or the report JSON itself).
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
        $report = str_starts_with($fixture, '{') ? $fixture : file_get_contents(base_path("tests/Fixtures/Trivy/{$fixture}"));
        file_put_contents($command[array_search('--output', $command, true) + 1], $report);

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
 * A trivy JSON report of one file with the given vulnerabilities; each needs at least a package name.
 */
function trivyVulnerabilityReport(string $target, array $vulnerabilities): string
{
    $packages = [];
    $results = [];

    foreach (array_values($vulnerabilities) as $i => $vulnerability) {
        $vulnerability += [
            'VulnerabilityID' => 'CVE-2024-'.(1000 + $i), 'InstalledVersion' => '1.0.0', 'FixedVersion' => '1.0.1',
            'PrimaryURL' => 'https://avd.aquasec.com/nvd/cve-2024-'.(1000 + $i), 'Title' => 'Advisory '.$i, 'Severity' => 'HIGH',
        ];
        $vulnerability['PkgID'] = "{$vulnerability['PkgName']}@{$vulnerability['InstalledVersion']}";
        $packages[] = ['ID' => $vulnerability['PkgID'], 'Name' => $vulnerability['PkgName'], 'Version' => $vulnerability['InstalledVersion'], 'Locations' => [['StartLine' => 10 + $i * 10]]];
        $results[] = $vulnerability;
    }

    return json_encode(['SchemaVersion' => 2, 'Results' => [['Target' => $target, 'Class' => 'lang-pkgs', 'Type' => 'composer', 'Packages' => $packages, 'Vulnerabilities' => $results]]]);
}

/**
 * The summary and title of the check run the scan completed.
 *
 * @return array{title: string, summary: string, conclusion: string}
 */
function completedVulnerabilityCheck(): array
{
    $request = collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->last(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/check-runs/99'));

    return ['title' => $request['output']['title'], 'summary' => $request['output']['summary'], 'conclusion' => $request['conclusion']];
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

it('is neutral and names the file when nothing could be read', function () {
    fakeGitHubForVulnerabilityScan(overrides: ['api.github.com/repos/octocat/app/contents/*' => Http::response(['message' => 'Server Error'], 500)]);
    fakeTrivyForJob('composer-head.json');

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository())->id);

    expect(completedVulnerabilityCheck())
        ->conclusion->toBe('neutral')
        ->title->toBe('1 file could not be read')
        ->summary->toContain('`composer.lock`');
});

it('is neutral and lists the unread files when the rest brought nothing new', function () {
    fakeGitHubForVulnerabilityScan(
        [['filename' => 'composer.lock', 'status' => 'modified'], ['filename' => 'Dockerfile', 'status' => 'added']],
        ['api.github.com/repos/octocat/app/contents/composer.lock*' => Http::response(['message' => 'Server Error'], 500)],
    );
    fakeTrivyForJob('empty.json');

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository())->id);

    expect(completedVulnerabilityCheck())
        ->conclusion->toBe('neutral')
        ->title->toBe('1 file could not be read')
        ->summary->toContain('`composer.lock`')->not->toContain('`Dockerfile`');
});

it('still fails when a file could not be read but others brought a high problem', function () {
    fakeGitHubForVulnerabilityScan(
        [['filename' => 'composer.lock', 'status' => 'modified'], ['filename' => 'Dockerfile', 'status' => 'added']],
        ['api.github.com/repos/octocat/app/contents/composer.lock*' => Http::response(['message' => 'Server Error'], 500)],
    );
    fakeTrivyForJob('dockerfile.json');

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository())->id);

    expect(completedVulnerabilityCheck())
        ->conclusion->toBe('failure')
        ->summary->toContain('`composer.lock`');
});

it('lists at most twenty unread files and counts the rest', function () {
    $files = array_map(fn (int $i) => ['filename' => "svc{$i}/Dockerfile", 'status' => 'modified'], range(1, 22));
    fakeGitHubForVulnerabilityScan($files, ['api.github.com/repos/octocat/app/contents/*' => Http::response(['message' => 'Server Error'], 500)]);
    fakeTrivyForJob('empty.json');

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository())->id);

    $check = completedVulnerabilityCheck();

    expect($check['title'])->toBe('22 files could not be read')
        ->and($check['summary'])->toContain('`svc20/Dockerfile`')->not->toContain('`svc21/Dockerfile`')
        ->and($check['summary'])->toContain('and 2 more');
});

it('keeps an earlier finding open while its file cannot be read', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    fakeGitHubForVulnerabilityScan();
    fakeTrivyForJob('composer-head.json', 'composer-base.json');
    runVulnerabilityScan($pullRequest->id, 'head-sha-1');

    $pullRequest->update(['head_sha' => 'head-sha-2']);
    fakeGitHubForVulnerabilityScan(overrides: ['api.github.com/repos/octocat/app/contents/*' => Http::response(['message' => 'Server Error'], 500)]);
    fakeTrivyForJob('empty.json');
    runVulnerabilityScan($pullRequest->id, 'head-sha-2');

    expect(PullRequestReviewFinding::query()->sole()->resolved_at)->toBeNull();
    Http::assertNotSent(fn (Request $r) => isset($r['in_reply_to']));
});

it('scans at most the file limit, says so, and leaves the rest of the findings open', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    $limit = TrivyScanner::MAX_TARGETS;
    $last = 'svc'.($limit + 1).'/Dockerfile';
    $earlier = gitleaksFinding($pullRequest, ['source' => 'trivy', 'dedupe_key' => "trivy:config:{$last}:DS-0001:from alpine", 'file' => $last]);
    $files = array_map(fn (int $i) => ['filename' => "svc{$i}/Dockerfile", 'status' => 'added'], range(1, $limit + 1));
    fakeGitHubForVulnerabilityScan($files);
    fakeTrivyForJob('empty.json');

    runVulnerabilityScan($pullRequest->id);

    expect(completedVulnerabilityCheck())
        ->conclusion->toBe('neutral')
        ->title->toBe('1 file not scanned: over the 100-file limit')
        ->summary->toContain("Only the first {$limit} of ".($limit + 1).' dependency and infrastructure files were scanned');
    expect($earlier->fresh()->resolved_at)->toBeNull();
});

it('comments inline on at most twenty problems and lists the rest in the summary', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    fakeGitHubForVulnerabilityScan([['filename' => 'composer.lock', 'status' => 'added']]);
    fakeTrivyForJob(trivyVulnerabilityReport('composer.lock', array_map(fn (int $i) => ['PkgName' => "vendor/pkg{$i}"], range(1, 22))));

    runVulnerabilityScan($pullRequest->id);

    $findings = PullRequestReviewFinding::query()->get();

    expect($findings)->toHaveCount(22)
        ->and($findings->every(fn ($f) => $f->is_posted))->toBeTrue()
        ->and($findings->whereNotNull('provider_comment_id'))->toHaveCount(ScanPullRequestVulnerabilities::INLINE_COMMENT_LIMIT)
        ->and(collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/pulls/7/comments')))->toHaveCount(20);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls/7/reviews')
        && substr_count($r['body'], '- **HIGH**') === 22 && str_contains($r['body'], 'Inline comments are limited to 20'));
});

it('keeps an advisory link only when it is a web address', function () {
    fakeGitHubForVulnerabilityScan([['filename' => 'composer.lock', 'status' => 'added']]);
    fakeTrivyForJob(trivyVulnerabilityReport('composer.lock', [['PkgName' => 'vendor/evil', 'PrimaryURL' => 'javascript:alert(1)']]));

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository())->id);

    $finding = PullRequestReviewFinding::query()->sole();

    expect($finding->metadata)->not->toHaveKey('url')
        ->and($finding->explanation)->not->toContain('javascript:');
});

it('hashes an identity too long for the dedupe key, stably', function () {
    $path = str_repeat('very-long-directory-name/', 10).'composer.lock';
    $pullRequest = secretScanPullRequest(secretScanRepository());
    fakeGitHubForVulnerabilityScan([['filename' => $path, 'status' => 'added']]);
    fakeTrivyForJob(trivyVulnerabilityReport($path, [['PkgName' => 'vendor/pkg', 'VulnerabilityID' => 'CVE-2024-1']]));
    runVulnerabilityScan($pullRequest->id, 'head-sha-1');

    $identity = "vuln:{$path}:vendor/pkg:1.0.0:CVE-2024-1";

    expect(PullRequestReviewFinding::query()->sole()->dedupe_key)->toBe('trivy:'.sha1($identity));

    $pullRequest->update(['head_sha' => 'head-sha-2']);
    fakeGitHubForVulnerabilityScan([['filename' => $path, 'status' => 'added']]);
    fakeTrivyForJob(trivyVulnerabilityReport($path, [['PkgName' => 'vendor/pkg', 'VulnerabilityID' => 'CVE-2024-1']]));
    runVulnerabilityScan($pullRequest->id, 'head-sha-2');

    expect(PullRequestReviewFinding::query()->sole()->resolved_at)->toBeNull();
});

it('refreshes an open problem with the current advisory data on a rescan', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    fakeGitHubForVulnerabilityScan([['filename' => 'composer.lock', 'status' => 'added']]);
    fakeTrivyForJob(trivyVulnerabilityReport('composer.lock', [['PkgName' => 'vendor/pkg', 'Severity' => 'MEDIUM', 'Title' => 'Old title', 'FixedVersion' => '']]));
    runVulnerabilityScan($pullRequest->id, 'head-sha-1');

    $pullRequest->update(['head_sha' => 'head-sha-2']);
    fakeGitHubForVulnerabilityScan([['filename' => 'composer.lock', 'status' => 'added']]);
    fakeTrivyForJob(trivyVulnerabilityReport('composer.lock', [['PkgName' => 'vendor/pkg', 'Severity' => 'CRITICAL', 'Title' => 'New title', 'FixedVersion' => '1.0.1']]));
    runVulnerabilityScan($pullRequest->id, 'head-sha-2');

    $finding = PullRequestReviewFinding::query()->sole();

    expect($finding->severity->value)->toBe('critical')
        ->and($finding->explanation)->toContain('New title')
        ->and($finding->suggested_fix)->toBe('Upgrade vendor/pkg to 1.0.1 or later.')
        ->and(completedVulnerabilityCheck()['conclusion'])->toBe('failure');
});

it('asks for no merge base when no scannable file changed', function () {
    fakeGitHubForVulnerabilityScan([['filename' => 'app/Models/User.php', 'status' => 'modified']]);
    fakeTrivyForJob('composer-head.json');

    runVulnerabilityScan(secretScanPullRequest(secretScanRepository())->id);

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/compare/'));
});
