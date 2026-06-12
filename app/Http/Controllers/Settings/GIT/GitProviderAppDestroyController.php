<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Enums\GIT\GitProvider;
use App\Http\Controllers\Controller;
use App\Repositories\Contracts\GIT\GitAccountRepositoryInterface;
use App\Repositories\Contracts\GIT\GitProviderAppRepositoryInterface;
use App\Services\Git\GitHubAppInstallationCleaner;
use App\Services\Git\GitProviderAppConfigurator;
use Illuminate\Http\RedirectResponse;

class GitProviderAppDestroyController extends Controller
{
    /**
     * Delete a system-level provider app configuration and its connected accounts.
     *
     * For GitHub the app is first uninstalled from every account (GitHub has no
     * API to delete the registration itself), then local credentials and accounts
     * are removed. The operator finishes by deleting the registration on GitHub.
     */
    public function __invoke(
        string $provider,
        GitProviderAppConfigurator $apps,
        GitHubAppInstallationCleaner $cleaner,
        GitProviderAppRepositoryInterface $providerApps,
        GitAccountRepositoryInterface $gitAccounts,
    ): RedirectResponse {
        $gitProvider = GitProvider::tryFrom($provider) ?? abort(404);

        $app = $apps->configuredApp($gitProvider);

        if ($gitProvider === GitProvider::Github && $app !== null) {
            $cleaner->uninstallAll($app);
        }

        $providerApps->deleteForProvider($gitProvider);
        $gitAccounts->deleteForProvider($gitProvider);

        return to_route('git-providers.edit')
            ->with('status', $gitProvider->label().' app uninstalled from all accounts and removed from PullLens. Delete the app on GitHub to finish.');
    }
}
