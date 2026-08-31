<?php

namespace App\Services\Reports;

use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;

/**
 * Supplies the dropdown choices the report pages filter by.
 *
 * These two lists were previously re-queried inline in five different controller
 * actions; keeping them here guarantees every page offers the same options.
 */
class ReportFilterOptionsService
{
    /**
     * Repositories with reviews enabled, for the repository filter.
     *
     * @return array<int, array<string, mixed>>
     */
    public function repositories(): array
    {
        return GitRepository::query()
            ->where('reviews_enabled', true)
            ->orderBy('full_name')
            ->get(['id', 'name', 'full_name'])
            ->map(static fn (GitRepository $repository): array => [
                'id' => $repository->id,
                'name' => $repository->name,
                'full_name' => $repository->full_name,
            ])
            ->all();
    }

    /**
     * Distinct pull request authors, for the developer filter.
     *
     * @return array<int, array<string, mixed>>
     */
    public function authors(): array
    {
        return PullRequest::query()
            ->whereNotNull('author_login')
            ->groupBy('author_login', 'author_name')
            ->orderBy('author_name')
            ->get(['author_login', 'author_name'])
            ->map(static fn (PullRequest $pr): array => [
                'author_login' => $pr->author_login,
                'author_name' => $pr->author_name,
            ])
            ->all();
    }
}
