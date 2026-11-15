<?php

namespace App\Repositories\Contracts\GIT;

use App\Models\GIT\Project;
use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface ProjectRepositoryInterface extends RepositoryInterface
{
    /**
     * Find a project by id or throw a model-not-found exception.
     */
    public function findOrFail(string $id): Project;

    /**
     * Return every project with its repositories eager loaded, ordered by name.
     *
     * @return Collection<int, Project>
     */
    public function allWithRepositories(): Collection;
}
