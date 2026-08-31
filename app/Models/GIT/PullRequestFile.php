<?php

namespace App\Models\GIT;

use App\Enums\GIT\PullRequestFileStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pull_request_id',
    'filename',
    'previous_filename',
    'status',
    'additions',
    'deletions',
    'language',
    'patch',
])]
class PullRequestFile extends Model
{
    use HasUuids;

    /**
     * Attribute casts for this model.
     *      *
     *      * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PullRequestFileStatus::class,
            'additions' => 'integer',
            'deletions' => 'integer',
        ];
    }

    /**
     * The pull request this pull request file belongs to.
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }
}
