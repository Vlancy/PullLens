<?php

namespace App\Services\Git;

use App\Models\GIT\GitAccount;
use Illuminate\Support\Collection;

class AvailableRepositoryBrowser
{
    /**
     * Create the browser with the GitHub API client dependency.
     */
    public function __construct(private readonly GitHubApiClient $api) {}

    /**
     * List installations the account can access, each grouped with its repositories.
     *
     * The result is grouped by installation (personal account and organizations)
     * so the UI can present a repository picker per owner.
     *
     * @return array<int, array<string, mixed>>
     */
    public function browse(GitAccount $account): array
    {
        return collect($this->api->installations($account))
            ->map(function (array $installation) use ($account): array {
                $installationId = (int) data_get($installation, 'id');

                return [
                    'installation_id' => $installationId,
                    'account_login' => (string) data_get($installation, 'account.login'),
                    'account_type' => (string) data_get($installation, 'account.type'),
                    'account_avatar_url' => data_get($installation, 'account.avatar_url'),
                    'repositories' => $this->repositories($account, $installationId),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Normalize the repositories reachable through a single installation.
     *
     * @return array<int, array<string, mixed>>
     */
    private function repositories(GitAccount $account, int $installationId): array
    {
        return collect($this->api->installationRepositories($account, $installationId))
            ->map(fn (array $repository): array => [
                'provider_repo_id' => (int) data_get($repository, 'id'),
                'name' => (string) data_get($repository, 'name'),
                'full_name' => (string) data_get($repository, 'full_name'),
                'default_branch' => (string) data_get($repository, 'default_branch'),
                'private' => (bool) data_get($repository, 'private'),
                'web_url' => data_get($repository, 'html_url'),
            ])
            ->values()
            ->all();
    }

    /**
     * Build a lookup of repository metadata keyed by GitHub repository id.
     *
     * Each entry carries the owning installation context so a saved selection
     * can be persisted with authoritative server-fetched values.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function catalog(GitAccount $account): Collection
    {
        $catalog = collect();

        foreach ($this->browse($account) as $installation) {
            foreach ($installation['repositories'] as $repository) {
                $catalog->put($repository['provider_repo_id'], $repository + [
                    'installation_id' => $installation['installation_id'],
                    'owner_login' => $installation['account_login'],
                    'owner_type' => $installation['account_type'],
                ]);
            }
        }

        return $catalog;
    }
}
