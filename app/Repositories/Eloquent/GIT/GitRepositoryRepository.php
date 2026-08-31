<?php

namespace App\Repositories\Eloquent\GIT;

use App\Enums\GIT\GitProvider;
use App\Enums\GIT\PullRequestState;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Repositories\Contracts\GIT\GitRepositoryRepositoryInterface;
use App\Repositories\Eloquent\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<GitRepository>
 */
class GitRepositoryRepository extends BaseRepository implements GitRepositoryRepositoryInterface
{
    protected string $model = GitRepository::class;

    /**
     * Return tracked repositories for a provider with branches eager loaded.
     *
     * @return Collection<int, GitRepository>
     */
    public function forProvider(GitProvider $provider): Collection
    {
        return $this->query()
            ->where('provider', $provider)
            ->with('branches')
            ->withCount([
                'pullRequests as open_prs_count' => fn ($q) => $q->where('state', PullRequestState::Open->value),
                'pullRequests as draft_prs_count' => fn ($q) => $q->where('state', PullRequestState::Draft->value),
                'pullRequests as merged_prs_count' => fn ($q) => $q->where('state', PullRequestState::Merged->value),
                'pullRequests as closed_prs_count' => fn ($q) => $q->where('state', PullRequestState::Closed->value),
            ])
            ->orderBy('full_name')
            ->get();
    }

    /**
     * Create or update a tracked repository for an account by provider repository id.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateOrCreateForAccount(GitAccount $account, int $providerRepoId, array $values): GitRepository
    {
        return $this->query()->updateOrCreate(
            [
                'git_account_id' => $account->id,
                'provider_repo_id' => $providerRepoId,
            ],
            $values,
        );
    }

    /**
     * Remove tracked repositories for an account that are not in the given id set.
     *
     * @param  array<int, int>  $providerRepoIds
     */
    public function deleteForAccountExcept(GitAccount $account, array $providerRepoIds): int
    {
        return $this->query()
            ->where('git_account_id', $account->id)
            ->when($providerRepoIds !== [], fn ($query) => $query->whereNotIn('provider_repo_id', $providerRepoIds))
            ->delete();
    }

    /**
     * Replace the stored branches for a repository with a fresh set.
     *
     * @param  array<int, array<string, mixed>>  $branches
     */
    public function replaceBranches(GitRepository $repository, array $branches): void
    {
        $repository->branches()->delete();

        if ($branches !== []) {
            $repository->branches()->createMany($branches);
        }
    }

    /**
     * Find a tracked repository by its provider numeric ID and provider slug.
     * Used by the webhook controller to match incoming events to tracked repositories.
     */
    public function findByProviderRepoId(string $provider, int $providerRepoId): ?GitRepository
    {
        return $this->query()
            ->where('provider', $provider)
            ->where('provider_repo_id', $providerRepoId)
            ->first();
    }

    /**
     * Return tracked repositories for an account whose provider_repo_id is NOT in the keep list.
     * Called before deleteForAccountExcept so callers can act on them (e.g. remove webhooks).
     *
     * @param  array<int, int>  $keepProviderRepoIds
     * @return Collection<int, GitRepository>
     */
    public function findRemovedForAccount(GitAccount $account, array $keepProviderRepoIds): Collection
    {
        return $this->query()
            ->where('git_account_id', $account->id)
            ->when(
                $keepProviderRepoIds !== [],
                fn ($q) => $q->whereNotIn('provider_repo_id', $keepProviderRepoIds),
            )
            ->get();
    }
}
