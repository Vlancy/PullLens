<?php

namespace App\Models\GIT;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'git_repository_id',
    'name',
    'commit_sha',
    'is_protected',
    'is_default',
])]
class GitRepositoryBranch extends Model
{
    use HasUuids;

    /**
     * Return casts for branch protection and default flags.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_protected' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * The repository this branch belongs to.
     *
     * @return BelongsTo<GitRepository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
    }
}
