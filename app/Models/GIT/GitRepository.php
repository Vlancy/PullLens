<?php

namespace App\Models\GIT;

use App\Enums\GIT\GitProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'git_account_id',
    'provider',
    'installation_id',
    'provider_repo_id',
    'owner_login',
    'owner_type',
    'name',
    'full_name',
    'default_branch',
    'is_private',
    'web_url',
    'selected_at',
    'reviews_enabled',
    'auto_review_on_open',
    'auto_approve',
    'auto_apply_labels',
    'allow_comment_replies',
    'auto_merge',
    'auto_merge_method',
    'review_language',
    'base_branches',
    'tracked_branches',
])]
class GitRepository extends Model
{
    /**
     * Return casts for the provider enum, identifiers, flags, and timestamps.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => GitProvider::class,
            'installation_id' => 'integer',
            'provider_repo_id' => 'integer',
            'is_private' => 'boolean',
            'selected_at' => 'datetime',
            'reviews_enabled' => 'boolean',
            'auto_review_on_open' => 'boolean',
            'auto_approve' => 'boolean',
            'auto_apply_labels' => 'boolean',
            'allow_comment_replies' => 'boolean',
            'auto_merge' => 'boolean',
            'base_branches' => 'array',
            'tracked_branches' => 'array',
        ];
    }

    /**
     * The operator account whose token was used to select this repository.
     *
     * @return BelongsTo<GitAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(GitAccount::class, 'git_account_id');
    }

    /**
     * Branches discovered for this repository at selection time.
     *
     * @return HasMany<GitRepositoryBranch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(GitRepositoryBranch::class);
    }
}
