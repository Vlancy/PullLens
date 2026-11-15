<?php

namespace App\Models\GIT;

use App\Enums\GIT\AttributionSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A feature that groups related tasks, scoped to a project or, without one, a repository.
 */
#[Fillable([
    'project_id',
    'git_repository_id',
    'scope_key',
    'name',
    'slug',
    'description',
    'source',
    'merged_into_id',
])]
class TaskFeature extends Model
{
    use HasUuids;

    /**
     * Attribute casts for this model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => AttributionSource::class,
        ];
    }

    /**
     * The project this feature is scoped to, if any.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The repository this feature is scoped to, when the repository has no project.
     *
     * @return BelongsTo<GitRepository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
    }

    /**
     * The feature this one was merged into, if any.
     *
     * @return BelongsTo<TaskFeature, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /**
     * The tasks grouped under this feature.
     *
     * @return HasMany<PullRequestTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(PullRequestTask::class);
    }

    /**
     * Only features that have not been merged into another.
     *
     * @param  Builder<TaskFeature>  $query
     * @return Builder<TaskFeature>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('merged_into_id');
    }

    /**
     * Follow the merge chain to the surviving feature.
     *
     * Stops after 10 hops or on a cycle, returning the last feature reached.
     */
    public function resolveMerged(): TaskFeature
    {
        $current = $this;
        $seen = [$current->id => true];

        for ($hops = 0; $hops < 10 && $current->merged_into_id !== null; $hops++) {
            if (isset($seen[$current->merged_into_id])) {
                break;
            }

            $next = self::query()->find($current->merged_into_id);

            if ($next === null) {
                break;
            }

            $seen[$next->id] = true;
            $current = $next;
        }

        return $current;
    }
}
