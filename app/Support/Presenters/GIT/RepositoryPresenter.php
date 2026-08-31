<?php

namespace App\Support\Presenters\GIT;

use App\Models\GIT\GitRepository;
use Illuminate\Support\Collection;

/**
 * Serializes repositories for the dashboard and repository pages.
 */
final class RepositoryPresenter
{
    /**
     * Identity and configuration, without any counts.
     *
     * @return array<string, mixed>
     */
    public static function toArray(GitRepository $repository): array
    {
        return [
            'id' => $repository->id,
            'name' => $repository->name,
            'full_name' => $repository->full_name,
            'owner_login' => $repository->owner_login,
            'provider' => $repository->provider?->value,
            'is_private' => $repository->is_private,
            'web_url' => $repository->web_url,
            'default_branch' => $repository->default_branch,
            'reviews_enabled' => $repository->reviews_enabled,
        ];
    }

    /**
     * Dashboard row: identity plus the counts loaded via withCount().
     *
     * @return array<string, mixed>
     */
    public static function withCounts(GitRepository $repository): array
    {
        return [
            ...self::toArray($repository),
            'pull_requests_count' => (int) ($repository->pull_requests_count ?? 0),
            'findings_count' => (int) ($repository->findings_count ?? 0),
        ];
    }

    /**
     * @param  iterable<int, GitRepository>  $repositories
     * @return array<int, array<string, mixed>>
     */
    public static function collectionWithCounts(iterable $repositories): array
    {
        return Collection::make($repositories)
            ->map(static fn (GitRepository $repository): array => self::withCounts($repository))
            ->values()
            ->all();
    }
}
