<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Models\AI\AiProvider;
use App\Repositories\Contracts\AI\AiProviderRepositoryInterface;
use App\Services\Assistant\AssistantConversationStore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the assistant page with the user's stored conversation and provider choices.
 */
class AssistantPageController extends Controller
{
    /**
     * Inject the assistant conversation store this class delegates to.
     */
    public function __construct(private readonly AssistantConversationStore $conversations) {}

    /**
     * Render the assistant page.
     */
    public function __invoke(Request $request, AiProviderRepositoryInterface $providers): Response
    {
        $enabled = $providers->enabled()
            ->map(static fn (AiProvider $provider): array => [
                'id' => $provider->id,
                'name' => $provider->name,
                'is_default' => $provider->is_default,
            ])
            ->values();

        return Inertia::render('assistant', [
            'history' => $this->conversations->get($request->user()->getAuthIdentifier()),
            'providers' => $enabled,
            // Prefer the explicitly marked default, otherwise the first enabled provider.
            'default_provider_id' => $enabled->firstWhere('is_default', true)['id']
                ?? $enabled->first()['id']
                ?? null,
        ]);
    }
}
