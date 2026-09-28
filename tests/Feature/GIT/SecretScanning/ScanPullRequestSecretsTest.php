<?php

use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSource;
use App\Enums\GIT\SecurityScanStatus;
use App\Jobs\GIT\ScanPullRequestSecrets;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\SecurityScan;
use App\Services\Git\SecretScanning\GitleaksFailed;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Fake gitleaks returning $hits for every scan.
 */
function fakeGitleaks(array $hits, bool $installed = true): void
{
    Process::fake(function (PendingProcess $process) use ($hits, $installed) {
        $command = (array) $process->command;

        if (in_array('version', $command, true)) {
            return $installed ? Process::result('8.28.0') : Process::result('', 'not found', 127);
        }

        file_put_contents($command[array_search('--report-path', $command, true) + 1], json_encode($hits));

        return Process::result('');
    });
}

/**
 * Fake every GitHub call the job makes; $patch is config/app.php's diff.
 *
 * Http::fake() appends stubs and the first match wins, so the factory is reset
 * first (which also clears recorded requests). $overrides are registered before
 * the defaults so they take precedence.
 */
function fakeGitHubForScan(string $patch, array $overrides = []): void
{
    app()->forgetInstance(Factory::class);
    Http::clearResolvedInstance(Factory::class);

    $base = 'api.github.com/repos/octocat/app';

    if ($overrides !== []) {
        Http::fake($overrides);
    }

    Http::fake([
        "{$base}/pulls/7/files*" => Http::response([['filename' => 'config/app.php', 'status' => 'modified', 'patch' => $patch]]),
        "{$base}/contents/*" => Http::response([], 404),
        "{$base}/check-runs/*" => Http::response([]),
        "{$base}/check-runs" => Http::response(['id' => 99], 201),
        "{$base}/pulls/7/reviews" => Http::response(['id' => 1], 200),
        "{$base}/pulls/7/comments" => Http::response(['id' => 555], 201),
        "{$base}/git/ref/notes/gitleaks" => Http::response([], 404),
        "{$base}/git/blobs" => Http::response(['sha' => 'blob1'], 201),
        "{$base}/git/trees" => Http::response(['sha' => 'tree1'], 201),
        "{$base}/git/commits" => Http::response(['sha' => 'notes1'], 201),
        "{$base}/git/refs" => Http::response([], 201),
    ]);
}

/**
 * Run the job synchronously for a pull request head.
 */
function runSecretScan(string $pullRequestId, string $headSha = 'head-sha-1'): void
{
    app()->call([new ScanPullRequestSecrets($pullRequestId, $headSha), 'handle']);
}

$leak = "@@ -1 +1,2 @@\n <?php\n+AWS_KEY=AKIAABCDEFGHIJKLMNOP";
$awsHit = fn (int $line = 2) => ['RuleID' => 'aws-access-token', 'Description' => 'AWS Access Key', 'File' => 'config/app.php', 'StartLine' => $line, 'Match' => 'AWS_KEY=REDACTED'];

it('records a redacted critical security finding for each hit', function () use ($leak, $awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);

    runSecretScan($pullRequest->id);

    $scan = SecurityScan::query()->sole();
    $finding = PullRequestReviewFinding::query()->sole();

    expect($scan->status)->toBe(SecurityScanStatus::Completed)
        ->and($scan->findings_count)->toBe(1)
        ->and($scan->check_run_id)->toBe(99)
        ->and($scan->notes_commit_sha)->toBe('notes1')
        ->and($scan->scanner_version)->toBe('8.28.0')
        ->and($finding->source)->toBe(FindingSource::Gitleaks)
        ->and($finding->security_scan_id)->toBe($scan->id)
        ->and($finding->pull_request_review_id)->toBeNull()
        ->and($finding->severity->value)->toBe('critical')
        ->and($finding->category->value)->toBe('security')
        ->and($finding->line)->toBe(2)
        ->and($finding->is_posted)->toBeTrue()
        ->and($finding->provider_comment_id)->toBe(555)
        ->and($finding->explanation)->toContain('REDACTED')
        ->and($finding->explanation)->not->toContain('AKIAABCDEFGHIJKLMNOP');
});

it('never sends the raw secret to GitHub', function () use ($leak, $awsHit) {
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    Http::assertNotSent(fn (Request $r) => str_contains($r->body(), 'AKIAABCDEFGHIJKLMNOP')
        || str_contains(base64_decode((string) ($r['content'] ?? '')), 'AKIAABCDEFGHIJKLMNOP'));
});

it('fails the secrets check with one annotation per finding', function () use ($leak, $awsHit) {
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/check-runs')
        && $r['name'] === 'PullLens / Secrets' && $r['head_sha'] === 'head-sha-1');
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/check-runs/99')
        && $r['conclusion'] === 'failure'
        && $r['output']['annotations'][0]['path'] === 'config/app.php'
        && $r['output']['annotations'][0]['start_line'] === 2
        && $r['output']['annotations'][0]['annotation_level'] === 'failure');
});

it('posts a comment review with an inline comment on the secret line', function () use ($leak, $awsHit) {
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls/7/reviews') && $r['event'] === 'COMMENT');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls/7/comments')
        && $r['path'] === 'config/app.php' && $r['line'] === 2 && $r['commit_id'] === 'head-sha-1');
});

it('writes a git note on the head commit', function () use ($leak, $awsHit) {
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/trees') && $r['tree'][0]['path'] === 'head-sha-1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/blobs')
        && str_contains(base64_decode($r['content']), 'config/app.php:2 aws-access-token'));
});

it('passes the check and writes no note or comments when the diff is clean', function () use ($leak) {
    fakeGitHubForScan($leak);
    fakeGitleaks([]);

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    expect(PullRequestReviewFinding::query()->count())->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['conclusion'] === 'success');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/git/') || str_contains($r->url(), '/pulls/7/reviews'));
});

it('does not comment twice when the secret only moved', function () use ($awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());

    fakeGitHubForScan("@@ -1 +1,2 @@\n <?php\n+AWS_KEY=AKIAABCDEFGHIJKLMNOP");
    fakeGitleaks([$awsHit(2)]);
    runSecretScan($pullRequest->id, 'head-sha-1');

    // Resetting the fake also clears the first run's recorded requests, so the
    // assertions below cover the second run only.
    $pullRequest->update(['head_sha' => 'head-sha-2']);
    fakeGitHubForScan("@@ -1 +1,3 @@\n <?php\n+// config\n+AWS_KEY=AKIAABCDEFGHIJKLMNOP");
    fakeGitleaks([$awsHit(3)]);
    runSecretScan($pullRequest->id, 'head-sha-2');

    $finding = PullRequestReviewFinding::query()->sole();
    $inlineComments = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/pulls/7/comments') && ! isset($r['in_reply_to']));

    expect($finding->resolved_at)->toBeNull()
        ->and($finding->line)->toBe(3)
        ->and($finding->is_posted)->toBeTrue()
        ->and($finding->provider_comment_id)->toBe(555)
        ->and($inlineComments)->toHaveCount(0);
});

it('resolves a secret that a later push took out of the diff and says to rotate it', function () use ($leak, $awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());

    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);
    runSecretScan($pullRequest->id, 'head-sha-1');

    $pullRequest->update(['head_sha' => 'head-sha-2']);
    fakeGitHubForScan("@@ -1 +1,2 @@\n <?php\n+AWS_KEY=env('AWS_KEY')");
    fakeGitleaks([]);
    runSecretScan($pullRequest->id, 'head-sha-2');

    $finding = PullRequestReviewFinding::query()->sole();

    expect($finding->resolved_at)->not->toBeNull()
        ->and($finding->resolution_type)->toBe(FindingResolutionType::SecretRemoved);
    Http::assertSent(fn (Request $r) => ($r['in_reply_to'] ?? null) === 555 && str_contains($r['body'], 'rotate'));
});

it('skips a head it already scanned', function () use ($leak, $awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);

    runSecretScan($pullRequest->id);
    runSecretScan($pullRequest->id);

    expect(SecurityScan::query()->count())->toBe(1);
});

it('skips without a check run when gitleaks is not installed', function () use ($leak) {
    fakeGitHubForScan($leak);
    fakeGitleaks([], installed: false);

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    expect(SecurityScan::query()->sole()->status)->toBe(SecurityScanStatus::Skipped);
    Http::assertNothingSent();
});

it('does nothing when the repository turned secret scanning off', function () use ($leak) {
    fakeGitHubForScan($leak);
    fakeGitleaks([]);

    runSecretScan(secretScanPullRequest(secretScanRepository(['secret_scanning_enabled' => false]))->id);

    expect(SecurityScan::query()->count())->toBe(0);
});

it('records a failure, marks the check neutral and rethrows when gitleaks breaks', function () use ($leak) {
    fakeGitHubForScan($leak);
    Process::fake(function (PendingProcess $process) {
        return in_array('version', (array) $process->command, true)
            ? Process::result('8.28.0')
            : Process::result('', 'bad config', 1);
    });

    expect(fn () => runSecretScan(secretScanPullRequest(secretScanRepository())->id))
        ->toThrow(GitleaksFailed::class);

    expect(SecurityScan::query()->sole()->status)->toBe(SecurityScanStatus::Failed);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/check-runs/99')
        && $r['conclusion'] === 'neutral');
});

it('still completes the scan when writing the note fails', function () use ($leak, $awsHit) {
    fakeGitHubForScan($leak, [
        'api.github.com/repos/octocat/app/git/*' => Http::response(['message' => 'forbidden'], 403),
    ]);
    fakeGitleaks([$awsHit()]);

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    expect(SecurityScan::query()->sole()->status)->toBe(SecurityScanStatus::Completed)
        ->and(SecurityScan::query()->sole()->notes_commit_sha)->toBeNull();
});

it('skips a head that is no longer the pull request head', function () use ($leak, $awsHit) {
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);

    runSecretScan(secretScanPullRequest(secretScanRepository(), ['head_sha' => 'head-sha-2'])->id, 'head-sha-1');

    expect(SecurityScan::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('runs one scan per pull request at a time', function () {
    $middleware = (new ScanPullRequestSecrets('pr-123', 'head-sha-1'))->middleware();

    $overlap = collect($middleware)->first(fn ($m) => $m instanceof WithoutOverlapping);

    expect($overlap)->not->toBeNull()
        ->and($overlap->key)->toBe('pr-123');
});

it('reuses the unfinished scan row when a head is retried', function () use ($leak, $awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());

    fakeGitHubForScan($leak, ['api.github.com/repos/octocat/app/check-runs' => Http::response([], 500)]);
    Process::fake(fn (PendingProcess $process) => in_array('version', (array) $process->command, true)
        ? Process::result('8.28.0')
        : Process::result('', 'bad config', 1));

    expect(fn () => runSecretScan($pullRequest->id))->toThrow(GitleaksFailed::class);

    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);
    runSecretScan($pullRequest->id);

    $scan = SecurityScan::query()->sole();

    expect($scan->status)->toBe(SecurityScanStatus::Completed)
        ->and($scan->error)->toBeNull()
        ->and($scan->check_run_id)->toBe(99);
});

it('marks a running scan failed and its check neutral when the job dies', function () use ($leak) {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    $scan = SecurityScan::query()->create([
        'pull_request_id' => $pullRequest->id,
        'git_repository_id' => $pullRequest->git_repository_id,
        'head_sha' => 'head-sha-1',
        'status' => SecurityScanStatus::Running,
        'check_run_id' => 99,
    ]);
    fakeGitHubForScan($leak);

    (new ScanPullRequestSecrets($pullRequest->id, 'head-sha-1'))->failed(new RuntimeException('boom'));

    expect($scan->fresh()->status)->toBe(SecurityScanStatus::Failed)
        ->and($scan->fresh()->error)->toBe('boom');
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/check-runs/99')
        && $r['conclusion'] === 'neutral');
});

it('keeps a secret open when a later scan had to skip its file', function () use ($leak, $awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());

    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);
    runSecretScan($pullRequest->id, 'head-sha-1');

    $pullRequest->update(['head_sha' => 'head-sha-2']);
    fakeGitHubForScan($leak, [
        'api.github.com/repos/octocat/app/pulls/7/files*' => Http::response([['filename' => 'config/app.php', 'status' => 'modified']]),
    ]);
    fakeGitleaks([]);
    runSecretScan($pullRequest->id, 'head-sha-2');

    $finding = PullRequestReviewFinding::query()->sole();

    expect($finding->resolved_at)->toBeNull()
        ->and(SecurityScan::query()->where('head_sha', 'head-sha-2')->sole()->files_skipped)->toBe(1);
    Http::assertNotSent(fn (Request $r) => isset($r['in_reply_to']));
});

it('retries for a window of time and stops after two real failures', function () {
    $job = new ScanPullRequestSecrets('pr-123', 'head-sha-1');

    expect($job->retryUntil()->getTimestamp())->toBeGreaterThanOrEqual(now()->addMinutes(15)->getTimestamp() - 5)
        ->and($job->retryUntil()->getTimestamp())->toBeLessThanOrEqual(now()->addMinutes(15)->getTimestamp() + 5)
        ->and($job->maxExceptions)->toBe(2)
        ->and(property_exists($job, 'tries'))->toBeFalse();
});

it('keeps a manually resolved secret resolved on the next scan', function (FindingResolutionType $type) use ($leak, $awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());

    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);
    runSecretScan($pullRequest->id, 'head-sha-1');

    PullRequestReviewFinding::query()->sole()->update(['resolved_at' => now(), 'resolution_type' => $type]);

    $pullRequest->update(['head_sha' => 'head-sha-2']);
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);
    runSecretScan($pullRequest->id, 'head-sha-2');

    $finding = PullRequestReviewFinding::query()->sole();

    expect($finding->resolution_type)->toBe($type)
        ->and(SecurityScan::query()->where('head_sha', 'head-sha-2')->sole()->findings_count)->toBe(0);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/pulls/7/comments') || str_ends_with($r->url(), '/pulls/7/reviews'));
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/check-runs/99')
        && $r['conclusion'] === 'success' && $r['output']['annotations'] === []);
})->with([
    'false positive' => [FindingResolutionType::FalsePositive],
    "won't fix" => [FindingResolutionType::WontFix],
    'acknowledged' => [FindingResolutionType::Acknowledged],
]);

it('reopens a secret marked fixed when the same secret is still in the diff', function (FindingResolutionType $type) use ($leak, $awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());

    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);
    runSecretScan($pullRequest->id, 'head-sha-1');

    PullRequestReviewFinding::query()->sole()->update(['resolved_at' => now(), 'resolution_type' => $type]);

    // Resetting the fake also clears the first run's recorded requests, so the
    // assertions below cover the second run only.
    $pullRequest->update(['head_sha' => 'head-sha-2']);
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);
    runSecretScan($pullRequest->id, 'head-sha-2');

    $findings = PullRequestReviewFinding::query()->get();
    $open = $findings->whereNull('resolved_at');

    expect($findings)->toHaveCount(2)
        ->and($open)->toHaveCount(1)
        ->and($open->first()->is_posted)->toBeTrue();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls/7/comments') && ! isset($r['in_reply_to']));
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/check-runs/99')
        && $r['conclusion'] === 'failure');
})->with([
    'fix submitted' => [FindingResolutionType::FixSubmitted],
    'fix confirmed' => [FindingResolutionType::FixConfirmed],
]);

it('opens a new finding when a secret that was removed comes back', function () use ($leak, $awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());

    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);
    runSecretScan($pullRequest->id, 'head-sha-1');

    $pullRequest->update(['head_sha' => 'head-sha-2']);
    fakeGitHubForScan("@@ -1 +1,2 @@\n <?php\n+AWS_KEY=env('AWS_KEY')");
    fakeGitleaks([]);
    runSecretScan($pullRequest->id, 'head-sha-2');

    $pullRequest->update(['head_sha' => 'head-sha-3']);
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);
    runSecretScan($pullRequest->id, 'head-sha-3');

    $findings = PullRequestReviewFinding::query()->get();
    $open = $findings->whereNull('resolved_at');

    expect($findings)->toHaveCount(2)
        ->and($open)->toHaveCount(1)
        ->and($open->first()->is_posted)->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['conclusion'] === 'failure');
});

it('resolves a secret whose file a later push deleted', function () use ($leak, $awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());

    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);
    runSecretScan($pullRequest->id, 'head-sha-1');

    $pullRequest->update(['head_sha' => 'head-sha-2']);
    fakeGitHubForScan($leak, [
        'api.github.com/repos/octocat/app/pulls/7/files*' => Http::response([['filename' => 'config/app.php', 'status' => 'removed', 'patch' => "@@ -1,2 +0,0 @@\n-<?php\n-AWS_KEY=AKIAABCDEFGHIJKLMNOP"]]),
    ]);
    fakeGitleaks([]);
    runSecretScan($pullRequest->id, 'head-sha-2');

    $finding = PullRequestReviewFinding::query()->sole();

    expect($finding->resolved_at)->not->toBeNull()
        ->and($finding->resolution_type)->toBe(FindingResolutionType::SecretRemoved);
    Http::assertSent(fn (Request $r) => ($r['in_reply_to'] ?? null) === 555 && str_contains($r['body'], 'rotate'));
});

it('still scans when the check run cannot be created', function () use ($leak, $awsHit) {
    fakeGitHubForScan($leak, ['api.github.com/repos/octocat/app/check-runs' => Http::failedConnection()]);
    fakeGitleaks([$awsHit()]);
    Log::spy();

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    $scan = SecurityScan::query()->sole();

    expect($scan->status)->toBe(SecurityScanStatus::Completed)
        ->and($scan->check_run_id)->toBeNull()
        ->and(PullRequestReviewFinding::query()->count())->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'secret_scan.check_run_create_failed')->once();
});

it('reuses the check run a failed attempt created when the head is retried', function () use ($leak, $awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());

    fakeGitHubForScan($leak);
    Process::fake(fn (PendingProcess $process) => in_array('version', (array) $process->command, true)
        ? Process::result('8.28.0')
        : Process::result('', 'bad config', 1));

    expect(fn () => runSecretScan($pullRequest->id))->toThrow(GitleaksFailed::class);

    fakeGitHubForScan($leak, ['api.github.com/repos/octocat/app/check-runs' => Http::response(['id' => 123], 201)]);
    fakeGitleaks([$awsHit()]);
    runSecretScan($pullRequest->id);

    expect(SecurityScan::query()->sole()->check_run_id)->toBe(99);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/check-runs'));
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/check-runs/99')
        && $r['conclusion'] === 'failure');
});
