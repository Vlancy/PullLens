<?php

namespace App\Models\GIT;

use App\Enums\GIT\ContributorRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pull_request_id',
    'login',
    'name',
    'email',
    'avatar_url',
    'provider_user_id',
    'role',
    'commit_count',
    'comment_count',
])]
class PullRequestContributor extends Model
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
            'role' => ContributorRole::class,
            'commit_count' => 'integer',
            'comment_count' => 'integer',
        ];
    }

    /**
     * The pull request this pull request contributor belongs to.
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }
}
