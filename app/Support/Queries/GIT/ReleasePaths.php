<?php

namespace App\Support\Queries\GIT;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where merged work lives now: a pull request followed through later release merges.
 *
 * From the branch a pull request landed on, the next hop is the first later merge
 * whose source is that branch (stage -> prod merged after the work reached stage),
 * and so on. Shared by the feature rollup and the task list, so a feature and its
 * tasks always agree on where the work is.
 */
final class ReleasePaths
{
    /** How many release hops (e.g. feature -> stage -> prod) are followed. */
    private const MAX_PROMOTIONS = 5;

    /**
     * The release path of each pull request, keyed by the key the caller picks.
     *
     * One query for the whole set: merged pull requests in the repositories of the
     * merged items, from the earliest of those merges on. Unmerged work stays where
     * it is, with an empty path.
     *
     * @param  Collection<array-key, object>  $pullRequests  Items with git_repository_id, target_branch and merged_at.
     * @return Collection<array-key, array{path: array<int, string>, promoted_by: array{number: int|null, url: string|null}|null}>
     */
    public static function for(Collection $pullRequests): Collection
    {
        $releases = self::releaseMerges($pullRequests);

        return $pullRequests->map(static fn (object $pullRequest): array => self::follow(
            $pullRequest,
            $releases[$pullRequest->git_repository_id] ?? [],
        ));
    }

    /**
     * Merged pull requests that could have carried the items onwards, per repository, oldest first.
     *
     * @param  Collection<array-key, object>  $pullRequests
     * @return array<string, array<int, object>>
     */
    private static function releaseMerges(Collection $pullRequests): array
    {
        $merged = $pullRequests->filter(static fn (object $row): bool => $row->merged_at !== null && $row->target_branch !== null);

        if ($merged->isEmpty()) {
            return [];
        }

        return DB::table('pull_requests')
            ->whereIn('git_repository_id', $merged->pluck('git_repository_id')->unique()->values()->all())
            ->whereNotNull('merged_at')
            ->where('merged_at', '>=', $merged->min(static fn (object $row): Carbon => Carbon::parse($row->merged_at)))
            ->orderBy('merged_at')
            ->orderBy('number')
            ->get(['git_repository_id', 'source_branch', 'target_branch', 'number', 'web_url', 'merged_at'])
            ->groupBy('git_repository_id')
            ->map(static fn (Collection $rows): array => $rows->all())
            ->all();
    }

    /**
     * Follow one merged pull request through the later release merges of its repository.
     *
     * @param  array<int, object>  $releases  Merged pull requests of its repository, oldest first.
     * @return array{path: array<int, string>, promoted_by: array{number: int|null, url: string|null}|null}
     */
    private static function follow(object $pullRequest, array $releases): array
    {
        if ($pullRequest->merged_at === null || $pullRequest->target_branch === null) {
            return ['path' => [], 'promoted_by' => null];
        }

        $path = [$pullRequest->target_branch];
        $branch = $pullRequest->target_branch;
        $since = Carbon::parse($pullRequest->merged_at);
        $promotedBy = null;

        for ($hop = 0; $hop < self::MAX_PROMOTIONS; $hop++) {
            $next = null;

            foreach ($releases as $release) {
                if ($release->source_branch === $branch
                    && $release->target_branch !== null
                    && ! in_array($release->target_branch, $path, true)
                    && Carbon::parse($release->merged_at)->greaterThan($since)) {
                    $next = $release;
                    break;
                }
            }

            if ($next === null) {
                break;
            }

            $path[] = $next->target_branch;
            $branch = $next->target_branch;
            $since = Carbon::parse($next->merged_at);
            $promotedBy = ['number' => $next->number === null ? null : (int) $next->number, 'url' => $next->web_url];
        }

        return ['path' => $path, 'promoted_by' => $promotedBy];
    }
}
