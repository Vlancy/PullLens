<?php

namespace App\Http\Controllers\Assistant;

use App\Models\AI\AiProvider;
use App\Repositories\Contracts\AI\AiProviderRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class AssistantPageController
{
    public function __invoke(Request $request, AiProviderRepositoryInterface $providers): Response
    {
        $history = Cache::get("assistant_history:{$request->user()->id}", []);

        $enabledProviders = $providers->enabled()->map(fn (AiProvider $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'is_default' => $p->is_default,
        ])->values();

        $defaultId = $enabledProviders->firstWhere('is_default', true)['id']
            ?? $enabledProviders->first()['id']
            ?? null;

        return Inertia::render('assistant', [
            'history' => $history,
            'providers' => $enabledProviders,
            'default_provider_id' => $defaultId,
        ]);
    }
}
