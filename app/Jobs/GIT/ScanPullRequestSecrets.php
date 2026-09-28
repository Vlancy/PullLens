<?php

namespace App\Jobs\GIT;

use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\Scanner;
use App\Enums\GIT\SecurityScanStatus;
use App\Models\GIT\GitAccount;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\SecurityScan;
use App\Services\Git\GitHubCallerResolver;
use App\Services\Git\Scanning\GitHubNotesWriter;
use App\Services\Git\Scanning\ScanCheckRun;
use App\Services\Git\Scanning\ScanCommentPublisher;
use App\Services\Git\Scanning\ScanFindingRecorder;
use App\Services\Git\Scanning\ScanIssue;
use App\Services\Git\Scanning\ScanRunRecorder;
use App\Services\Git\SecretScanning\GitleaksRunner;
use App\Services\Git\SecretScanning\SecretHit;
use App\Services\Git\SecretScanning\SecretScanner;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scans a pull request head for leaked credentials with gitleaks and reports them.
 *
 * Independent of the AI review: it has its own check run, costs no AI tokens, and
 * runs even when reviews are off. Every output - finding, check annotation, inline
 * comment, git note - carries only gitleaks's redacted match.
 *
 * Scans of one pull request run one at a time and retry within a time window,
 * so a scan waiting behind another is delayed, never dropped.
 */
class ScanPullRequestSecrets implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * File listing, two config reads, one gitleaks run and the GitHub writes.
     * Held under the queue's retry_after so a slow run is never executed twice.
     */
    public int $timeout = 180;

    /**
     * Stop after two genuine failures. Waiting behind another scan of the same pull
     * request (a WithoutOverlapping release) is not an exception, so it never counts.
     */
    public int $maxExceptions = 2;

    public int $backoff = 30;

    /**
     * Release the lock after ten minutes even if the job dies without finishing.
     */
    public int $uniqueFor = 600;

    /**
     * Inject the pull request and head commit to scan.
     */
    public function __construct(
        public readonly string $pullRequestId,
        public readonly string $headSha,
    ) {}

    /**
     * One scan per pull request head may be queued at a time.
     */
    public function uniqueId(): string
    {
        return $this->pullRequestId.':'.$this->headSha;
    }

    /**
     * Bound retries by time instead of $tries: every WithoutOverlapping release uses
     * an attempt, so a count would fail a newer head's scan that merely waited its
     * turn. Laravel ignores $tries while this is set; $maxExceptions caps failures.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    /**
     * Run one scan per pull request at a time, so scans of quickly pushed heads
     * never read the same open findings and post the same secret twice.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->pullRequestId))->releaseAfter(30)->expireAfter(240)];
    }

    /**
     * Execute the secret scan.
     */
    public function handle(
        GitHubCallerResolver $callers,
        SecretScanner $scanner,
        GitleaksRunner $runner,
        GitHubNotesWriter $notes,
        ScanRunRecorder $runs,
        ScanCheckRun $checks,
        ScanFindingRecorder $recorder,
        ScanCommentPublisher $comments,
    ): void {
        $pullRequest = PullRequest::with('repository.account')->find($this->pullRequestId);

        if ($pullRequest === null || ! $pullRequest->repository->secret_scanning_enabled) {
            return;
        }

        // A newer head has its own scan queued; scanning this one could re-open
        // or re-comment a secret the newer scan already resolved.
        if (filled($pullRequest->head_sha) && $pullRequest->head_sha !== $this->headSha) {
            return;
        }

        if ($runs->alreadyCompleted($pullRequest->id, $this->headSha, Scanner::Gitleaks)) {
            return;
        }

        $repository = $pullRequest->repository;
        [$owner, $name] = explode('/', $repository->full_name, 2);

        $scan = $runs->start($pullRequest, $this->headSha, Scanner::Gitleaks);

        if (! $runner->isAvailable()) {
            $scan->update(['status' => SecurityScanStatus::Skipped, 'error' => 'gitleaks binary is not installed']);
            Log::warning(Scanner::Gitleaks->logPrefix().'.binary_missing', ['pull_request_id' => $pullRequest->id]);

            return;
        }

        $caller = $callers->for($repository);

        if ($caller === null) {
            $scan->update(['status' => SecurityScanStatus::Skipped, 'error' => 'No GitHub credential for this repository']);

            return;
        }

        $started = hrtime(true);
        $checkRunId = $checks->open($caller, $owner, $name, $this->headSha, $scan);
        $scan->update(['check_run_id' => $checkRunId, 'scanner_version' => $runner->version()]);

        try {
            $result = $scanner->scan($caller, $owner, $name, $pullRequest->number, (string) $pullRequest->target_branch);
        } catch (Throwable $e) {
            $scan->update([
                'status' => SecurityScanStatus::Failed,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'duration_ms' => $this->elapsedMs($started),
            ]);

            if ($checkRunId !== null) {
                $checks->complete($caller, $owner, $name, $checkRunId, Scanner::Gitleaks, 'neutral',
                    'Secret scan could not run', 'gitleaks failed: '.mb_substr($e->getMessage(), 0, 500), collect());
            }

            throw $e;
        }

        $findings = $recorder->record($pullRequest, $scan, array_map(fn (SecretHit $hit) => $this->issue($hit), $result->hits));
        $resolved = $recorder->resolveMissing($pullRequest, Scanner::Gitleaks, $findings->pluck('dedupe_key')->all(),
            $result->skippedPaths, FindingResolutionType::SecretRemoved);

        $scan->update([
            'status' => SecurityScanStatus::Completed,
            'findings_count' => $findings->count(),
            'files_scanned' => $result->filesScanned,
            'files_skipped' => $result->filesSkipped,
            'duration_ms' => $this->elapsedMs($started),
        ]);

        if ($checkRunId !== null) {
            $count = $findings->count();

            $checks->complete(
                $caller, $owner, $name, $checkRunId, Scanner::Gitleaks,
                $count > 0 ? 'failure' : 'success',
                $count > 0 ? "{$count} possible ".str('secret')->plural($count).' found' : 'No secrets found',
                $this->checkSummary($count, $result->filesScanned, $result->filesSkipped),
                $findings,
            );
        }

        $comments->publish($caller, $owner, $name, $pullRequest, $this->headSha, $findings,
            fn (Collection $unposted) => "**PullLens secret scan:** {$unposted->count()} possible ".str('secret')->plural($unposted->count()).' added in this pull request. '
                .'Rotate each credential - removing it from the code is not enough once it has been pushed.',
            Scanner::Gitleaks->logPrefix());

        $comments->replyResolved($caller, $owner, $name, $pullRequest, $resolved,
            'No longer in the diff, but still in this branch\'s git history - rotate this credential if you have not already.',
            Scanner::Gitleaks->logPrefix());

        if ($findings->isNotEmpty()) {
            $this->writeNote($notes, $caller, $owner, $name, $pullRequest, $scan, $findings, (string) $runner->version());
        }
    }

    /**
     * Close out a scan the job left running when it died, so its check never stays in progress.
     */
    public function failed(Throwable $e): void
    {
        $scan = app(ScanRunRecorder::class)->running($this->pullRequestId, $this->headSha, Scanner::Gitleaks);

        if ($scan === null) {
            return;
        }

        $scan->update(['status' => SecurityScanStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 2000)]);

        app(ScanCheckRun::class)->closeAfterCrash($scan, 'Secret scan could not run',
            'The secret scan stopped before finishing: '.mb_substr($e->getMessage(), 0, 500));
    }

    /**
     * Shape one redacted gitleaks hit as a finding.
     */
    private function issue(SecretHit $hit): ScanIssue
    {
        return new ScanIssue(
            dedupeKey: $hit->dedupeKey(),
            title: 'Secret detected: '.($hit->description !== '' ? $hit->description : $hit->ruleId),
            severity: FindingSeverity::Critical,
            file: $hit->file,
            line: $hit->line,
            explanation: "gitleaks rule `{$hit->ruleId}` matched `{$hit->match}` on an added line. "
                .'The value is now part of this branch\'s git history, so deleting the line does not un-leak it.',
            suggestedFix: 'Rotate or revoke this credential first, then remove it from the code and load it from '
                .'the environment or a secret store. If it is a test fixture, allowlist it in .gitleaks.toml on the target branch.',
            metadata: ['kind' => 'secret', 'rule_id' => $hit->ruleId],
        );
    }

    /**
     * Markdown summary shown on the check run.
     */
    private function checkSummary(int $count, int $scanned, int $skipped): string
    {
        $lines = [$count > 0
            ? "gitleaks found {$count} possible ".str('secret')->plural($count).' in the lines this pull request adds. '
                .'**Rotate them** - removing the line does not remove it from git history.'
            : 'gitleaks found no secrets in the lines this pull request adds.'];

        $lines[] = "Files scanned: {$scanned}".($skipped > 0 ? " - skipped (binary, removed or too large): {$skipped}" : '');

        return implode("\n\n", $lines);
    }

    /**
     * Attach the redacted findings to the head commit as a git note.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     */
    private function writeNote(GitHubNotesWriter $notes, GitAccount|string $caller, string $owner, string $name, PullRequest $pullRequest, SecurityScan $scan, Collection $findings, string $version): void
    {
        $lines = [
            "PullLens secret scan {$scan->id}",
            "gitleaks {$version} - ".now()->toIso8601String(),
            "{$findings->count()} possible ".str('secret')->plural($findings->count())." in pull request #{$pullRequest->number}:",
        ];

        foreach ($findings as $finding) {
            $rule = str($finding->dedupe_key)->after('gitleaks:')->before(':');
            $lines[] = "- {$finding->file}:{$finding->line} {$rule}";
        }

        try {
            $scan->update(['notes_commit_sha' => $notes->write($caller, $owner, $name, $this->headSha, implode("\n", $lines)."\n", Scanner::Gitleaks->notesRef())]);
        } catch (Throwable $e) {
            Log::warning(Scanner::Gitleaks->logPrefix().'.note_failed', ['security_scan_id' => $scan->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Milliseconds since $started (an hrtime value).
     */
    private function elapsedMs(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
