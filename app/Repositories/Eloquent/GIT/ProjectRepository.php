<?php

namespace App\Repositories\Eloquent\GIT;

use App\Models\GIT\Project;
use App\Repositories\Contracts\GIT\ProjectRepositoryInterface;
use App\Repositories\Eloquent\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<Project>
 */
class ProjectRepository extends BaseRepository implements ProjectRepositoryInterface
{
    protected string $model = Project::class;

    /**
     * Find a project by id or throw a model-not-found exception.
     */
    public function findOrFail(string $id): Project
    {
        return $this->query()->findOrFail($id);
    }

    /**
     * Return every project with its repositories eager loaded, ordered by name.
     *
     * @return Collection<int, Project>
     */
    public function allWithRepositories(): Collection
    {
        return $this->query()->with('repositories')->orderBy('name')->get();
    }
}
