<?php

namespace App\Support\Presenters\GIT;

use App\Models\GIT\PullRequestReview;
use Illuminate\Support\Collection;

/**
 * Serializes AI reviews for the dashboard activity feed.
 */
final class ReviewPresenter
{
    /**
     * Serialize for the front end.
     *
     * @return array<string, mixed>
     */
    public static function toArray(PullRequestReview $review): array
    {
        return [
            'id' => $review->id,
            'verdict' => $review->verdict?->value,
            'verdict_label' => $review->verdict?->label(),
            'risk_level' => $review->risk_level,
            'ai_model' => $review->ai_model,
            'review_duration_ms' => $review->review_duration_ms,
            'reviewed_at' => $review->reviewed_at?->toISOString(),
            // Populated by withCount('findings'); absent when the caller did not ask for it.
            'findings_count' => (int) ($review->findings_count ?? 0),
            'pull_request' => self::pullRequest($review),
        ];
    }

    /**
     * Serialize a set of records for the front end.
     *
     * @param  iterable<int, PullRequestReview>  $reviews
     * @return array<int, array<string, mixed>>
     */
    public static function collection(iterable $reviews): array
    {
        return Collection::make($reviews)
            ->map(static fn (PullRequestReview $review): array => self::toArray($review))
            ->values()
            ->all();
    }

    /**
     * The pull request this record belongs to.
     *
     * @return array<string, mixed>|null
     */
    private static function pullRequest(PullRequestReview $review): ?array
    {
        $pullRequest = $review->pullRequest;

        if ($pullRequest === null) {
            return null;
        }

        return [
            'number' => $pullRequest->number,
            'title' => $pullRequest->title,
            'author_login' => $pullRequest->author_login,
            'author_avatar_url' => $pullRequest->author_avatar_url,
            'web_url' => $pullRequest->web_url,
            'state' => $pullRequest->state?->value,
            'repository' => $pullRequest->repository === null
                ? null
                : ['full_name' => $pullRequest->repository->full_name],
        ];
    }
}
