<?php

namespace App\Models\System;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model over Laravel's `failed_jobs` table.
 *
 * The queue writes these rows itself, so the application never creates or updates
 * them — this exists purely so the health checks on the dashboard can be expressed
 * as Eloquent scopes instead of raw query-builder calls scattered across a controller.
 */
class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    /** The queue owns this table; nothing here may write to it. */
    protected $guarded = ['*'];

    public $timestamps = false;

    /**
     * Attribute casts for this model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['failed_at' => 'datetime'];
    }

    /**
     * Only failures recorded since the given moment.
     *
     * @param  Builder<FailedJob>  $query
     * @return Builder<FailedJob>
     */
    public function scopeFailedSince(Builder $query, \DateTimeInterface $since): Builder
    {
        return $query->where('failed_at', '>=', $since);
    }

    /**
     * Failures whose serialized payload mentions the given job class.
     *
     * A LIKE against the payload is the only option available: the queue stores the
     * job as an opaque serialized blob with no class column to index.
     *
     * @param  Builder<FailedJob>  $query
     * @return Builder<FailedJob>
     */
    public function scopeForJob(Builder $query, string $jobClass): Builder
    {
        return $query->where('payload', 'like', '%'.class_basename($jobClass).'%');
    }

    /**
     * Failures whose exception text contains any of the given fragments.
     *
     * Matching is case-insensitive so callers do not have to enumerate capitalisation
     * variants of the same provider message.
     *
     * @param  Builder<FailedJob>  $query
     * @param  array<int, string>  $needles
     * @return Builder<FailedJob>
     */
    public function scopeExceptionContainsAny(Builder $query, array $needles): Builder
    {
        return $query->where(function (Builder $inner) use ($needles): void {
            foreach ($needles as $needle) {
                $inner->orWhere('exception', 'ilike', '%'.addcslashes($needle, '%_\\').'%');
            }
        });
    }
}
