<?php

namespace App\Repositories\Contracts\GIT;

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitProviderApp;
use App\Repositories\Contracts\RepositoryInterface;

interface GitProviderAppRepositoryInterface extends RepositoryInterface
{
    /**
     * Find the system-level application credentials for a provider.
     */
    public function findByProvider(GitProvider $provider): ?GitProviderApp;

    /**
     * Create or update system-level application credentials for a provider.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateOrCreateForProvider(GitProvider $provider, array $values): GitProviderApp;

    /**
     * Delete the system-level application credentials for a provider.
     */
    public function deleteForProvider(GitProvider $provider): int;
}
