<?php

namespace App\Models\GIT;

use App\Enums\GIT\AttributionSource;
use App\Enums\GIT\RepositoryTagKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A short profile tag describing a repository, set by the AI or by a person.
 */
#[Fillable(['git_repository_id', 'name', 'kind', 'source', 'dismissed'])]
class GitRepositoryTag extends Model
{
    use HasUuids;

    /**
     * Return casts for the kind and source enums and the dismissed flag.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RepositoryTagKind::class,
            'source' => AttributionSource::class,
            'dismissed' => 'boolean',
        ];
    }

    /**
     * The repository this tag describes.
     *
     * @return BelongsTo<GitRepository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
    }
}
