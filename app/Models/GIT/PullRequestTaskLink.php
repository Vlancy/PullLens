<?php

namespace App\Models\GIT;

use App\Enums\GIT\TaskRelation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directed relationship between two tasks: the newer one and the earlier one it
 * fixes, extends, reverts or duplicates.
 */
#[Fillable([
    'task_id',
    'related_task_id',
    'relation',
    'reason',
    'confidence',
    'source',
])]
class PullRequestTaskLink extends Model
{
    /** Link proposed by the reviewer. Replaceable on the next review. */
    public const SOURCE_AI = 'ai';

    /** Link created or corrected by a person. Never overwritten by a review. */
    public const SOURCE_MANUAL = 'manual';

    protected $table = 'pull_request_task_links';

    /**
     * Attribute casts for this model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation' => TaskRelation::class,
            'confidence' => 'float',
        ];
    }

    /**
     * The newer task — the one doing the fixing, extending or reverting.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(PullRequestTask::class, 'task_id');
    }

    /**
     * The earlier task being acted upon.
     */
    public function relatedTask(): BelongsTo
    {
        return $this->belongsTo(PullRequestTask::class, 'related_task_id');
    }
}
