<?php

namespace App\Services\Git;

use App\Models\GIT\GitAccount;
use App\Models\GIT\GitProviderApp;
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
     * List ALL installations and their repositories using the GitHub App's own credentials.
     *
     * Unlike browse(), this does not require a connected user account — the App
     * authenticates directly with its private key and sees every installation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function browseAsApp(GitProviderApp $app): array
    {
        return collect($this->api->appInstallations($app))
            ->map(function (array $installation) use ($app): array {
                $installationId = (int) data_get($installation, 'id');

                return [
                    'installation_id' => $installationId,
                    'account_login' => (string) data_get($installation, 'account.login'),
                    'account_type' => (string) data_get($installation, 'account.type'),
                    'account_avatar_url' => data_get($installation, 'account.avatar_url'),
                    'repositories' => collect($this->api->appInstallationRepositories($app, $installationId))
                        ->map(fn (array $repository): array => [
                            'provider_repo_id' => (int) data_get($repository, 'id'),
                            'name' => (string) data_get($repository, 'name'),
                            'full_name' => (string) data_get($repository, 'full_name'),
                            'default_branch' => (string) data_get($repository, 'default_branch'),
                            'private' => (bool) data_get($repository, 'private'),
                            'web_url' => data_get($repository, 'html_url'),
                        ])
                        ->values()
                        ->all(),
                ];
            })
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
        return $this->buildCatalog($this->browse($account));
    }

    /**
     * Same as catalog() but uses the App's own JWT credentials instead of a user
     * OAuth token — sees every installation regardless of connected accounts.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function catalogAsApp(GitProviderApp $app): Collection
    {
        return $this->buildCatalog($this->browseAsApp($app));
    }

    /**
     * @param  array<int, array<string, mixed>>  $installations
     * @return Collection<int, array<string, mixed>>
     */
    private function buildCatalog(array $installations): Collection
    {
        $catalog = collect();

        foreach ($installations as $installation) {
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
