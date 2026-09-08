<?php

namespace App\Models\GIT;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pull_request_id',
    'sha',
    'short_sha',
    'message',
    'author_login',
    'author_name',
    'author_email',
    'author_avatar_url',
    'committed_at',
    'additions',
    'deletions',
    'changed_files_count',
    'files',
])]
class PullRequestCommit extends Model
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
            'committed_at' => 'datetime',
            'additions' => 'integer',
            'deletions' => 'integer',
            'changed_files_count' => 'integer',
            'files' => 'array',
        ];
    }

    /**
     * The pull request this pull request commit belongs to.
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }
}
