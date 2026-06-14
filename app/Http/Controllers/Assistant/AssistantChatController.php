<?php

namespace App\Http\Controllers\Assistant;

use App\Ai\Agents\AssistantAgent;
use App\Models\AI\AiProvider;
use App\Services\AI\AiProviderConfigResolver;
use App\Services\AI\ResolvedAiProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Symfony\Component\HttpFoundation\Response;

class AssistantChatController
{
    public function __construct(private readonly AiProviderConfigResolver $configResolver) {}

    public function __invoke(Request $request): Response
    {
        $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['array', 'max:50'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:10000'],
            'provider_id' => ['nullable', 'uuid', 'exists:ai_providers,id'],
        ]);

        $message = (string) $request->string('message');
        $history = $request->input('history', []);
        $userId = $request->user()->id;

        $resolved = $this->resolveProvider($request->input('provider_id'));

        $agent = AssistantAgent::make()->withHistory($history);

        $stream = $agent->stream($message, provider: $resolved->configName, model: $resolved->model, timeout: 25);

        $stream->then(function (StreamedAgentResponse $response) use ($userId, $history, $message) {
            $updated = array_merge(
                $history,
                [
                    ['role' => 'user', 'content' => $message],
                    ['role' => 'assistant', 'content' => $response->text],
                ]
            );

            if (count($updated) > 50) {
                $updated = array_slice($updated, -50);
            }

            Cache::put("assistant_history:{$userId}", $updated, now()->addDays(7));
        });

        return response()->stream(function () use ($stream) {
            try {
                foreach ($stream as $event) {
                    yield 'data: '.((string) $event)."\n\n";
                }
                yield "data: [DONE]\n\n";
            } catch (\Throwable $e) {
                yield 'data: '.json_encode(['type' => 'error', 'message' => 'The AI provider failed to respond. Please check your AI provider settings.'])."\n\n";
                yield "data: [DONE]\n\n";
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
        ]);
    }

    private function resolveProvider(?string $providerId): ResolvedAiProvider
    {
        if ($providerId !== null) {
            $provider = AiProvider::where('id', $providerId)->where('is_enabled', true)->first();

            if ($provider instanceof AiProvider) {
                return $this->configResolver->inject($provider, $provider->default_model);
            }
        }

        return $this->configResolver->default();
    }
}
