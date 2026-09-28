<?php

namespace App\Services\Git\Scanning;

use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\Scanner;
use App\Models\GIT\GitAccount;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\SecurityScan;
use App\Services\Git\GitHubApiClient;
use App\Services\Git\GitHubCallerResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The GitHub check run a scanner reports on, from creation to conclusion.
 *
 * Every GitHub error is logged and swallowed: a scan still records its findings in
 * PullLens when the check cannot be created or completed.
 */
class ScanCheckRun
{
    /**
     * Inject the collaborators this class delegates to.
     */
    public function __construct(
        private readonly GitHubApiClient $api,
        private readonly GitHubCallerResolver $callers,
    ) {}

    /**
     * The check run this head's scan reports on: the one an earlier attempt already
     * created, or a new one. Null when GitHub will not create it.
     */
    public function open(GitAccount|string $caller, string $owner, string $repo, string $headSha, SecurityScan $scan): ?int
    {
        if ($scan->check_run_id !== null) {
            return (int) $scan->check_run_id;
        }

        try {
            return (int) data_get($this->api->createCheckRun($caller, $owner, $repo, $headSha, $scan->scanner->checkName()), 'id') ?: null;
        } catch (Throwable $e) {
            Log::warning($scan->scanner->logPrefix().'.check_run_create_failed', ['security_scan_id' => $scan->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Complete the check with one annotation per finding that has a line.
     *
     * High and critical findings annotate as failures, the rest as warnings.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     */
    public function complete(
        GitAccount|string $caller, string $owner, string $repo, int $checkRunId, Scanner $scanner,
        string $conclusion, string $title, string $summary, Collection $findings,
    ): void {
        $annotations = $findings
            ->filter(fn (PullRequestReviewFinding $f) => $f->line !== null)
            ->map(fn (PullRequestReviewFinding $f) => [
                'path' => $f->file,
                'start_line' => (int) $f->line,
                'end_line' => (int) $f->line,
                'annotation_level' => in_array($f->severity, [FindingSeverity::Critical, FindingSeverity::High], true) ? 'failure' : 'warning',
                'title' => $f->title,
                'message' => $f->explanation,
            ])
            ->values()
            ->all();

        try {
            $this->api->updateCheckRun($caller, $owner, $repo, $checkRunId, $conclusion, $title, $summary, $annotations);
        } catch (Throwable $e) {
            Log::warning($scanner->logPrefix().'.check_run_failed', ['check_run_id' => $checkRunId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Complete a dead job's check as neutral so it never stays in progress.
     */
    public function closeAfterCrash(SecurityScan $scan, string $title, string $summary): void
    {
        $repository = $scan->repository;

        if ($scan->check_run_id === null || $repository === null) {
            return;
        }

        try {
            $caller = $this->callers->for($repository);

            if ($caller === null) {
                return;
            }

            [$owner, $name] = explode('/', $repository->full_name, 2);

            $this->api->updateCheckRun($caller, $owner, $name, (int) $scan->check_run_id, 'neutral', $title, $summary);
        } catch (Throwable $e) {
            Log::warning($scan->scanner->logPrefix().'.check_run_failed', ['check_run_id' => $scan->check_run_id, 'error' => $e->getMessage()]);
        }
    }
}
