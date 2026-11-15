<?php

namespace App\Services\Tasks\Features;

use App\Enums\GIT\AttributionSource;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequestTask;
use App\Models\GIT\TaskFeature;
use App\Support\Tasks\FeatureScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Applies a person's corrections to features: renames, merges, moving tasks, cleanup.
 *
 * Every correction is recorded as manual so later reviews never undo it.
 */
class FeatureManager
{
    /**
     * Rename a feature and replace its description, marking it manual.
     *
     * The slug is left alone so the AI's existing references keep resolving to it.
     *
     * @param  TaskFeature  $feature  The feature to rename.
     * @param  string  $name  The new display name.
     * @param  ?string  $description  The new description; null clears it.
     */
    public function rename(TaskFeature $feature, string $name, ?string $description): TaskFeature
    {
        $description = $description === null ? null : trim($description);

        $feature->forceFill([
            'name' => trim($name),
            'description' => $description === '' ? null : $description,
            'source' => AttributionSource::Manual,
        ])->save();

        return $feature;
    }

    /**
     * Fold one feature into another in the same scope.
     *
     * Tasks keep their feature source; the merged feature stays behind as a pointer so
     * slug references to it resolve to the target.
     *
     * @param  TaskFeature  $from  The feature being absorbed.
     * @param  TaskFeature  $into  The feature to keep (its merge chain is followed first).
     *
     * @throws ValidationException When the target is the same feature or in another scope.
     */
    public function merge(TaskFeature $from, TaskFeature $into): void
    {
        $into = $into->resolveMerged();

        if ($into->is($from)) {
            throw ValidationException::withMessages(['into_id' => 'A feature cannot be merged into itself.']);
        }

        if ($into->scope_key !== $from->scope_key) {
            throw ValidationException::withMessages(['into_id' => 'Features can only be merged within the same project or repository.']);
        }

        DB::transaction(function () use ($from, $into): void {
            $this->absorb($from, $into);
            $from->forceFill(['merged_into_id' => $into->id])->save();
        });
    }

    /**
     * File a task under an existing feature, a newly named one, or none, as a manual choice.
     *
     * @param  PullRequestTask  $task  The task to move.
     * @param  ?string  $featureId  An existing feature in the task's scope.
     * @param  ?string  $newName  A name for a new manual feature, used when no id is given.
     *
     * @throws ValidationException When the feature is outside the task's scope.
     */
    public function assignTask(PullRequestTask $task, ?string $featureId, ?string $newName): void
    {
        $scope = FeatureScope::forRepository($task->repository()->firstOrFail());
        $feature = null;

        if ($featureId !== null) {
            $feature = $scope->applyTo(TaskFeature::query())->whereKey($featureId)->first()?->resolveMerged();

            if ($feature === null) {
                throw ValidationException::withMessages(['feature_id' => 'That feature belongs to a different project or repository.']);
            }
        } elseif ($newName !== null && trim($newName) !== '') {
            $feature = $this->findOrCreate($scope, trim($newName));
        }

        $task->forceFill([
            'task_feature_id' => $feature?->id,
            'feature_source' => AttributionSource::Manual,
        ])->save();
    }

    /**
     * Delete AI features nothing uses any more: no tasks, and no features merged into them.
     *
     * @return int The number of features deleted.
     */
    public function pruneEmpty(): int
    {
        return TaskFeature::query()
            ->active()
            ->where('source', AttributionSource::Ai->value)
            ->whereDoesntHave('tasks')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('task_features as merged')
                ->whereColumn('merged.merged_into_id', 'task_features.id'))
            ->delete();
    }

    /**
     * Move a feature's tasks, and the features merged into it, onto another feature.
     *
     * @param  TaskFeature  $from  The feature being emptied.
     * @param  TaskFeature  $into  The feature receiving its tasks.
     * @param  ?GitRepository  $repository  Limit the move to one repository's tasks.
     */
    public function absorb(TaskFeature $from, TaskFeature $into, ?GitRepository $repository = null): void
    {
        PullRequestTask::query()
            ->where('task_feature_id', $from->id)
            ->when($repository !== null, fn (Builder $query) => $query->where('git_repository_id', $repository->id))
            ->update(['task_feature_id' => $into->id]);

        if ($repository === null) {
            TaskFeature::query()->where('merged_into_id', $from->id)->update(['merged_into_id' => $into->id]);
        }
    }

    /**
     * Find a feature by the slug of a name in a scope, or create it as manual.
     *
     * @throws ValidationException When the name has nothing to build a slug from.
     */
    private function findOrCreate(FeatureScope $scope, string $name): TaskFeature
    {
        $name = trim(Str::limit($name, 120, ''));
        $slug = Str::limit(Str::slug($name), 120, '');

        if ($slug === '') {
            throw ValidationException::withMessages(['new_name' => 'The feature name needs at least one letter or number.']);
        }

        $existing = $scope->applyTo(TaskFeature::query())->where('slug', $slug)->first();

        if ($existing !== null) {
            return $existing->resolveMerged();
        }

        return TaskFeature::query()->create([
            'project_id' => $scope->projectId,
            'git_repository_id' => $scope->repositoryId,
            'scope_key' => $scope->key(),
            'name' => $name,
            'slug' => $slug,
            'source' => AttributionSource::Manual,
        ]);
    }
}
