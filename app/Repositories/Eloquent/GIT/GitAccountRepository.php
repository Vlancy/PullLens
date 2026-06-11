<?php

namespace App\Repositories\Eloquent\GIT;

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitAccount;
use App\Repositories\Contracts\GIT\GitAccountRepositoryInterface;
use App\Repositories\Eloquent\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<GitAccount>
 */
class GitAccountRepository extends BaseRepository implements GitAccountRepositoryInterface
{
    protected string $model = GitAccount::class;

    /**
     * Return connected Git accounts ordered by newest connection first.
     *
     * @return Collection<int, GitAccount>
     */
    public function latestConnected(): Collection
    {
        return $this->query()
            ->latest('connected_at')
            ->get();
    }

    /**
     * Find a provider account while locking it for a transactional refresh.
     */
    public function findForUpdate(GitProvider $provider, string $providerUserId): ?GitAccount
    {
        return $this->query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Delete all connected system accounts for a provider.
     */
    public function deleteForProvider(GitProvider $provider): int
    {
        return $this->query()
            ->where('provider', $provider)
            ->delete();
    }
}
