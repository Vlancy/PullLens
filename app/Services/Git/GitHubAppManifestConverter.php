<?php

namespace App\Services\Git;

use App\Enums\GIT\GitProvider;
use App\Repositories\Contracts\GIT\GitProviderAppRepositoryInterface;
use Illuminate\Support\Facades\Http;

class GitHubAppManifestConverter
{
    /**
     * Create the converter with encrypted provider app persistence.
     */
    public function __construct(private readonly GitProviderAppRepositoryInterface $providerApps) {}

    /**
     * Exchange a GitHub manifest code and store the resulting encrypted credentials.
     *
     * @return array<string, mixed>
     */
    public function convert(string $code): array
    {
        $response = Http::accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->send('POST', "https://api.github.com/app-manifests/{$code}/conversions")
            ->throw()
            ->json();

        // Store app secrets in encrypted model casts instead of environment variables.
        $this->providerApps->updateOrCreateForProvider(
            GitProvider::Github,
            [
                'name' => (string) data_get($response, 'name', config('app.name')),
                'app_id' => filled(data_get($response, 'id')) ? (string) data_get($response, 'id') : null,
                'client_id' => (string) data_get($response, 'client_id'),
                'client_secret' => (string) data_get($response, 'client_secret'),
                'webhook_secret' => data_get($response, 'webhook_secret'),
                'private_key' => data_get($response, 'pem'),
                'slug' => data_get($response, 'slug'),
                'configured_at' => now(),
            ],
        );

        return $response;
    }
}
