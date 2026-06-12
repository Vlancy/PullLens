<?php

namespace App\Repositories\Contracts\GIT;

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface GitRepositoryRepositoryInterface extends RepositoryInterface
{
    /**
     * Return tracked repositories for a provider with branches eager loaded.
     *
     * @return Collection<int, GitRepository>
     */
    public function forProvider(GitProvider $provider): Collection;

    /**
     * Create or update a tracked repository for an account by provider repository id.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateOrCreateForAccount(GitAccount $account, int $providerRepoId, array $values): GitRepository;

    /**
     * Remove tracked repositories for an account that are not in the given id set.
     *
     * @param  array<int, int>  $providerRepoIds
     */
    public function deleteForAccountExcept(GitAccount $account, array $providerRepoIds): int;

    /**
     * Replace the stored branches for a repository with a fresh set.
     *
     * @param  array<int, array<string, mixed>>  $branches
     */
    public function replaceBranches(GitRepository $repository, array $branches): void;

    /**
     * Find a tracked repository by its provider numeric ID and provider slug.
     */
    public function findByProviderRepoId(string $provider, int $providerRepoId): ?GitRepository;

    /**
     * Return the repositories for an account that are NOT in the given provider ID set.
     * Used to load repos about to be untracked before the delete runs.
     *
     * @param  array<int, int>  $keepProviderRepoIds
     * @return Collection<int, GitRepository>
     */
    public function findRemovedForAccount(GitAccount $account, array $keepProviderRepoIds): Collection;
}
