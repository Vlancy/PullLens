<?php

namespace App\Services\Git;

use App\Models\GIT\GitAccount;
use App\Models\GIT\GitProviderApp;
use App\Models\GIT\GitRepository;

/**
 * Resolves the credential a GitHub call for a repository should be made with.
 *
 * A GitHub App installation token is preferred: it is scoped to the installation
 * and does not spend the connecting user's rate limit. The connected account's
 * own token is the fallback for repositories tracked without an app.
 */
class GitHubCallerResolver
{
    /**
     * Inject the API client this class delegates to.
     */
    public function __construct(private readonly GitHubApiClient $api) {}

    /**
     * Return the installation token for a repository, or its account as the caller.
     */
    public function for(GitRepository $repository): GitAccount|string|null
    {
        $app = GitProviderApp::where('provider', 'github')->first();

        if ($app?->private_key && $repository->installation_id) {
            $token = $this->api->installationToken($app, (int) $repository->installation_id);

            if ($token !== '') {
                return $token;
            }
        }

        return $repository->account;
    }
}
