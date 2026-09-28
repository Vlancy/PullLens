<?php

namespace App\Jobs\GIT;

use App\Enums\GIT\FindingCategory;
use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\FindingSource;
use App\Enums\GIT\SecretScanStatus;
use App\Models\GIT\GitAccount;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\SecretScan;
use App\Services\Git\GitHubApiClient;
use App\Services\Git\GitHubCallerResolver;
use App\Services\Git\SecretScanning\GitHubNotesWriter;
use App\Services\Git\SecretScanning\GitleaksRunner;
use App\Services\Git\SecretScanning\SecretHit;
use App\Services\Git\SecretScanning\SecretScanner;
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
 */
class ScanPullRequestSecrets implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHECK_NAME = 'PullLens / Secrets';

    /**
     * File listing, two config reads, one gitleaks run and the GitHub writes.
     * Held under the queue's retry_after so a slow run is never executed twice.
     */
    public int $timeout = 180;

    public int $tries = 2;

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
        GitHubApiClient $api,
        GitHubCallerResolver $callers,
        SecretScanner $scanner,
        GitleaksRunner $runner,
        GitHubNotesWriter $notes,
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

        $alreadyScanned = SecretScan::query()
            ->where('pull_request_id', $pullRequest->id)
            ->where('head_sha', $this->headSha)
            ->where('status', SecretScanStatus::Completed->value)
            ->exists();

        if ($alreadyScanned) {
            return;
        }

        $repository = $pullRequest->repository;
        [$owner, $name] = explode('/', $repository->full_name, 2);

        $scan = $this->startScan($pullRequest);

        if (! $runner->isAvailable()) {
            $scan->update(['status' => SecretScanStatus::Skipped, 'error' => 'gitleaks binary is not installed']);
            Log::warning('secret_scan.binary_missing', ['pull_request_id' => $pullRequest->id]);

            return;
        }

        $caller = $callers->for($repository);

        if ($caller === null) {
            $scan->update(['status' => SecretScanStatus::Skipped, 'error' => 'No GitHub credential for this repository']);

            return;
        }

        $started = hrtime(true);
        $checkRunId = (int) data_get($api->createCheckRun($caller, $owner, $name, $this->headSha, self::CHECK_NAME), 'id') ?: null;
        $scan->update(['check_run_id' => $checkRunId, 'gitleaks_version' => $runner->version()]);

        try {
            $result = $scanner->scan($caller, $owner, $name, $pullRequest->number, (string) $pullRequest->target_branch);
        } catch (Throwable $e) {
            $scan->update([
                'status' => SecretScanStatus::Failed,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'duration_ms' => $this->elapsedMs($started),
            ]);

            if ($checkRunId !== null) {
                $this->completeCheckRun($api, $caller, $owner, $name, $checkRunId, 'neutral',
                    'Secret scan could not run', 'gitleaks failed: '.mb_substr($e->getMessage(), 0, 500), collect());
            }

            throw $e;
        }

        $findings = $this->recordFindings($pullRequest, $scan, $result->hits);
        $resolved = $this->resolveRemoved($pullRequest, $findings->pluck('dedupe_key')->all(), $result->skippedPaths);

        $scan->update([
            'status' => SecretScanStatus::Completed,
            'findings_count' => $findings->count(),
            'files_scanned' => $result->filesScanned,
            'files_skipped' => $result->filesSkipped,
            'duration_ms' => $this->elapsedMs($started),
        ]);

        if ($checkRunId !== null) {
            $count = $findings->count();

            $this->completeCheckRun(
                $api, $caller, $owner, $name, $checkRunId,
                $count > 0 ? 'failure' : 'success',
                $count > 0 ? "{$count} possible ".str('secret')->plural($count).' found' : 'No secrets found',
                $this->checkSummary($count, $result->filesScanned, $result->filesSkipped),
                $findings,
            );
        }

        $this->postComments($api, $caller, $owner, $name, $pullRequest, $findings);
        $this->replyToResolved($api, $caller, $owner, $name, $pullRequest, $resolved);

        if ($findings->isNotEmpty()) {
            $this->writeNote($notes, $caller, $owner, $name, $pullRequest, $scan, $findings, (string) $runner->version());
        }
    }

    /**
     * Close out a scan the job left running when it died, so its check never stays in progress.
     */
    public function failed(Throwable $e): void
    {
        $scan = SecretScan::query()
            ->where('pull_request_id', $this->pullRequestId)
            ->where('head_sha', $this->headSha)
            ->where('status', SecretScanStatus::Running->value)
            ->latest()
            ->first();

        if ($scan === null) {
            return;
        }

        $scan->update(['status' => SecretScanStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 2000)]);

        $repository = $scan->repository;

        if ($scan->check_run_id === null || $repository === null) {
            return;
        }

        try {
            $caller = app(GitHubCallerResolver::class)->for($repository);

            if ($caller === null) {
                return;
            }

            [$owner, $name] = explode('/', $repository->full_name, 2);

            app(GitHubApiClient::class)->updateCheckRun($caller, $owner, $name, (int) $scan->check_run_id, 'neutral',
                'Secret scan could not run', 'The secret scan stopped before finishing: '.mb_substr($e->getMessage(), 0, 500));
        } catch (Throwable $apiError) {
            Log::warning('secret_scan.check_run_failed', ['check_run_id' => $scan->check_run_id, 'error' => $apiError->getMessage()]);
        }
    }

    /**
     * Reuse this head's unfinished scan row on a retry, or create one.
     */
    private function startScan(PullRequest $pullRequest): SecretScan
    {
        $scan = SecretScan::query()
            ->where('pull_request_id', $pullRequest->id)
            ->where('head_sha', $this->headSha)
            ->where('status', '!=', SecretScanStatus::Completed->value)
            ->latest()
            ->first();

        if ($scan !== null) {
            $scan->update(['status' => SecretScanStatus::Running, 'error' => null]);

            return $scan;
        }

        return SecretScan::query()->create([
            'pull_request_id' => $pullRequest->id,
            'git_repository_id' => $pullRequest->git_repository_id,
            'head_sha' => $this->headSha,
            'status' => SecretScanStatus::Running,
        ]);
    }

    /**
     * Create a finding per new hit and refresh the ones already open.
     *
     * @param  list<SecretHit>  $hits
     * @return Collection<int, PullRequestReviewFinding>
     */
    private function recordFindings(PullRequest $pullRequest, SecretScan $scan, array $hits): Collection
    {
        $open = PullRequestReviewFinding::query()
            ->where('pull_request_id', $pullRequest->id)
            ->where('source', FindingSource::Gitleaks->value)
            ->whereNull('resolved_at')
            ->get()
            ->keyBy('dedupe_key');

        $findings = collect();

        foreach ($hits as $hit) {
            $key = $hit->dedupeKey();

            if ($findings->has($key)) {
                continue;
            }

            $existing = $open->get($key);

            if ($existing !== null) {
                // The same secret, possibly moved: keep its thread, follow its line.
                $existing->update(['line' => $hit->line, 'secret_scan_id' => $scan->id]);
                $findings->put($key, $existing);

                continue;
            }

            $findings->put($key, PullRequestReviewFinding::query()->create([
                'pull_request_review_id' => null,
                'secret_scan_id' => $scan->id,
                'pull_request_id' => $pullRequest->id,
                'git_repository_id' => $pullRequest->git_repository_id,
                'source' => FindingSource::Gitleaks->value,
                'dedupe_key' => $key,
                'title' => mb_substr('Secret detected: '.($hit->description !== '' ? $hit->description : $hit->ruleId), 0, 255),
                'severity' => FindingSeverity::Critical->value,
                'category' => FindingCategory::Security->value,
                'file' => $hit->file,
                'line' => $hit->line,
                'confidence' => 1.0,
                'explanation' => "gitleaks rule `{$hit->ruleId}` matched `{$hit->match}` on an added line. "
                    .'The value is now part of this branch\'s git history, so deleting the line does not un-leak it.',
                'suggested_fix' => 'Rotate or revoke this credential first, then remove it from the code and load it from '
                    .'the environment or a secret store. If it is a test fixture, allowlist it in .gitleaks.toml on the target branch.',
            ]));
        }

        return $findings->values();
    }

    /**
     * Resolve open secret findings the new scan no longer sees.
     *
     * Findings in files this scan had to skip are left open: not seeing them is
     * not the same as them being gone.
     *
     * @param  array<int, string>  $currentKeys
     * @param  list<string>  $skippedPaths
     * @return Collection<int, PullRequestReviewFinding>
     */
    private function resolveRemoved(PullRequest $pullRequest, array $currentKeys, array $skippedPaths): Collection
    {
        $gone = PullRequestReviewFinding::query()
            ->where('pull_request_id', $pullRequest->id)
            ->where('source', FindingSource::Gitleaks->value)
            ->whereNull('resolved_at')
            ->whereNotIn('dedupe_key', $currentKeys)
            ->when($skippedPaths !== [], fn ($query) => $query->whereNotIn('file', $skippedPaths))
            ->get();

        foreach ($gone as $finding) {
            $finding->update([
                'resolved_at' => now(),
                'resolution_type' => FindingResolutionType::SecretRemoved,
            ]);
        }

        return $gone;
    }

    /**
     * Complete the secrets check run with one annotation per finding.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     */
    private function completeCheckRun(
        GitHubApiClient $api, GitAccount|string $caller, string $owner, string $name,
        int $checkRunId, string $conclusion, string $title, string $summary, Collection $findings,
    ): void {
        $annotations = $findings->map(fn (PullRequestReviewFinding $f) => [
            'path' => $f->file,
            'start_line' => (int) $f->line,
            'end_line' => (int) $f->line,
            'annotation_level' => 'failure',
            'title' => $f->title,
            'message' => $f->explanation,
        ])->values()->all();

        try {
            $api->updateCheckRun($caller, $owner, $name, $checkRunId, $conclusion, $title, $summary, $annotations);
        } catch (Throwable $e) {
            Log::warning('secret_scan.check_run_failed', ['check_run_id' => $checkRunId, 'error' => $e->getMessage()]);
        }
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
     * Post a COMMENT review and an inline comment for each finding not yet posted.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     */
    private function postComments(GitHubApiClient $api, GitAccount|string $caller, string $owner, string $name, PullRequest $pullRequest, Collection $findings): void
    {
        $unposted = $findings->reject(fn (PullRequestReviewFinding $f) => $f->is_posted);

        if ($unposted->isEmpty()) {
            return;
        }

        $count = $unposted->count();

        try {
            $api->postPullRequestReview($caller, $owner, $name, $pullRequest->number,
                "**PullLens secret scan:** {$count} possible ".str('secret')->plural($count).' added in this pull request. '
                .'Rotate each credential - removing it from the code is not enough once it has been pushed.',
                'COMMENT');
        } catch (Throwable $e) {
            Log::warning('secret_scan.review_post_failed', ['pull_request_id' => $pullRequest->id, 'error' => $e->getMessage()]);
        }

        foreach ($unposted as $finding) {
            try {
                $posted = $api->postReviewComment($caller, $owner, $name, $pullRequest->number, $this->headSha,
                    $finding->file, (int) $finding->line,
                    "**{$finding->title}**\n\n{$finding->explanation}\n\n{$finding->suggested_fix}");

                $commentId = (int) data_get($posted, 'id');

                if ($commentId > 0) {
                    $finding->update(['is_posted' => true, 'provider_comment_id' => $commentId]);
                }
            } catch (Throwable $e) {
                Log::warning('secret_scan.comment_post_failed', [
                    'pull_request_id' => $pullRequest->id, 'file' => $finding->file, 'line' => $finding->line, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Tell each resolved finding's thread the secret is gone from the diff but not from history.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $resolved
     */
    private function replyToResolved(GitHubApiClient $api, GitAccount|string $caller, string $owner, string $name, PullRequest $pullRequest, Collection $resolved): void
    {
        foreach ($resolved as $finding) {
            if (! $finding->provider_comment_id) {
                continue;
            }

            try {
                $api->replyToReviewComment($caller, $owner, $name, $pullRequest->number, (int) $finding->provider_comment_id,
                    'No longer in the diff, but still in this branch\'s git history - rotate this credential if you have not already.');
            } catch (Throwable $e) {
                Log::warning('secret_scan.resolve_reply_failed', ['finding_id' => $finding->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Attach the redacted findings to the head commit as a git note.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     */
    private function writeNote(GitHubNotesWriter $notes, GitAccount|string $caller, string $owner, string $name, PullRequest $pullRequest, SecretScan $scan, Collection $findings, string $version): void
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
            $scan->update(['notes_commit_sha' => $notes->write($caller, $owner, $name, $this->headSha, implode("\n", $lines)."\n")]);
        } catch (Throwable $e) {
            Log::warning('secret_scan.note_failed', ['secret_scan_id' => $scan->id, 'error' => $e->getMessage()]);
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
