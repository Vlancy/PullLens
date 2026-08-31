<?php

namespace App\Services\AI;

use App\Enums\AI\AiProviderDriver;
use App\Models\AI\AiProvider;
use App\Models\GIT\GitRepository;
use App\Repositories\Contracts\AI\AiProviderRepositoryInterface;
use Illuminate\Support\Arr;
use RuntimeException;

class AiProviderConfigResolver
{
    private const CONFIG_PREFIX = 'pull_lens_db_';

    /**
     * Create a resolver for DB-backed Laravel AI provider configuration.
     */
    public function __construct(private readonly AiProviderRepositoryInterface $providers) {}

    /**
     * Resolve and inject the AI provider configuration for a repository review.
     */
    public function forRepository(GitRepository $repository): ResolvedAiProvider
    {
        $repository->loadMissing('aiProvider');

        $provider = $repository->aiProvider?->is_enabled ? $repository->aiProvider : $this->providers->default();

        if (! $provider instanceof AiProvider) {
            throw new RuntimeException('No enabled AI provider is configured for PullLens reviews.');
        }

        return $this->inject($provider, $repository->ai_model ?: $provider->default_model);
    }

    /**
     * Resolve and inject the default AI provider configuration.
     */
    public function default(): ResolvedAiProvider
    {
        $provider = $this->providers->default();

        if (! $provider instanceof AiProvider) {
            throw new RuntimeException('No enabled default AI provider is configured.');
        }

        return $this->inject($provider, $provider->default_model);
    }

    /**
     * Inject a DB-backed provider config entry that Laravel AI can resolve by name.
     */
    public function inject(AiProvider $provider, ?string $model = null): ResolvedAiProvider
    {
        $configName = self::CONFIG_PREFIX.$provider->id;
        $credentials = $provider->credentials ?? [];
        $driver = $provider->provider_driver;
        $config = [
            'driver' => $driver->value,
            'key' => Arr::get($credentials, 'api_key'),
        ];

        if ($provider->base_url !== null) {
            // Bedrock uses a region string, not a base URL endpoint.
            $config[$driver === AiProviderDriver::Bedrock ? 'region' : 'url'] = $provider->base_url;
        }

        foreach (Arr::except($credentials, ['api_key']) as $key => $value) {
            if ($value !== null && $value !== '') {
                $config[$key] = $value;
            }
        }

        config(["ai.providers.{$configName}" => $config]);

        return new ResolvedAiProvider($provider, $configName, $model);
    }
}
