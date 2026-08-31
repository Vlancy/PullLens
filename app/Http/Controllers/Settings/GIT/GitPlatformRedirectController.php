<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Enums\GIT\GitProvider;
use App\Http\Controllers\Controller;
use App\Services\Git\GitProviderAppConfigurator;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GitPlatformRedirectController extends Controller
{
    /**
     * Redirect an authenticated PullLens user to the selected provider OAuth flow.
     */
    public function __invoke(string $provider, GitProviderAppConfigurator $apps): RedirectResponse|SymfonyRedirectResponse
    {
        $gitProvider = $this->resolveProvider($provider);

        abort_unless($apps->isConfigured($gitProvider), 404);

        $apps->configureSocialite($gitProvider);

        return Socialite::driver($gitProvider->value)
            ->scopes($gitProvider->scopes())
            ->redirect();
    }

    /**
     * Resolve a route parameter into a supported Git provider enum.
     */
    private function resolveProvider(string $provider): GitProvider
    {
        return GitProvider::tryFrom($provider) ?? abort(404);
    }
}
