<?php

namespace App\Jobs\GIT;

use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\Scanner;
use App\Enums\GIT\SecurityScanStatus;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\SecurityScan;
use App\Services\Git\GitHubCallerResolver;
use App\Services\Git\Scanning\GitHubNotesWriter;
use App\Services\Git\Scanning\ScanCheckRun;
use App\Services\Git\Scanning\ScanCommentPublisher;
use App\Services\Git\Scanning\ScanFindingRecorder;
use App\Services\Git\Scanning\ScannerBinary;
use App\Services\Git\Scanning\ScanOutcome;
use App\Services\Git\Scanning\ScanRunRecorder;
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
 * Runs one security scanner against a pull request head and reports what it found.
 *
 * Everything the scanners share lives here: the stale-head and already-scanned
 * guards, the security_scans row, the check run, recording and resolving findings,
 * the review comments, the git note and the concurrency contract. A subclass only
 * says how to run its tool and how to word its results.
 *
 * Scans of one pull request by one scanner run one at a time and retry within a
 * time window, so a scan waiting behind another is delayed, never dropped.
 */
abstract class SecurityScanJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * File listing, the file reads, the tool's runs and the GitHub writes.
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
        return $this->lockKey().':'.$this->headSha;
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
     * never read the same open findings and post the same problem twice.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->lockKey()))->releaseAfter(30)->expireAfter(240)];
    }

    /**
     * Execute the scan.
     */
    public function handle(
        GitHubCallerResolver $callers,
        GitHubNotesWriter $notes,
        ScanRunRecorder $runs,
        ScanCheckRun $checks,
        ScanFindingRecorder $recorder,
        ScanCommentPublisher $comments,
    ): void {
        $scanner = $this->scanner();
        $pullRequest = PullRequest::with('repository.account')->find($this->pullRequestId);

        if ($pullRequest === null || ! $this->isEnabled($pullRequest->repository)) {
            return;
        }

        // A newer head has its own scan queued; scanning this one could re-open
        // or re-comment a problem the newer scan already resolved.
        if (filled($pullRequest->head_sha) && $pullRequest->head_sha !== $this->headSha) {
            return;
        }

        if ($runs->alreadyCompleted($pullRequest->id, $this->headSha, $scanner)) {
            return;
        }

        $repository = $pullRequest->repository;
        [$owner, $name] = explode('/', $repository->full_name, 2);

        $scan = $runs->start($pullRequest, $this->headSha, $scanner);
        $binary = $this->binary();

        if (! $binary->isAvailable()) {
            $scan->update(['status' => SecurityScanStatus::Skipped, 'error' => "{$scanner->value} binary is not installed"]);
            Log::warning($scanner->logPrefix().'.binary_missing', ['pull_request_id' => $pullRequest->id]);

            return;
        }

        $caller = $callers->for($repository);

        if ($caller === null) {
            $scan->update(['status' => SecurityScanStatus::Skipped, 'error' => 'No GitHub credential for this repository']);

            return;
        }

        $started = hrtime(true);
        $checkRunId = $checks->open($caller, $owner, $name, $this->headSha, $scan);
        $scan->update(['check_run_id' => $checkRunId, 'scanner_version' => $binary->version()]);

        $blocked = $this->blockedReason($pullRequest);

        if ($blocked !== null) {
            $scan->update(['status' => SecurityScanStatus::Skipped, 'error' => $blocked, 'duration_ms' => $this->elapsedMs($started)]);

            if ($checkRunId !== null) {
                $checks->complete($caller, $owner, $name, $checkRunId, $scanner, 'neutral',
                    ucfirst($this->scanName()).' skipped', $blocked, collect());
            }

            return;
        }

        try {
            $outcome = $this->runScan($caller, $owner, $name, $pullRequest);
        } catch (Throwable $e) {
            $scan->update([
                'status' => SecurityScanStatus::Failed,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'duration_ms' => $this->elapsedMs($started),
            ]);

            if ($checkRunId !== null) {
                $checks->complete($caller, $owner, $name, $checkRunId, $scanner, 'neutral',
                    ucfirst($this->scanName()).' could not run', $this->toolName().' failed: '.mb_substr($e->getMessage(), 0, 500), collect());
            }

            throw $e;
        }

        $findings = $recorder->record($pullRequest, $scan, $outcome->issues);
        $resolved = $recorder->resolveMissing($pullRequest, $scanner, $findings->pluck('dedupe_key')->all(),
            [...$outcome->skippedPaths, ...$outcome->limitedPaths], $this->resolution());

        $scan->update([
            'status' => SecurityScanStatus::Completed,
            'findings_count' => $findings->count(),
            'files_scanned' => $outcome->filesScanned,
            'files_skipped' => $outcome->filesSkipped,
            'duration_ms' => $this->elapsedMs($started),
        ]);

        if ($checkRunId !== null) {
            [$conclusion, $title] = $this->verdict($findings, $outcome);
            $summary = implode("\n\n", [$this->checkSummary($findings, $outcome), ...$outcome->notes]);

            $checks->complete($caller, $owner, $name, $checkRunId, $scanner, $conclusion, $title, $summary, $findings);
        }

        $comments->publish($caller, $owner, $name, $pullRequest, $this->headSha, $findings,
            fn (Collection $unposted) => $this->reviewBody($unposted), $scanner->logPrefix(), $this->inlineCommentLimit());

        $comments->replyResolved($caller, $owner, $name, $pullRequest, $resolved, $this->resolvedReply(), $scanner->logPrefix());

        if ($findings->isNotEmpty()) {
            $this->writeNote($notes, $caller, $owner, $name, $pullRequest, $scan, $findings, (string) $binary->version());
        }
    }

    /**
     * Close out a scan the job left running when it died, so its check never stays in progress.
     */
    public function failed(Throwable $e): void
    {
        $scan = app(ScanRunRecorder::class)->running($this->pullRequestId, $this->headSha, $this->scanner());

        if ($scan === null) {
            return;
        }

        $scan->update(['status' => SecurityScanStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 2000)]);

        app(ScanCheckRun::class)->closeAfterCrash($scan, ucfirst($this->scanName()).' could not run',
            "The {$this->scanName()} stopped before finishing: ".mb_substr($e->getMessage(), 0, 500));
    }

    /**
     * The scanner this job runs.
     */
    abstract protected function scanner(): Scanner;

    /**
     * The overlap lock shared by every scan of this pull request by this scanner.
     */
    abstract protected function lockKey(): string;

    /**
     * Whether the repository wants this scan.
     */
    abstract protected function isEnabled(GitRepository $repository): bool;

    /**
     * The command-line tool this scan runs.
     */
    abstract protected function binary(): ScannerBinary;

    /**
     * The scan's name in prose, such as "secret scan".
     */
    abstract protected function scanName(): string;

    /**
     * The tool's name as shown when it fails, such as "gitleaks".
     */
    abstract protected function toolName(): string;

    /**
     * Why the scan cannot run right now although the tool is installed, or null when it can.
     */
    protected function blockedReason(PullRequest $pullRequest): ?string
    {
        return null;
    }

    /**
     * Most inline comments one scan posts; the rest are named in the summary review only. Null means no limit.
     */
    protected function inlineCommentLimit(): ?int
    {
        return null;
    }

    /**
     * Run the tool over the pull request and shape what it found.
     */
    abstract protected function runScan(GitAccount|string $caller, string $owner, string $name, PullRequest $pullRequest): ScanOutcome;

    /**
     * How a finding a later scan no longer sees is resolved.
     */
    abstract protected function resolution(): FindingResolutionType;

    /**
     * The check conclusion and title for this scan.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     * @return array{0: string, 1: string}
     */
    abstract protected function verdict(Collection $findings, ScanOutcome $outcome): array;

    /**
     * Markdown summary shown on the check run.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     */
    abstract protected function checkSummary(Collection $findings, ScanOutcome $outcome): string;

    /**
     * Body of the summary review listing the findings not yet posted.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $unposted
     */
    abstract protected function reviewBody(Collection $unposted): string;

    /**
     * Reply posted in the thread of a finding a later scan resolved.
     */
    abstract protected function resolvedReply(): string;

    /**
     * The git note's lines after its header: a count, then one line per finding.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     * @return list<string>
     */
    abstract protected function noteLines(PullRequest $pullRequest, Collection $findings): array;

    /**
     * Attach the findings to the head commit as a git note.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     */
    private function writeNote(GitHubNotesWriter $notes, GitAccount|string $caller, string $owner, string $name, PullRequest $pullRequest, SecurityScan $scan, Collection $findings, string $version): void
    {
        $scanner = $this->scanner();

        $lines = [
            "PullLens {$this->scanName()} {$scan->id}",
            "{$scanner->value} {$version} - ".now()->toIso8601String(),
            ...$this->noteLines($pullRequest, $findings),
        ];

        try {
            $scan->update(['notes_commit_sha' => $notes->write($caller, $owner, $name, $this->headSha, implode("\n", $lines)."\n", $scanner->notesRef())]);
        } catch (Throwable $e) {
            Log::warning($scanner->logPrefix().'.note_failed', ['security_scan_id' => $scan->id, 'error' => $e->getMessage()]);
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
