<?php

namespace App\Models\GIT;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pull_request_id',
    'git_repository_id',
    'event_type',
    'actor_login',
    'actor_type',
    'payload',
    'occurred_at',
])]
class PullRequestEvent extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
    }
}
