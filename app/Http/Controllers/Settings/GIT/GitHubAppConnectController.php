<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Enums\GIT\GitProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\GIT\ConnectGitHubAppRequest;
use App\Repositories\Contracts\GIT\GitProviderAppRepositoryInterface;
use Illuminate\Http\RedirectResponse;

class GitHubAppConnectController extends Controller
{
    /**
     * Inject the git provider app repository interface this class delegates to.
     */
    public function __construct(private readonly GitProviderAppRepositoryInterface $providerApps) {}

    /**
     * Handle the request and redirect back to the caller.
     */
    public function __invoke(ConnectGitHubAppRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->providerApps->updateOrCreateForProvider(GitProvider::Github, [
            'name' => $data['slug'],
            'app_id' => $data['app_id'],
            'slug' => $data['slug'],
            'client_id' => $data['client_id'],
            'client_secret' => $data['client_secret'],
            'webhook_secret' => $data['webhook_secret'] ?? null,
            'private_key' => $data['private_key'],
            'configured_at' => now(),
        ]);

        return to_route('integrations.edit')
            ->with('status', 'GitHub App connected. Click "Sync" to pull the app name from GitHub.');
    }
}
