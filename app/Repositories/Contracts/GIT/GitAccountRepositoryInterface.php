<?php

namespace App\Repositories\Contracts\GIT;

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitAccount;
use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface GitAccountRepositoryInterface extends RepositoryInterface
{
    /**
     * Return connected Git accounts ordered by newest connection first.
     *
     * @return Collection<int, GitAccount>
     */
    public function latestConnected(): Collection;

    /**
     * Find a provider account while locking it for a transactional refresh.
     */
    public function findForUpdate(GitProvider $provider, string $providerUserId): ?GitAccount;

    /**
     * Delete all connected system accounts for a provider.
     */
    public function deleteForProvider(GitProvider $provider): int;
}
