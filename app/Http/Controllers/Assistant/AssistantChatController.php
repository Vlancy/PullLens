<?php

namespace App\Http\Controllers\Assistant;

use App\Ai\Agents\AssistantAgent;
use App\Enums\AI\AiOperation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\AssistantChatRequest;
use App\Models\AI\AiProvider;
use App\Services\AI\AiProviderConfigResolver;
use App\Services\AI\AiUsageRecorder;
use App\Services\AI\ResolvedAiProvider;
use App\Services\Assistant\AssistantConversationStore;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Streams one assistant turn back to the browser as server-sent events.
 *
 * The conversation history comes from the server-side store, never from the request,
 * and provider failures are reported generically — a provider's exception text often
 * echoes the request, API key included.
 */
class AssistantChatController extends Controller
{
    /** Seconds to wait for the model before giving up on the turn. */
    private const STREAM_TIMEOUT_SECONDS = 25;

    /**
     * Inject the ai provider config resolver, assistant conversation store and ai usage recorder this class delegates to.
     */
    public function __construct(
        private readonly AiProviderConfigResolver $configResolver,
        private readonly AssistantConversationStore $conversations,
        private readonly AiUsageRecorder $usage,
    ) {}

    /**
     * Render the page.
     */
    public function __invoke(AssistantChatRequest $request): Response
    {
        $userId = $request->user()->getAuthIdentifier();
        $message = $request->message();
        $startedAt = microtime(true);

        $provider = $this->resolveProvider($request->providerId());

        $stream = AssistantAgent::make()
            ->withHistory($this->conversations->get($userId))
            ->stream(
                $message,
                provider: $provider->configName,
                model: $provider->model,
                timeout: self::STREAM_TIMEOUT_SECONDS,
            );

        // Persist the exchange once the model has finished producing it. Token usage
        // is only known at that point too — a stream reports it after the last chunk.
        $stream->then(function (StreamedAgentResponse $response) use ($userId, $message, $provider, $startedAt): void {
            $this->conversations->append($userId, $message, $response->text);

            $this->usage->record(
                AiOperation::AssistantChat,
                $provider,
                $response->usage,
                (int) round((microtime(true) - $startedAt) * 1000),
                ['user_id' => $userId],
            );
        });

        return $this->streamEvents($stream);
    }

    /**
     * Wrap the agent stream in a server-sent-event response.
     */
    private function streamEvents(iterable $stream): Response
    {
        return response()->stream(function () use ($stream) {
            try {
                foreach ($stream as $event) {
                    yield 'data: '.((string) $event)."\n\n";
                }
            } catch (Throwable $e) {
                Log::warning('assistant.stream_failed', ['error' => $e->getMessage()]);

                yield 'data: '.json_encode([
                    'type' => 'error',
                    'message' => 'The AI provider failed to respond. Please check your AI provider settings.',
                ])."\n\n";
            }

            yield "data: [DONE]\n\n";
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            // Stops nginx buffering the stream and delivering it all at the end.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Resolve the provider to answer with, falling back to the configured default.
     *
     * A disabled or unknown provider silently falls back rather than erroring: the
     * selector is a preference, and the request should still be answered.
     */
    private function resolveProvider(?string $providerId): ResolvedAiProvider
    {
        if ($providerId === null) {
            return $this->configResolver->default();
        }

        $provider = AiProvider::query()
            ->where('id', $providerId)
            ->where('is_enabled', true)
            ->first();

        return $provider instanceof AiProvider
            ? $this->configResolver->inject($provider, $provider->default_model)
            : $this->configResolver->default();
    }
}
