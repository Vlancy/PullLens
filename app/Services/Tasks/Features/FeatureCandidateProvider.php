<?php

namespace App\Services\Tasks\Features;

use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequestTask;
use App\Models\GIT\TaskFeature;
use App\Services\Repositories\RepositoryTagService;
use App\Support\Tasks\FeatureScope;

/**
 * Supplies the project, tag and feature context a review needs to assign tasks to features.
 */
class FeatureCandidateProvider
{
    /** Default number of features offered to the model, when not configured. */
    private const DEFAULT_LIMIT = 40;

    /**
     * Inject the tag service used to read repository tags.
     *
     * @param  RepositoryTagService  $tags  Reads the live tag names for the prompt.
     */
    public function __construct(private readonly RepositoryTagService $tags) {}

    /**
     * Context for the repository's review prompt.
     *
     * @param  GitRepository  $repository  The repository being reviewed.
     * @return array{project: array{name: string, description: ?string, repositories: array<int, array{name: string, tags: array<int, string>}>}|null, repository_tags: array<int, string>, features: array<int, array{slug: string, name: string, description: ?string, task_count: int}>}
     */
    public function forRepository(GitRepository $repository): array
    {
        $project = $repository->project;

        return [
            'project' => $project === null ? null : [
                'name' => $project->name,
                'description' => $project->description,
                'repositories' => $project->repositories()->orderBy('name')->get()
                    ->map(fn (GitRepository $repo): array => [
                        'name' => $repo->name,
                        'tags' => $this->tags->forPrompt($repo),
                    ])->all(),
            ],
            'repository_tags' => $this->tags->forPrompt($repository),
            'features' => $this->features(FeatureScope::forRepository($repository)),
        ];
    }

    /**
     * Active features in scope, most recently worked on first.
     *
     * @return array<int, array{slug: string, name: string, description: ?string, task_count: int}>
     */
    private function features(FeatureScope $scope): array
    {
        $latest = PullRequestTask::query()
            ->selectRaw('max(created_at)')
            ->whereColumn('pull_request_tasks.task_feature_id', 'task_features.id');

        return $scope->applyTo(TaskFeature::query())
            ->active()
            ->withCount('tasks')
            // COALESCE keeps task-less features last: pgsql sorts NULLs first on DESC.
            ->orderByRaw("COALESCE(({$latest->toSql()}), '1970-01-01 00:00:00') desc")
            ->orderBy('name')
            ->limit((int) config('pulllens.reviews.feature_context_limit', self::DEFAULT_LIMIT))
            ->get()
            ->map(static fn (TaskFeature $feature): array => [
                'slug' => $feature->slug,
                'name' => $feature->name,
                'description' => $feature->description,
                'task_count' => (int) $feature->tasks_count,
            ])
            ->all();
    }
}
