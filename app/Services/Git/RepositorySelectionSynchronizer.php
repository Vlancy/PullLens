<?php

namespace App\Services\Git;

use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Repositories\Contracts\GIT\GitRepositoryRepositoryInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

class RepositorySelectionSynchronizer
{
    /**
     * Create the synchronizer with transaction, browsing, API, and persistence deps.
     */
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AvailableRepositoryBrowser $browser,
        private readonly GitHubApiClient $api,
        private readonly GitRepositoryRepositoryInterface $repositories,
        private readonly GitHubWebhookRegistrar $webhookRegistrar,
    ) {}

    /**
     * Persist the operator's repository selection for an account.
     *
     * The incoming selection is treated as the complete desired set for the
     * account: missing repositories are untracked, and each selected repository
     * is stored with authoritative metadata plus its discovered branches.
     *
     * @param  array<int, array<string, mixed>>  $selections  Items with provider_repo_id keys.
     * @return Collection<int, GitRepository>
     */
    public function sync(GitAccount $account, array $selections): Collection
    {
        // Re-fetch authoritative repository metadata so client input is never trusted.
        $catalog = $this->browser->catalog($account);

        $selectedIds = collect($selections)
            ->pluck('provider_repo_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $catalog->has($id))
            ->unique()
            ->values();

        // Load repos about to be removed so we can clean up their webhooks after the
        // transaction commits. We do this before the delete to avoid loading nothing.
        $removedRepos = $this->repositories->findRemovedForAccount($account, $selectedIds->all());

        $synced = $this->database->transaction(function () use ($account, $selectedIds, $catalog): Collection {
            $this->repositories->deleteForAccountExcept($account, $selectedIds->all());

            return $selectedIds->map(function (int $providerRepoId) use ($account, $catalog): GitRepository {
                /** @var array<string, mixed> $repository */
                $repository = $catalog->get($providerRepoId);

                $model = $this->repositories->updateOrCreateForAccount($account, $providerRepoId, [
                    'provider' => $account->provider,
                    'installation_id' => $repository['installation_id'],
                    'owner_login' => $repository['owner_login'],
                    'owner_type' => $repository['owner_type'],
                    'name' => $repository['name'],
                    'full_name' => $repository['full_name'],
                    'default_branch' => $repository['default_branch'],
                    'is_private' => $repository['private'],
                    'web_url' => $repository['web_url'],
                ]);

                // Track the default branch out of the box for newly selected
                // repositories, leaving an existing operator's choice untouched.
                if ($model->wasRecentlyCreated && filled($model->default_branch)) {
                    $model = $this->repositories->update($model, [
                        'tracked_branches' => [$model->default_branch],
                    ]);
                }

                $this->repositories->replaceBranches($model, $this->branches($account, $model));

                return $model;
            });
        });

        // Webhook calls are outside the transaction — HTTP failures must not roll back DB state.
        $removedRepos->each(fn (GitRepository $repo) => $this->webhookRegistrar->removeWebhook($repo));
        $synced->each(fn (GitRepository $repo) => $this->webhookRegistrar->ensureWebhook($repo));

        return $synced;
    }

    /**
     * Fetch and normalize the branches for a repository, flagging the default branch.
     *
     * @return array<int, array<string, mixed>>
     */
    private function branches(GitAccount $account, GitRepository $repository): array
    {
        [$owner, $name] = explode('/', $repository->full_name, 2);

        return collect($this->api->branches($account, $owner, $name))
            ->map(fn (array $branch): array => [
                'name' => (string) data_get($branch, 'name'),
                'commit_sha' => data_get($branch, 'commit.sha'),
                'is_protected' => (bool) data_get($branch, 'protected', false),
                'is_default' => data_get($branch, 'name') === $repository->default_branch,
            ])
            ->values()
            ->all();
    }
}
