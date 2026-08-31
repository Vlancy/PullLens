<?php

namespace App\Support\Presenters\GIT;

use App\Models\GIT\PullRequestReviewFinding;
use Illuminate\Support\Collection;

/**
 * Serializes review findings for the Inertia pages that list them.
 *
 * One definition of the shape means the findings list, the repository page and any
 * future view stay consistent, and enum-backed columns are always emitted as their
 * scalar value rather than as an object the front end has to special-case.
 */
final class FindingPresenter
{
    /**
     * Full representation used by the findings list.
     *
     * @return array<string, mixed>
     */
    public static function toArray(PullRequestReviewFinding $finding): array
    {
        return [
            'id' => $finding->id,
            'title' => $finding->title,
            'severity' => $finding->severity?->value,
            'category' => $finding->category?->value,
            'file' => $finding->file,
            'line' => $finding->line,
            'confidence' => $finding->confidence,
            'explanation' => $finding->explanation,
            'suggested_fix' => $finding->suggested_fix,
            'resolved_at' => $finding->resolved_at?->toISOString(),
            'resolution_type' => $finding->resolution_type?->value,
            'created_at' => $finding->created_at?->toISOString(),
            'repository' => self::repository($finding),
            'pull_request' => self::pullRequest($finding),
        ];
    }

    /**
     * Condensed representation for the "recent findings" panel on a repository page.
     *
     * @return array<string, mixed>
     */
    public static function summary(PullRequestReviewFinding $finding): array
    {
        return [
            'id' => $finding->id,
            'title' => $finding->title,
            'severity' => $finding->severity?->value,
            'category' => $finding->category?->value,
            'file' => $finding->file,
            'line' => $finding->line,
            'is_resolved' => $finding->resolved_at !== null,
            'pull_request' => self::pullRequest($finding),
        ];
    }

    /**
     * @param  iterable<int, PullRequestReviewFinding>  $findings
     * @return array<int, array<string, mixed>>
     */
    public static function collection(iterable $findings): array
    {
        return Collection::make($findings)
            ->map(static fn (PullRequestReviewFinding $finding): array => self::toArray($finding))
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int, PullRequestReviewFinding>  $findings
     * @return array<int, array<string, mixed>>
     */
    public static function summaries(iterable $findings): array
    {
        return Collection::make($findings)
            ->map(static fn (PullRequestReviewFinding $finding): array => self::summary($finding))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function repository(PullRequestReviewFinding $finding): ?array
    {
        $repository = $finding->repository;

        return $repository === null ? null : [
            'id' => $repository->id,
            'name' => $repository->name,
            'full_name' => $repository->full_name,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function pullRequest(PullRequestReviewFinding $finding): ?array
    {
        $pullRequest = $finding->pullRequest;

        return $pullRequest === null ? null : [
            'number' => $pullRequest->number,
            'title' => $pullRequest->title,
            'web_url' => $pullRequest->web_url,
            'state' => $pullRequest->state?->value,
        ];
    }
}
