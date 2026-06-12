<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Enums\GIT\GitProvider;
use App\Http\Controllers\Controller;
use App\Services\Git\GitAccountConnector;
use App\Services\Git\GitProviderAppConfigurator;
use App\Services\Git\SocialiteGitUserMapper;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;

class GitPlatformCallbackController extends Controller
{
    /**
     * Create the callback controller with provider connection dependencies.
     */
    public function __construct(
        private readonly GitAccountConnector $connector,
        private readonly GitProviderAppConfigurator $apps,
        private readonly SocialiteGitUserMapper $mapper,
    ) {}

    /**
     * Handle provider OAuth callback and connect the account at system level.
     */
    public function __invoke(string $provider): RedirectResponse
    {
        $gitProvider = GitProvider::tryFrom($provider) ?? abort(404);

        $this->apps->configureSocialite($gitProvider);

        $socialiteUser = Socialite::driver($gitProvider->value)->user();

        $this->connector->connect(
            $this->mapper->map($gitProvider, $socialiteUser),
        );

        return to_route('integrations.edit')
            ->with('status', "{$gitProvider->label()} account connected.");
    }
}
