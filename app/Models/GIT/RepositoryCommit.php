<?php

namespace App\Models\GIT;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'git_repository_id',
    'sha',
    'pull_request_id',
    'branch',
    'author_login',
    'author_name',
    'author_email',
    'author_avatar_url',
    'message',
    'additions',
    'deletions',
    'changed_files_count',
    'committed_at',
    'stats_synced',
])]
class RepositoryCommit extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'additions' => 'integer',
            'deletions' => 'integer',
            'changed_files_count' => 'integer',
            'committed_at' => 'datetime',
            'stats_synced' => 'boolean',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
    }

    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }
}
