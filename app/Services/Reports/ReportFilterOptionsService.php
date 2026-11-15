<?php

namespace App\Services\Reports;

use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;
use App\Models\GIT\PullRequest;
use App\Support\Access\RepositoryScope;
use Illuminate\Database\Eloquent\Builder;

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
     * @param  RepositoryScope|null  $scope  The viewer's grants; null lists every repository.
     * @return array<int, array<string, mixed>>
     */
    public function repositories(?RepositoryScope $scope = null): array
    {
        return $this->filterable($scope)
            ->orderBy('full_name')
            ->get(['id', 'name', 'full_name', 'project_id'])
            ->map(static fn (GitRepository $repository): array => [
                'id' => $repository->id,
                'name' => $repository->name,
                'full_name' => $repository->full_name,
                // Lets a project filter narrow this list client-side.
                'project_id' => $repository->project_id,
            ])
            ->all();
    }

    /**
     * Projects with at least one repository the viewer may see, for the project filter.
     *
     * @param  RepositoryScope  $scope  The viewer's repository grants.
     * @return array<int, array{id: string, name: string}>
     */
    public function projects(RepositoryScope $scope): array
    {
        return Project::query()
            ->whereHas('repositories', fn (Builder $repositories) => $scope
                ->applyToRepositories($repositories->where('git_repositories.reviews_enabled', true), 'git_repositories.id'))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(static fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
            ])
            ->all();
    }

    /**
     * Whether the viewer may see any repository that belongs to no project.
     *
     * @param  RepositoryScope  $scope  The viewer's repository grants.
     */
    public function hasRepositoriesWithoutProject(RepositoryScope $scope): bool
    {
        return $this->filterable($scope)->whereNull('project_id')->exists();
    }

    /**
     * The repositories the filters offer: reviews enabled, within the scope when one is given.
     *
     * @param  RepositoryScope|null  $scope  The viewer's grants; null means no restriction.
     * @return Builder<GitRepository>
     */
    private function filterable(?RepositoryScope $scope): Builder
    {
        $query = GitRepository::query()->where('reviews_enabled', true);

        return $scope === null ? $query : $scope->applyToRepositories($query);
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
