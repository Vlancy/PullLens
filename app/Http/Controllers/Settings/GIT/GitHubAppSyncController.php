<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Enums\GIT\GitProvider;
use App\Http\Controllers\Controller;
use App\Repositories\Contracts\GIT\GitProviderAppRepositoryInterface;
use App\Services\Git\GitHubApiClient;
use Illuminate\Http\RedirectResponse;
use Throwable;

class GitHubAppSyncController extends Controller
{
    public function __construct(private readonly GitProviderAppRepositoryInterface $providerApps) {}

    public function __invoke(GitHubApiClient $api): RedirectResponse
    {
        $app = $this->providerApps->findByProvider(GitProvider::Github);

        if (! $app || ! $app->private_key || ! $app->app_id) {
            return to_route('integrations.edit')
                ->with('status', 'No configured GitHub App to sync.');
        }

        try {
            $data = $api->getApp($app);

            $this->providerApps->updateOrCreateForProvider(GitProvider::Github, [
                'name' => (string) data_get($data, 'name', $app->name),
                'slug' => (string) data_get($data, 'slug', $app->slug),
                'app_id' => (string) data_get($data, 'id', $app->app_id),
            ]);
        } catch (Throwable) {
            return to_route('integrations.edit')
                ->with('status', 'Sync failed — check your App ID and private key.');
        }

        return to_route('integrations.edit')
            ->with('status', 'GitHub App synced successfully.');
    }
}
