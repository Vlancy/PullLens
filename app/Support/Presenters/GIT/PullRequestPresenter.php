<?php

namespace App\Support\Presenters\GIT;

use App\Models\GIT\PullRequest;
use Illuminate\Support\Collection;

/**
 * Serializes pull requests for the repository page listing.
 */
final class PullRequestPresenter
{
    /**
     * Serialize for the front end.
     *
     * @return array<string, mixed>
     */
    public static function toArray(PullRequest $pullRequest): array
    {
        return [
            'id' => $pullRequest->id,
            'number' => $pullRequest->number,
            'title' => $pullRequest->title,
            'state' => $pullRequest->state?->value,
            'is_draft' => $pullRequest->is_draft,
            'author_login' => $pullRequest->author_login,
            'author_avatar_url' => $pullRequest->author_avatar_url,
            'source_branch' => $pullRequest->source_branch,
            'target_branch' => $pullRequest->target_branch,
            'web_url' => $pullRequest->web_url,
            'additions' => $pullRequest->additions,
            'deletions' => $pullRequest->deletions,
            'changed_files_count' => $pullRequest->changed_files_count,
            'labels' => $pullRequest->labels ?? [],
            'opened_at' => $pullRequest->opened_at?->toISOString(),
            'merged_at' => $pullRequest->merged_at?->toISOString(),
            'closed_at' => $pullRequest->closed_at?->toISOString(),
            // Set by RepositoryOverviewService; defaults to zero when not loaded.
            'findings_count' => (int) ($pullRequest->getAttribute('open_findings_count') ?? 0),
            'latest_review' => self::latestReview($pullRequest),
        ];
    }

    /**
     * Serialize a set of records for the front end.
     *
     * @param  iterable<int, PullRequest>  $pullRequests
     * @return array<int, array<string, mixed>>
     */
    public static function collection(iterable $pullRequests): array
    {
        return Collection::make($pullRequests)
            ->map(static fn (PullRequest $pullRequest): array => self::toArray($pullRequest))
            ->values()
            ->all();
    }

    /**
     * The most recent completed review for this pull request.
     *
     * @return array<string, mixed>|null
     */
    private static function latestReview(PullRequest $pullRequest): ?array
    {
        $review = $pullRequest->latestReview;

        return $review === null ? null : [
            'verdict' => $review->verdict?->value,
            'verdict_label' => $review->verdict?->label(),
            'reviewed_at' => $review->reviewed_at?->toISOString(),
        ];
    }
}
