<?php

namespace App\Services\Git\Scanning;

use App\Enums\GIT\Scanner;
use App\Enums\GIT\SecurityScanStatus;
use App\Models\GIT\PullRequest;
use App\Models\GIT\SecurityScan;
use Illuminate\Database\Eloquent\Builder;

/**
 * Owns the security_scans row for one scanner's run against a pull request head.
 */
class ScanRunRecorder
{
    /**
     * Whether this scanner already finished a scan of this head.
     */
    public function alreadyCompleted(string $pullRequestId, string $headSha, Scanner $scanner): bool
    {
        return $this->forHead($pullRequestId, $headSha, $scanner)
            ->where('status', SecurityScanStatus::Completed->value)
            ->exists();
    }

    /**
     * Reuse this head's unfinished row on a retry, or create one, and mark it running.
     */
    public function start(PullRequest $pullRequest, string $headSha, Scanner $scanner): SecurityScan
    {
        $scan = $this->forHead($pullRequest->id, $headSha, $scanner)
            ->where('status', '!=', SecurityScanStatus::Completed->value)
            ->latest()
            ->first();

        if ($scan !== null) {
            $scan->update(['status' => SecurityScanStatus::Running, 'error' => null]);

            return $scan;
        }

        return SecurityScan::query()->create([
            'pull_request_id' => $pullRequest->id,
            'git_repository_id' => $pullRequest->git_repository_id,
            'scanner' => $scanner,
            'head_sha' => $headSha,
            'status' => SecurityScanStatus::Running,
        ]);
    }

    /**
     * The row a dead job left running for this head, if any.
     */
    public function running(string $pullRequestId, string $headSha, Scanner $scanner): ?SecurityScan
    {
        return $this->forHead($pullRequestId, $headSha, $scanner)
            ->where('status', SecurityScanStatus::Running->value)
            ->latest()
            ->first();
    }

    /**
     * Every row of this scanner for this pull request head.
     *
     * @return Builder<SecurityScan>
     */
    private function forHead(string $pullRequestId, string $headSha, Scanner $scanner): Builder
    {
        return SecurityScan::query()
            ->where('pull_request_id', $pullRequestId)
            ->where('scanner', $scanner->value)
            ->where('head_sha', $headSha);
    }
}
