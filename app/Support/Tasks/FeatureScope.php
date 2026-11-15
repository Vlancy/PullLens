<?php

namespace App\Support\Tasks;

use App\Models\GIT\GitRepository;
use App\Models\GIT\TaskFeature;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where a feature lives: a project when the repository has one, otherwise the repository.
 */
final readonly class FeatureScope
{
    /**
     * Build a scope; exactly one of the ids is expected to be set.
     */
    public function __construct(
        public ?string $projectId,
        public ?string $repositoryId,
    ) {}

    /**
     * The scope features for this repository belong to.
     */
    public static function forRepository(GitRepository $repository): self
    {
        return $repository->project_id !== null
            ? new self($repository->project_id, null)
            : new self(null, $repository->id);
    }

    /**
     * Stable non-null identifier for this scope, stored as `task_features.scope_key`.
     */
    public function key(): string
    {
        return $this->projectId !== null
            ? "project:{$this->projectId}"
            : "repository:{$this->repositoryId}";
    }

    /**
     * Restrict a task features query to this scope.
     *
     * @param  Builder<TaskFeature>  $query
     * @return Builder<TaskFeature>
     */
    public function applyTo(Builder $query): Builder
    {
        return $query->where('task_features.scope_key', $this->key());
    }
}
