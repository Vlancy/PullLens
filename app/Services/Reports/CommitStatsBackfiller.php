<?php

namespace App\Services\Reports;

use App\Jobs\GIT\SyncPullRequestDetails;
use App\Models\GIT\PullRequestCommit;

/**
 * Re-queues detail syncing for pull requests whose commits have no line statistics.
 *
 * A commit recorded straight from a webhook payload starts at zero additions and
 * deletions; if the follow-up API call failed, the numbers stay at zero and the
 * reports understate that developer's output. This finds those PRs and re-runs the sync.
 */
class CommitStatsBackfiller
{
    /**
     * Dispatch a detail sync for every affected pull request.
     *
     * @return int Number of pull requests queued.
     */
    public function queue(): int
    {
        $pullRequestIds = PullRequestCommit::query()
            ->where('additions', 0)
            ->where('deletions', 0)
            ->distinct()
            ->pluck('pull_request_id');

        $pullRequestIds->each(
            static fn (string $id) => SyncPullRequestDetails::dispatch($id),
        );

        return $pullRequestIds->count();
    }
}
