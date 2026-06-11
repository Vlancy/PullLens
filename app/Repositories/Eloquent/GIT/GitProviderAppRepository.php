<?php

namespace App\Repositories\Eloquent\GIT;

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitProviderApp;
use App\Repositories\Contracts\GIT\GitProviderAppRepositoryInterface;
use App\Repositories\Eloquent\BaseRepository;

/**
 * @extends BaseRepository<GitProviderApp>
 */
class GitProviderAppRepository extends BaseRepository implements GitProviderAppRepositoryInterface
{
    protected string $model = GitProviderApp::class;

    /**
     * Find the system-level application credentials for a provider.
     */
    public function findByProvider(GitProvider $provider): ?GitProviderApp
    {
        return $this->query()
            ->where('provider', $provider)
            ->first();
    }

    /**
     * Create or update system-level application credentials for a provider.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateOrCreateForProvider(GitProvider $provider, array $values): GitProviderApp
    {
        return $this->query()->updateOrCreate(
            ['provider' => $provider->value],
            $values,
        );
    }

    /**
     * Delete the system-level application credentials for a provider.
     */
    public function deleteForProvider(GitProvider $provider): int
    {
        return $this->query()
            ->where('provider', $provider)
            ->delete();
    }
}
