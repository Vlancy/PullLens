<?php

namespace App\Services\Findings;

use App\Enums\GIT\FindingResolutionType;
use App\Models\GIT\PullRequestReviewFinding;
use Illuminate\Support\Facades\DB;

/**
 * Marks findings as resolved.
 *
 * Both entry points funnel through here so "resolved" always means the same pair of
 * columns being written together, and so re-resolving an already-closed finding can
 * never overwrite the original reason and timestamp.
 */
class FindingResolutionService
{
    /**
     * Close a single finding.
     *
     * Returns false when the finding was already resolved, so the caller can report
     * that rather than silently rewriting history.
     */
    public function resolve(PullRequestReviewFinding $finding, FindingResolutionType $type): bool
    {
        if ($finding->resolved_at !== null) {
            return false;
        }

        $finding->forceFill([
            'resolved_at' => now(),
            'resolution_type' => $type,
        ])->save();

        return true;
    }

    /**
     * Close every still-open finding in the batch.
     *
     * Wrapped in a transaction so a partially applied bulk action cannot leave some
     * findings closed with no record of the operation.
     *
     * @param  array<int, string>  $findingIds
     * @return int Number of findings actually closed.
     */
    public function resolveMany(array $findingIds, FindingResolutionType $type): int
    {
        if ($findingIds === []) {
            return 0;
        }

        return DB::transaction(fn (): int => PullRequestReviewFinding::query()
            ->whereIn('id', $findingIds)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'resolution_type' => $type->value,
                'updated_at' => now(),
            ]));
    }
}
