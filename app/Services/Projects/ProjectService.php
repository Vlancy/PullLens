<?php

namespace App\Services\Projects;

use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;
use App\Repositories\Contracts\GIT\ProjectRepositoryInterface;
use App\Services\Tasks\Features\FeatureManager;
use App\Services\Tasks\Features\FeatureScopeMigrator;
use Illuminate\Support\Facades\DB;

/**
 * Creates, edits and deletes projects and manages which repositories belong to them.
 */
class ProjectService
{
    /**
     * Inject the project store, the migrator that keeps features in step with membership,
     * and the feature manager that prunes features a move leaves empty.
     *
     * @param  ProjectRepositoryInterface  $projects  Persists projects.
     * @param  FeatureScopeMigrator  $migrator  Moves features when repositories join or leave.
     * @param  FeatureManager  $features  Prunes AI features left without tasks.
     */
    public function __construct(
        private readonly ProjectRepositoryInterface $projects,
        private readonly FeatureScopeMigrator $migrator,
        private readonly FeatureManager $features,
    ) {}

    /**
     * Create a project.
     *
     * @param  array{name: string, description?: ?string}  $data
     */
    public function create(array $data): Project
    {
        return $this->projects->create($data);
    }

    /**
     * Update a project's name and description.
     *
     * @param  array{name?: string, description?: ?string}  $data
     */
    public function update(Project $project, array $data): Project
    {
        return $this->projects->update($project, $data);
    }

    /**
     * Make exactly the given repositories members of the project.
     *
     * Repositories moving in from another project leave it first, and members not
     * in the list are unassigned. AI features the departures leave empty are pruned
     * from every project a repository left.
     *
     * @param  array<int, string>  $repositoryIds
     */
    public function assignRepositories(Project $project, array $repositoryIds): void
    {
        DB::transaction(function () use ($project, $repositoryIds): void {
            $ids = array_values(array_unique($repositoryIds));

            $leaving = GitRepository::query()
                ->where('project_id', $project->id)
                ->whereNotIn('id', $ids)
                ->get();

            $joining = GitRepository::query()
                ->whereIn('id', $ids)
                ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', '!=', $project->id))
                ->with('project')
                ->get();

            $left = [];

            foreach ($leaving as $repository) {
                $left[$project->id] = true;
                $this->migrator->repositoryLeft($repository, $project);
                $repository->forceFill(['project_id' => null])->save();
                $repository->setRelation('project', null);
            }

            foreach ($joining as $repository) {
                if ($repository->project !== null) {
                    $left[$repository->project->id] = true;
                    $this->migrator->repositoryLeft($repository, $repository->project);
                }

                $repository->forceFill(['project_id' => $project->id])->save();
                // Keep the loaded relation in step with the new FK for the migrator.
                $repository->setRelation('project', $project);
                $this->migrator->repositoryJoined($repository, $project);
            }

            foreach (array_keys($left) as $projectId) {
                $this->features->pruneEmpty((string) $projectId);
            }
        });
    }

    /**
     * Delete a project, unassigning its repositories first.
     */
    public function delete(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            foreach ($project->repositories()->get() as $repository) {
                $this->migrator->repositoryLeft($repository, $project);
            }

            $this->projects->delete($project);
        });
    }
}
