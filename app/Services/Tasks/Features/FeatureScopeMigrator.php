<?php

namespace App\Services\Tasks\Features;

use App\Enums\GIT\AttributionSource;
use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;
use App\Models\GIT\TaskFeature;
use App\Support\Tasks\FeatureScope;

/**
 * Keeps task features consistent when repositories move between projects.
 *
 * Features live in the repository's project when it has one, otherwise in the
 * repository itself, so a membership change moves features between those scopes.
 */
class FeatureScopeMigrator
{
    /**
     * Inject the feature manager used to move tasks between features.
     *
     * @param  FeatureManager  $features  Moves tasks and merge pointers.
     */
    public function __construct(private readonly FeatureManager $features) {}

    /**
     * A repository was newly assigned to a project.
     *
     * Its features are promoted into the project; one whose slug the project already
     * has is folded into the project's feature, a manual name winning over an AI one.
     *
     * @param  GitRepository  $repository  The repository that joined.
     * @param  Project  $project  The project it joined.
     */
    public function repositoryJoined(GitRepository $repository, Project $project): void
    {
        $projectScope = new FeatureScope($project->id, null);
        $repositoryFeatures = (new FeatureScope(null, $repository->id))
            ->applyTo(TaskFeature::query())
            ->get();

        foreach ($repositoryFeatures as $feature) {
            $existing = $projectScope->applyTo(TaskFeature::query())->where('slug', $feature->slug)->first();

            if ($existing === null) {
                $feature->forceFill([
                    'project_id' => $project->id,
                    'git_repository_id' => null,
                    'scope_key' => $projectScope->key(),
                ])->save();

                continue;
            }

            $target = $existing->resolveMerged();

            if ($feature->merged_into_id === null
                && $feature->source === AttributionSource::Manual
                && $target->source === AttributionSource::Ai) {
                $target->forceFill([
                    'name' => $feature->name,
                    'description' => $target->description ?? $feature->description,
                    'source' => AttributionSource::Manual,
                ])->save();
            }

            // The repository feature's scope no longer applies, so it is folded in and
            // removed rather than left behind as a pointer nothing can reach.
            $this->features->absorb($feature, $target);
            $feature->delete();
        }
    }

    /**
     * A repository is leaving a project, by unassignment, move or project deletion.
     *
     * Each project feature holding this repository's tasks is copied into the
     * repository's own scope (same slug, name and source) and those tasks move to it.
     *
     * @param  GitRepository  $repository  The repository that is leaving.
     * @param  Project  $old  The project it is leaving.
     */
    public function repositoryLeft(GitRepository $repository, Project $old): void
    {
        $repositoryScope = new FeatureScope(null, $repository->id);
        $projectFeatures = (new FeatureScope($old->id, null))
            ->applyTo(TaskFeature::query())
            ->whereHas('tasks', fn ($query) => $query->where('git_repository_id', $repository->id))
            ->get();

        foreach ($projectFeatures as $feature) {
            $target = $repositoryScope->applyTo(TaskFeature::query())->where('slug', $feature->slug)->first()?->resolveMerged()
                ?? TaskFeature::query()->create([
                    'project_id' => null,
                    'git_repository_id' => $repository->id,
                    'scope_key' => $repositoryScope->key(),
                    'name' => $feature->name,
                    'slug' => $feature->slug,
                    'description' => $feature->description,
                    'source' => $feature->source,
                ]);

            $this->features->absorb($feature, $target, $repository);
        }
    }
}
