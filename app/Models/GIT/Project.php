<?php

namespace App\Models\GIT;

use Database\Factories\GIT\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named group of repositories that belong to one product or client.
 */
#[Fillable(['name', 'description'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids;

    /**
     * The repositories assigned to this project.
     *
     * @return HasMany<GitRepository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(GitRepository::class);
    }
}
