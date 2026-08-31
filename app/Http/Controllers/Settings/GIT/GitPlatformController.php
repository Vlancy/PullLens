<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Enums\GIT\GitProvider;
use App\Http\Controllers\Controller;
use App\Models\GIT\GitRepository;
use App\Repositories\Contracts\GIT\GitAccountRepositoryInterface;
use App\Repositories\Contracts\GIT\GitRepositoryRepositoryInterface;
use App\Services\Git\GitProviderAppConfigurator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GitPlatformController extends Controller
{
    /**
     * Show system-level Git provider setup and connected account status.
     */
    public function edit(
        Request $request,
        GitProviderAppConfigurator $apps,
        GitAccountRepositoryInterface $gitAccounts,
        GitRepositoryRepositoryInterface $gitRepositories,
    ): Response {
        $accounts = $gitAccounts->latestConnected()
            ->map(fn ($account) => [
                'id' => $account->id,
                'provider' => $account->provider->value,
                'provider_label' => $account->provider->label(),
                'provider_user_id' => $account->provider_user_id,
                'nickname' => $account->nickname,
                'name' => $account->name,
                'email' => $account->email,
                'avatar_url' => $account->avatar_url,
                'scopes' => $account->scopes ?? [],
                'connected_at' => $account->connected_at?->toISOString(),
                'last_used_at' => $account->last_used_at?->toISOString(),
            ]);

        return Inertia::render('settings/git-platforms', [
            'accounts' => $accounts,
            'providers' => collect(GitProvider::cases())->map(function (GitProvider $provider) use ($apps) {
                $app = $apps->configuredApp($provider);

                return [
                    'value' => $provider->value,
                    'label' => $provider->label(),
                    'scopes' => $provider->scopes(),
                    'configured' => $apps->isConfigured($provider),
                    'app_name' => $app?->name,
                    'install_url' => $provider === GitProvider::Github && filled($app?->slug)
                        ? "https://github.com/apps/{$app->slug}/installations/new"
                        : null,
                    'github_settings_url' => $provider === GitProvider::Github && filled($app?->slug)
                        ? "https://github.com/apps/{$app->slug}"
                        : null,
                    'delete_url' => route('integrations.apps.destroy', $provider->value),
                    'setup_url' => $provider === GitProvider::Github
                        ? route('integrations.github.manifest.setup')
                        : null,
                    'test_url' => $provider === GitProvider::Github
                        ? route('integrations.github.app.test')
                        : null,
                    'connect_url' => $provider === GitProvider::Github
                        ? route('integrations.github.app.connect')
                        : null,
                    'sync_url' => $provider === GitProvider::Github && $apps->isConfigured($provider)
                        ? route('integrations.github.app.sync')
                        : null,
                    'callback_url' => route('integrations.callback', $provider->value),
                    'webhook_url' => $provider === GitProvider::Github
                        ? route('webhooks.github')
                        : null,
                    'repositories_browse_url' => route('integrations.repositories.browse', $provider->value),
                    'repositories_store_url' => route('integrations.repositories.store', $provider->value),
                ];
            })->values(),
            'repositories' => $gitRepositories->forProvider(GitProvider::Github)
                ->map(fn (GitRepository $repository) => [
                    'id' => $repository->id,
                    'provider' => $repository->provider->value,
                    'account_id' => $repository->git_account_id,
                    'installation_id' => $repository->installation_id,
                    'provider_repo_id' => $repository->provider_repo_id,
                    'name' => $repository->name,
                    'full_name' => $repository->full_name,
                    'owner_login' => $repository->owner_login,
                    'owner_type' => $repository->owner_type,
                    'default_branch' => $repository->default_branch,
                    'is_private' => $repository->is_private,
                    'web_url' => $repository->web_url,
                    'reviews_enabled' => $repository->reviews_enabled,
                    'branches_count' => $repository->branches->count(),
                    'open_prs_count' => (int) ($repository->open_prs_count ?? 0),
                    'draft_prs_count' => (int) ($repository->draft_prs_count ?? 0),
                    'merged_prs_count' => (int) ($repository->merged_prs_count ?? 0),
                    'closed_prs_count' => (int) ($repository->closed_prs_count ?? 0),
                    'settings_url' => route('integrations.repositories.settings.edit', $repository->id),
                    'destroy_url' => route('integrations.repositories.destroy', $repository->id),
                ])
                ->values(),
            'status' => $request->session()->get('status'),
        ]);
    }
}
