<?php

namespace App\Services\Findings;

use App\Enums\GIT\FindingResolutionType;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReviewFinding;
use App\Support\Access\RepositoryScope;
use App\Support\Database\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the dropdown choices offered by the findings page.
 *
 * Only values that actually appear in the data are offered, so the filters can never
 * lead to an empty result set - and every option is constrained to the caller's
 * repository scope, so the dropdowns cannot disclose the existence of a repository or
 * a developer the user has no access to.
 */
class FindingFilterOptionsService
{
    /**
     * Repositories in scope that have at least one finding.
     *
     * @return array<int, array<string, mixed>>
     */
    public function repositories(RepositoryScope $scope): array
    {
        $query = GitRepository::query()->whereHas('findings');

        $scope->applyToRepositories($query);

        return $query
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
     * Developers whose pull requests carry findings inside the scope.
     *
     * @return array<int, array<string, mixed>>
     */
    public function developers(RepositoryScope $scope): array
    {
        return PullRequest::query()
            ->whereNotNull('author_login')
            ->whereHas('findings', fn (Builder $findings) => $scope->applyTo(
                $findings,
                Table::of(PullRequestReviewFinding::class).'.git_repository_id',
            ))
            ->groupBy('author_login', 'author_name', 'author_avatar_url')
            ->orderBy('author_name')
            ->get(['author_login', 'author_name', 'author_avatar_url'])
            ->map(static fn (PullRequest $pr): array => [
                'login' => $pr->author_login,
                'name' => $pr->author_name,
                'avatar' => $pr->author_avatar_url,
            ])
            ->all();
    }

    /**
     * Finding categories present within the scope.
     *
     * @return array<int, string>
     */
    public function categories(RepositoryScope $scope): array
    {
        $query = PullRequestReviewFinding::query()->whereNotNull('category');

        $scope->applyTo($query);

        return $query
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->map(static fn (mixed $category): string => $category instanceof \BackedEnum
                ? (string) $category->value
                : (string) $category)
            ->all();
    }

    /**
     * Resolution reasons an operator can pick when closing a finding.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function resolutionTypes(): array
    {
        return array_map(
            static fn (FindingResolutionType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ],
            FindingResolutionType::cases(),
        );
    }
}
