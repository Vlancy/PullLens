<?php

namespace App\Http\Controllers\Settings\AI;

use App\Enums\AI\AiProviderDriver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AI\StoreAiProviderRequest;
use App\Http\Requests\Settings\AI\TestAiProviderRequest;
use App\Http\Requests\Settings\AI\UpdateAiProviderRequest;
use App\Models\AI\AiProvider;
use App\Repositories\Contracts\AI\AiProviderRepositoryInterface;
use App\Repositories\Contracts\GIT\GitRepositoryRepositoryInterface;
use App\Services\AI\AiProviderConnectionTester;
use App\Services\AI\AiProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AiProviderController extends Controller
{
    /**
     * Show configured DB-backed Laravel AI providers.
     */
    public function edit(AiProviderRepositoryInterface $providers, GitRepositoryRepositoryInterface $repositories): Response
    {
        $items = $providers->all(sort: ['field' => 'name'])->map(fn (AiProvider $provider) => [
            'id' => $provider->id,
            'provider_driver' => $provider->provider_driver,
            'name' => $provider->name,
            'base_url' => $provider->base_url,
            'default_model' => $provider->default_model,
            'is_default' => $provider->is_default,
            'is_enabled' => $provider->is_enabled,
            'has_credentials' => filled($provider->credentials['api_key'] ?? null),
            // Drives the confirmation shown before a provider is disabled or deleted:
            // these repositories pin it and would fall back to the global default.
            'dependent_repositories' => $repositories->pinnedToAiProvider($provider->id)
                ->map(fn ($repository) => [
                    'id' => $repository->id,
                    'full_name' => $repository->full_name,
                ])->values(),
            'update_url' => route('ai-providers.update', $provider->id),
            'destroy_url' => route('ai-providers.destroy', $provider->id),
            'default_url' => route('ai-providers.default', $provider->id),
        ])->values();

        return Inertia::render('settings/ai-providers', [
            'providers' => $items,
            'drivers' => AiProviderDriver::options(),
            'store_url' => route('ai-providers.store'),
            'test_url' => route('ai-providers.test'),
        ]);
    }

    /**
     * Store a DB-backed Laravel AI provider.
     */
    public function store(StoreAiProviderRequest $request, AiProviderManager $manager): RedirectResponse
    {
        $manager->create($request->validated());

        return to_route('ai-providers.edit')->with('status', 'AI provider saved.');
    }

    /**
     * Update a DB-backed Laravel AI provider.
     */
    public function update(UpdateAiProviderRequest $request, AiProvider $aiProvider, AiProviderManager $manager): RedirectResponse
    {
        $manager->update($aiProvider, $request->validated());

        return to_route('ai-providers.edit')->with('status', 'AI provider updated.');
    }

    /**
     * Mark an enabled AI provider as the default provider.
     */
    public function markDefault(AiProvider $aiProvider, AiProviderManager $manager): RedirectResponse
    {
        $manager->markDefault($aiProvider);

        return to_route('ai-providers.edit')->with('status', 'Default AI provider updated.');
    }

    /**
     * Delete a DB-backed Laravel AI provider.
     */
    public function destroy(AiProvider $aiProvider, AiProviderRepositoryInterface $providers): RedirectResponse
    {
        $providers->delete($aiProvider);

        return to_route('ai-providers.edit')->with('status', 'AI provider deleted.');
    }

    /**
     * Test connectivity and credential validity for a provider configuration.
     *
     * Delegated to AiProviderConnectionTester: the controller must not know how a
     * throwaway provider config is assembled, nor how to classify a failure.
     */
    public function test(TestAiProviderRequest $request, AiProviderConnectionTester $tester): JsonResponse
    {
        $result = $tester->test(
            driver: (string) $request->validated('provider_driver'),
            apiKey: $request->resolveApiKey(),
            baseUrl: $request->validated('base_url'),
            model: $request->validated('default_model'),
        );

        return response()->json($result->toArray(), $result->successful ? 200 : 422);
    }
}
