<?php

namespace App\Services\Git;

use App\Models\GIT\GitAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitHubApiClient
{
    private const API_BASE = 'https://api.github.com';

    private const PER_PAGE = 100;

    /**
     * Cap pagination so an unexpected response cannot loop indefinitely.
     */
    private const MAX_PAGES = 20;

    /**
     * List the GitHub App installations the account's user token can access.
     *
     * Covers both the personal account and any organization the user belongs to.
     *
     * @return array<int, array<string, mixed>>
     */
    public function installations(GitAccount $account): array
    {
        return $this->paginate($account, '/user/installations', 'installations');
    }

    /**
     * List repositories the user can reach through a specific installation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function installationRepositories(GitAccount $account, int $installationId): array
    {
        return $this->paginate(
            $account,
            "/user/installations/{$installationId}/repositories",
            'repositories',
        );
    }

    /**
     * List branches for a repository identified by owner and name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function branches(GitAccount $account, string $owner, string $repo): array
    {
        return $this->paginate($account, "/repos/{$owner}/{$repo}/branches", null);
    }

    /**
     * Fetch every page of a GitHub list endpoint and flatten the results.
     *
     * @param  string|null  $key  Response key holding the list, or null for a bare array.
     * @return array<int, array<string, mixed>>
     */
    private function paginate(GitAccount $account, string $path, ?string $key): array
    {
        $items = [];
        $page = 1;

        do {
            $response = $this->request($account)
                ->get(self::API_BASE.$path, [
                    'per_page' => self::PER_PAGE,
                    'page' => $page,
                ])
                ->throw()
                ->json();

            $batch = $key !== null ? (array) data_get($response, $key, []) : (array) $response;
            $items = array_merge($items, $batch);
            $page++;
        } while (count($batch) === self::PER_PAGE && $page <= self::MAX_PAGES);

        return $items;
    }

    /**
     * Build an authenticated GitHub request using the account's user-to-server token.
     */
    private function request(GitAccount $account): PendingRequest
    {
        return Http::withToken($account->access_token)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);
    }
}
