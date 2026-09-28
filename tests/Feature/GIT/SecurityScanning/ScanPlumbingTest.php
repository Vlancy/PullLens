<?php

use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\FindingSource;
use App\Enums\GIT\Scanner;
use App\Enums\GIT\SecurityScanStatus;
use App\Models\GIT\PullRequestReviewFinding;
use App\Services\Git\Scanning\GitHubNotesWriter;
use App\Services\Git\Scanning\ScanCheckRun;
use App\Services\Git\Scanning\ScanCommentPublisher;
use App\Services\Git\Scanning\ScanFindingRecorder;
use App\Services\Git\Scanning\ScanIssue;
use App\Services\Git\Scanning\ScanRunRecorder;
use App\Services\Git\Scanning\ScanWorkspaceSweeper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function plumbingIssue(string $key = 'trivy:vuln:composer.lock:guzzlehttp/psr7:1.8.3:CVE-2022-24775', ?int $line = 130, FindingSeverity $severity = FindingSeverity::High): ScanIssue
{
    return new ScanIssue(
        dedupeKey: $key,
        title: 'CVE-2022-24775 in guzzlehttp/psr7@1.8.3',
        severity: $severity,
        file: 'composer.lock',
        line: $line,
        explanation: 'Improper Input Validation in guzzlehttp/psr7',
        suggestedFix: 'Upgrade guzzlehttp/psr7 to 1.8.4 or later.',
        metadata: ['kind' => 'vulnerability', 'rule_id' => 'CVE-2022-24775'],
    );
}

it('records a trivy finding with its metadata and refreshes it on the next scan', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    $recorder = app(ScanFindingRecorder::class);

    $first = $recorder->record($pullRequest, securityScan($pullRequest, Scanner::Trivy), [plumbingIssue()]);
    $second = $recorder->record($pullRequest, $scan = securityScan($pullRequest, Scanner::Trivy, ['head_sha' => 'head-sha-2']), [plumbingIssue(line: 140)]);

    $finding = PullRequestReviewFinding::query()->sole();

    expect($first->first()->id)->toBe($second->first()->id)
        ->and($finding->source)->toBe(FindingSource::Trivy)
        ->and($finding->category->value)->toBe('security')
        ->and($finding->confidence)->toBe(1.0)
        ->and($finding->line)->toBe(140)
        ->and($finding->security_scan_id)->toBe($scan->id)
        ->and($finding->metadata)->toBe(['kind' => 'vulnerability', 'rule_id' => 'CVE-2022-24775']);
});

it('keeps a dismissed finding resolved but reopens one fixed in a later push', function (FindingResolutionType $type, int $open) {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    $recorder = app(ScanFindingRecorder::class);

    $recorder->record($pullRequest, securityScan($pullRequest, Scanner::Trivy), [plumbingIssue()]);
    PullRequestReviewFinding::query()->sole()->update(['resolved_at' => now(), 'resolution_type' => $type]);

    $again = $recorder->record($pullRequest, securityScan($pullRequest, Scanner::Trivy, ['head_sha' => 'head-sha-2']), [plumbingIssue()]);

    expect($again)->toHaveCount($open)
        ->and(PullRequestReviewFinding::query()->whereNull('resolved_at')->count())->toBe($open);
})->with([
    'false positive stays dismissed' => [FindingResolutionType::FalsePositive, 0],
    'fixed in a later push reopens' => [FindingResolutionType::FixedInLaterPush, 1],
]);

it('resolves findings the scan no longer sees, except in skipped files', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    $recorder = app(ScanFindingRecorder::class);
    $recorder->record($pullRequest, securityScan($pullRequest, Scanner::Trivy), [
        plumbingIssue('trivy:vuln:composer.lock:a:1:CVE-1'),
        new ScanIssue('trivy:config:Dockerfile:DS-0002:-', 'root user', FindingSeverity::High, 'Dockerfile', null, 'm', 'f', ['kind' => 'misconfiguration']),
    ]);

    $resolved = $recorder->resolveMissing($pullRequest, Scanner::Trivy, [], ['Dockerfile'], FindingResolutionType::FixedInLaterPush);

    expect($resolved)->toHaveCount(1)
        ->and($resolved->first()->file)->toBe('composer.lock')
        ->and($resolved->first()->fresh()->resolution_type)->toBe(FindingResolutionType::FixedInLaterPush);
});

it('lists findings without a line in the summary review only and marks them posted', function () {
    Http::fake([
        'api.github.com/repos/octocat/app/pulls/7/reviews' => Http::response(['id' => 1]),
        'api.github.com/repos/octocat/app/pulls/7/comments' => Http::response(['id' => 777], 201),
    ]);
    $pullRequest = secretScanPullRequest(secretScanRepository());
    $findings = app(ScanFindingRecorder::class)->record($pullRequest, securityScan($pullRequest, Scanner::Trivy), [
        plumbingIssue(),
        new ScanIssue('trivy:config:Dockerfile:DS-0002:-', "Image user should not be 'root'", FindingSeverity::High, 'Dockerfile', null, 'm', 'f', ['kind' => 'misconfiguration']),
    ]);

    app(ScanCommentPublisher::class)->publish('token', 'octocat', 'app', $pullRequest, 'head-sha-1', $findings,
        fn ($unposted) => 'summary of '.$unposted->count(), 'vulnerability_scan');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls/7/reviews') && $r['body'] === 'summary of 2' && $r['event'] === 'COMMENT');
    Http::assertSentCount(2);
    expect(PullRequestReviewFinding::query()->where('is_posted', true)->count())->toBe(2)
        ->and(PullRequestReviewFinding::query()->where('file', 'Dockerfile')->sole()->provider_comment_id)->toBeNull();
});

it('annotates only findings with a line, as warnings below high severity', function () {
    Http::fake(['api.github.com/repos/octocat/app/check-runs/99' => Http::response([])]);
    $pullRequest = secretScanPullRequest(secretScanRepository());
    $findings = app(ScanFindingRecorder::class)->record($pullRequest, securityScan($pullRequest, Scanner::Trivy), [
        plumbingIssue('trivy:vuln:composer.lock:a:1:CVE-1', 130, FindingSeverity::Medium),
        plumbingIssue('trivy:vuln:composer.lock:b:1:CVE-2', 140, FindingSeverity::Critical),
        plumbingIssue('trivy:config:Dockerfile:DS-0002:-', null),
    ]);

    app(ScanCheckRun::class)->complete('token', 'octocat', 'app', 99, Scanner::Trivy, 'failure', 't', 's', $findings);

    Http::assertSent(function (Request $r) {
        $levels = collect($r['output']['annotations'])->pluck('annotation_level', 'start_line')->all();

        return $levels === [130 => 'warning', 140 => 'failure'];
    });
});

it('opens the scanner check once and reuses it on a retry', function () {
    Http::fake(['api.github.com/repos/octocat/app/check-runs' => Http::response(['id' => 99], 201)]);
    $pullRequest = secretScanPullRequest(secretScanRepository());
    $scan = securityScan($pullRequest, Scanner::Trivy);

    $first = app(ScanCheckRun::class)->open('token', 'octocat', 'app', 'head-sha-1', $scan);
    $scan->update(['check_run_id' => $first]);
    $second = app(ScanCheckRun::class)->open('token', 'octocat', 'app', 'head-sha-1', $scan);

    expect($first)->toBe(99)->and($second)->toBe(99);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r['name'] === 'PullLens / Vulnerabilities');
});

it('keeps each scanner runs apart', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    securityScan($pullRequest, Scanner::Gitleaks, ['status' => SecurityScanStatus::Completed]);
    $runs = app(ScanRunRecorder::class);

    expect($runs->alreadyCompleted($pullRequest->id, 'head-sha-1', Scanner::Gitleaks))->toBeTrue()
        ->and($runs->alreadyCompleted($pullRequest->id, 'head-sha-1', Scanner::Trivy))->toBeFalse()
        ->and($runs->start($pullRequest, 'head-sha-1', Scanner::Trivy)->scanner)->toBe(Scanner::Trivy);
});

it('writes git notes under the ref it is given', function () {
    $base = 'api.github.com/repos/octocat/app/git';
    Http::fake([
        "{$base}/ref/notes/trivy" => Http::response([], 404),
        "{$base}/blobs" => Http::response(['sha' => 'blob1'], 201),
        "{$base}/trees" => Http::response(['sha' => 'tree1'], 201),
        "{$base}/commits" => Http::response(['sha' => 'notes1'], 201),
        "{$base}/refs" => Http::response([], 201),
    ]);

    app(GitHubNotesWriter::class)->write('token', 'octocat', 'app', 'head-sha-1', 'note', 'notes/trivy');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/commits') && $r['message'] === 'trivy: notes for head-sha-1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/refs') && $r['ref'] === 'refs/notes/trivy');
});

it('sweeps stale workspaces under its own root only', function () {
    $root = storage_path('framework/testing/sweeper-'.uniqid());
    $sweeper = new class($root) extends ScanWorkspaceSweeper
    {
        public function __construct(private readonly string $path) {}

        public function root(): string
        {
            return $this->path;
        }
    };
    mkdir($root.'/stale', 0777, true);
    mkdir($root.'/fresh', 0777, true);
    touch($root.'/stale', time() - 16 * 60);

    try {
        expect($sweeper->sweep())->toBe(1)
            ->and(is_dir($root.'/stale'))->toBeFalse()
            ->and(is_dir($root.'/fresh'))->toBeTrue();
    } finally {
        @rmdir($root.'/fresh');
        @rmdir($root);
    }
});
