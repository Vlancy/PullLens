<?php

namespace App\Services\Tasks\Features;

use App\Enums\GIT\AttributionSource;
use App\Models\GIT\TaskFeature;
use App\Support\Tasks\FeatureScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Maps the feature the AI named for a task onto a stored feature in a scope.
 */
class FeatureResolver
{
    /**
     * Find or create the feature for an AI suggestion, following merges.
     *
     * @param  array{existing_slug?: ?string, new_name?: ?string, new_description?: ?string}  $feature
     */
    public function resolve(FeatureScope $scope, array $feature): ?TaskFeature
    {
        $existingSlug = trim((string) ($feature['existing_slug'] ?? ''));
        $newName = trim((string) ($feature['new_name'] ?? ''));

        if ($existingSlug !== '') {
            $found = $this->find($scope, $existingSlug);

            if ($found !== null) {
                return $found->resolveMerged();
            }
        }

        $name = $newName !== '' ? $newName : Str::of($existingSlug)->replace(['-', '_'], ' ')->trim()->ucfirst()->toString();
        $name = trim(Str::limit($name, 120, ''));
        $slug = Str::limit(Str::slug($name), 120, '');

        if ($name === '' || $slug === '') {
            return null;
        }

        $found = $this->find($scope, $slug);

        if ($found !== null) {
            return $found->resolveMerged();
        }

        $description = trim((string) ($feature['new_description'] ?? ''));

        try {
            // Its own (savepoint) transaction: on PostgreSQL a unique violation aborts
            // the surrounding transaction unless rolled back to a savepoint first.
            return DB::transaction(fn (): TaskFeature => TaskFeature::query()->create([
                'project_id' => $scope->projectId,
                'git_repository_id' => $scope->repositoryId,
                'scope_key' => $scope->key(),
                'name' => $name,
                'slug' => $slug,
                'description' => $description !== '' ? $description : null,
                'source' => AttributionSource::Ai,
            ]))->resolveMerged();
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created it first.
            return $this->find($scope, $slug)?->resolveMerged();
        }
    }

    /**
     * Look up a feature by slug within a scope, merged ones included.
     *
     * A renamed feature keeps its original slug, so when no slug matches, the feature
     * whose current name slugs to the same value is used instead (unmerged ones first).
     *
     * @param  FeatureScope  $scope  The project or repository to look in.
     * @param  string  $slug  The slug to match.
     * @return ?TaskFeature The matching feature, not merge-resolved.
     */
    public function find(FeatureScope $scope, string $slug): ?TaskFeature
    {
        $bySlug = $scope->applyTo(TaskFeature::query())->where('slug', $slug)->first();

        if ($bySlug !== null) {
            return $bySlug;
        }

        return $scope->applyTo(TaskFeature::query())
            ->get()
            ->sortBy(fn (TaskFeature $feature): int => $feature->merged_into_id === null ? 0 : 1)
            ->first(fn (TaskFeature $feature): bool => Str::slug($feature->name) === $slug);
    }
}
