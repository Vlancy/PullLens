<?php

namespace App\Services\AI;

use App\Ai\Agents\TestConnectionAgent;
use App\Enums\AI\AiOperation;
use App\Enums\AI\AiProviderDriver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Performs a live round trip against an AI provider configuration.
 *
 * The provider config is registered under a throwaway name for the duration of the
 * request only, so testing an unsaved form never mutates the stored providers.
 *
 * Provider exception messages frequently echo back the request — including the API
 * key, the endpoint and organisation identifiers — so the raw message is logged for
 * operators and only a classified, non-revealing summary is returned to the browser.
 */
class AiProviderConnectionTester
{
    /**
     * Inject the ai usage recorder this class delegates to.
     */
    public function __construct(private readonly AiUsageRecorder $usage) {}

    /** Prefix for the ephemeral config entry created per test. */
    private const CONFIG_PREFIX = 'pull_lens_test_';

    /**
     * Substrings matched (case-insensitively) against the provider error, mapped to
     * the message shown to the operator. First match wins; order is most-specific first.
     *
     * @var array<string, string>
     */
    private const FAILURE_SIGNATURES = [
        'incorrect api key' => 'The API key was rejected by the provider.',
        'invalid api key' => 'The API key was rejected by the provider.',
        'invalid_api_key' => 'The API key was rejected by the provider.',
        'authentication' => 'The API key was rejected by the provider.',
        'unauthorized' => 'The API key was rejected by the provider.',
        '401' => 'The API key was rejected by the provider.',
        '403' => 'The provider refused access with these credentials.',
        'rate limit' => 'The provider is rate limiting this key. Try again shortly.',
        '429' => 'The provider is rate limiting this key. Try again shortly.',
        'quota' => 'The account attached to this key has no remaining quota.',
        'model' => 'The provider did not recognise the requested model.',
        'timed out' => 'The provider did not respond in time.',
        'timeout' => 'The provider did not respond in time.',
        'could not resolve' => 'The base URL could not be reached.',
        'connection refused' => 'The base URL could not be reached.',
    ];

    /**
     * Send a minimal prompt and report whether the provider answered.
     */
    public function test(string $driver, ?string $apiKey, ?string $baseUrl, ?string $model): AiConnectionTestResult
    {
        $configName = self::CONFIG_PREFIX.Str::random(16);

        config(["ai.providers.{$configName}" => $this->buildConfig($driver, $apiKey, $baseUrl)]);

        $startedAt = microtime(true);

        try {
            $response = (new TestConnectionAgent)->prompt('Reply with OK.', provider: $configName, model: $model);

            $elapsed = $this->elapsedMs($startedAt);

            // Tiny, but still billed — and a misconfigured page can retry it a lot.
            $this->usage->record(
                AiOperation::ConnectionTest,
                null,
                $response->usage,
                $elapsed,
                ['model' => $model],
            );

            return AiConnectionTestResult::success($elapsed);
        } catch (Throwable $e) {
            // Full detail goes to the log, where it is already access-controlled.
            Log::warning('ai_provider.connection_test_failed', [
                'driver' => $driver,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return AiConnectionTestResult::failure($this->classify($e->getMessage()));
        } finally {
            // Do not leave the key sitting in the resolved config for the rest of the request.
            config(["ai.providers.{$configName}" => null]);
        }
    }

    /**
     * Build the ephemeral Laravel AI provider config entry.
     *
     * @return array<string, mixed>
     */
    private function buildConfig(string $driver, ?string $apiKey, ?string $baseUrl): array
    {
        $config = ['driver' => $driver, 'key' => $apiKey];

        if (filled($baseUrl)) {
            // Bedrock is addressed by AWS region rather than by endpoint URL.
            $config[$driver === AiProviderDriver::Bedrock->value ? 'region' : 'url'] = $baseUrl;
        }

        return $config;
    }

    /**
     * Map a provider error onto a safe, actionable message.
     */
    private function classify(string $message): string
    {
        $haystack = strtolower($message);

        foreach (self::FAILURE_SIGNATURES as $needle => $summary) {
            if (str_contains($haystack, $needle)) {
                return $summary;
            }
        }

        return 'The provider could not be reached. Check the server log for details.';
    }

    /**
     * Milliseconds since the given start time.
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
