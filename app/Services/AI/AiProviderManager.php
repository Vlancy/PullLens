<?php

namespace App\Services\AI;

use App\Enums\AI\AiProviderDriver;
use App\Models\AI\AiProvider;
use App\Repositories\Contracts\AI\AiProviderRepositoryInterface;
use Illuminate\Support\Facades\DB;

class AiProviderManager
{
    /**
     * Create a manager for AI provider persistence rules.
     */
    public function __construct(private readonly AiProviderRepositoryInterface $providers) {}

    /**
     * Create an AI provider and maintain a single default provider.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AiProvider
    {
        return DB::transaction(function () use ($data) {
            $provider = $this->providers->create($this->attributesForCreate($data));

            if ($provider->is_default || $this->providers->default() === null) {
                return $this->providers->markDefault($provider);
            }

            return $provider;
        });
    }

    /**
     * Update an AI provider without replacing credentials when no new key is supplied.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(AiProvider $provider, array $data): AiProvider
    {
        $updated = $this->providers->update($provider, $this->attributesForUpdate($provider, $data));

        if ($updated->is_default) {
            return $this->providers->markDefault($updated);
        }

        if ($this->providers->default() === null && $updated->is_enabled) {
            return $this->providers->markDefault($updated);
        }

        return $updated;
    }

    /**
     * Mark a provider as the default enabled provider.
     */
    public function markDefault(AiProvider $provider): AiProvider
    {
        return $this->providers->markDefault($provider);
    }

    /**
     * Build persisted attributes for a new provider.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributesForCreate(array $data): array
    {
        return [
            'provider_driver' => $data['provider_driver'],
            'name' => $data['name'],
            'credentials' => $this->credentialsFrom($data),
            'base_url' => $data['base_url'] ?? null,
            'default_model' => $this->resolveModel($data),
            'is_default' => (bool) ($data['is_default'] ?? false),
            'is_enabled' => (bool) ($data['is_enabled'] ?? true),
        ];
    }

    /**
     * Build persisted attributes for an existing provider.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributesForUpdate(AiProvider $provider, array $data): array
    {
        $attributes = $this->attributesForCreate($data);

        if (($data['api_key'] ?? null) === null || $data['api_key'] === '') {
            $attributes['credentials'] = $provider->credentials ?? [];
        }

        return $attributes;
    }

    /**
     * Return the model to persist - falls back to the driver's recommended
     * code-review model when the operator leaves the field blank.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveModel(array $data): string
    {
        $model = (string) ($data['default_model'] ?? '');

        if ($model !== '') {
            return $model;
        }

        $driver = AiProviderDriver::tryFrom((string) ($data['provider_driver'] ?? ''));

        return $driver?->recommendedModel() ?? '';
    }

    /**
     * Convert write-only form fields to encrypted credential payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function credentialsFrom(array $data): array
    {
        return [
            'api_key' => $data['api_key'] ?? null,
        ];
    }
}
