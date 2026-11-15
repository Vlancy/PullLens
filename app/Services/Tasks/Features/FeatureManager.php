<?php

namespace App\Services\Tasks\Features;

use App\Enums\GIT\AttributionSource;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequestTask;
use App\Models\GIT\TaskFeature;
use App\Support\Tasks\FeatureScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * Inject the resolver used to find features by slug or current name.
     *
     * @param  FeatureResolver  $resolver  Looks features up within a scope.
     */
    public function __construct(private readonly FeatureResolver $resolver) {}

    /**
     * Rename a feature and replace its description, marking it manual.
     *
     * The slug is left alone so the AI's existing references keep resolving to it.
     *
     * @param  TaskFeature  $feature  The feature to rename.
     * @param  string  $name  The new display name.
     * @param  ?string  $description  The new description; null clears it.
     * @return TaskFeature The renamed feature.
     *
     * @throws ValidationException When the feature was merged away, the name has nothing to
     *                             build a slug from, or it matches another feature in the same scope.
     */
    public function rename(TaskFeature $feature, string $name, ?string $description): TaskFeature
    {
        if ($feature->merged_into_id !== null) {
            throw ValidationException::withMessages(['name' => 'This feature was merged into another one and can no longer be renamed.']);
        }

        $slug = Str::slug(trim($name));

        if ($slug === '') {
            throw ValidationException::withMessages(['name' => 'The feature name needs at least one letter or number.']);
        }

        // Another feature clashes when its slug or its current name slugs the same, unless
        // it was merged into this one and so already resolves here.
        $clash = TaskFeature::query()
            ->where('scope_key', $feature->scope_key)
            ->whereKeyNot($feature->id)
            ->get()
            ->contains(fn (TaskFeature $other): bool => ($other->slug === $slug || Str::slug($other->name) === $slug)
                && ! $other->resolveMerged()->is($feature));

        if ($clash) {
            throw ValidationException::withMessages(['name' => 'Another feature in this project or repository already uses that name.']);
        }

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
     * Delete a scope's AI features nothing uses any more: no tasks, and no features
     * merged into them.
     *
     * @param  FeatureScope|string|null  $scope  The scope to prune, or a project id; null prunes nothing.
     * @return int The number of features deleted.
     */
    public function pruneEmpty(FeatureScope|string|null $scope = null): int
    {
        if ($scope === null) {
            return 0;
        }

        $scope = is_string($scope) ? new FeatureScope($scope, null) : $scope;

        return $scope->applyTo(TaskFeature::query())
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
     * Find a feature by the slug of a name (or a renamed feature's current name) in a
     * scope, or create it as manual.
     *
     * @param  FeatureScope  $scope  The scope to look in and create in.
     * @param  string  $name  The feature name.
     * @return TaskFeature The existing (merge-resolved) or new feature.
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

        $existing = $this->resolver->find($scope, $slug);

        if ($existing !== null) {
            return $existing->resolveMerged();
        }

        try {
            // Its own (savepoint) transaction: on PostgreSQL a unique violation aborts
            // the surrounding transaction unless rolled back to a savepoint first.
            return DB::transaction(fn (): TaskFeature => TaskFeature::query()->create([
                'project_id' => $scope->projectId,
                'git_repository_id' => $scope->repositoryId,
                'scope_key' => $scope->key(),
                'name' => $name,
                'slug' => $slug,
                'source' => AttributionSource::Manual,
            ]));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created it first.
            return $this->resolver->find($scope, $slug)->resolveMerged();
        }
    }
}
