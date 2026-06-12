<?php

namespace App\Http\Controllers\Settings\AI;

use App\Ai\Agents\TestConnectionAgent;
use App\Enums\AI\AiProviderDriver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AI\StoreAiProviderRequest;
use App\Http\Requests\Settings\AI\TestAiProviderRequest;
use App\Http\Requests\Settings\AI\UpdateAiProviderRequest;
use App\Models\AI\AiProvider;
use App\Repositories\Contracts\AI\AiProviderRepositoryInterface;
use App\Services\AI\AiProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AiProviderController extends Controller
{

    /**
     * Show configured DB-backed Laravel AI providers.
     */
    public function edit(AiProviderRepositoryInterface $providers): Response
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
     */
    public function test(TestAiProviderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $driver = $validated['provider_driver'];
        $apiKey = $validated['api_key'] ?? null;
        $baseUrl = $validated['base_url'] ?? null;
        $model = $validated['default_model'] ?? null;

        // For edit context: reuse stored key when api_key field was left blank.
        if ($apiKey === null && isset($validated['provider_id'])) {
            $stored = AiProvider::find($validated['provider_id']);
            $apiKey = $stored?->credentials['api_key'] ?? null;
        }

        $configName = 'pull_lens_test_' . uniqid('', true);
        $config = ['driver' => $driver, 'key' => $apiKey];

        if ($baseUrl !== null) {
            $config[$driver === 'bedrock' ? 'region' : 'url'] = $baseUrl;
        }

        config(["ai.providers.{$configName}" => $config]);

        $start = microtime(true);

        try {
            (new TestConnectionAgent)->prompt('Reply with OK.', provider: $configName, model: $model);

            return response()->json([
                'success' => true,
                'message' => 'Connection successful',
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
