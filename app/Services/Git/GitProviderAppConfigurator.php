<?php

namespace App\Services\Git;

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitProviderApp;
use App\Repositories\Contracts\GIT\GitProviderAppRepositoryInterface;

class GitProviderAppConfigurator
{
    /**
     * Create the configurator with encrypted provider app persistence.
     */
    public function __construct(private readonly GitProviderAppRepositoryInterface $providerApps) {}

    /**
     * Return stored system-level app credentials for the provider.
     */
    public function configuredApp(GitProvider $provider): ?GitProviderApp
    {
        return $this->providerApps->findByProvider($provider);
    }

    /**
     * Determine whether the provider has usable OAuth credentials.
     */
    public function isConfigured(GitProvider $provider): bool
    {
        $app = $this->configuredApp($provider);

        return $app !== null
            && filled($app->client_id)
            && filled($app->client_secret);
    }

    /**
     * Inject encrypted database credentials into Socialite runtime configuration.
     */
    public function configureSocialite(GitProvider $provider): void
    {
        $app = $this->configuredApp($provider) ?? abort(404);

        config([
            "services.{$provider->value}.client_id" => $app->client_id,
            "services.{$provider->value}.client_secret" => $app->client_secret,
            "services.{$provider->value}.redirect" => route('integrations.callback', $provider->value),
        ]);
    }
}
