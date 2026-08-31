<?php

namespace App\Services\AI;

/**
 * Outcome of a provider connectivity test, in the shape the settings page expects.
 *
 * Immutable value object: it carries no provider internals, so it is always safe to
 * serialize straight to the client.
 */
final readonly class AiConnectionTestResult
{
    /**
     * Inject the bool and string this class delegates to.
     */
    private function __construct(
        public bool $successful,
        public string $message,
        public ?int $latencyMs = null,
    ) {}

    /**
     * A successful round trip, with how long the provider took to answer.
     */
    public static function success(int $latencyMs): self
    {
        return new self(true, 'Connection successful', $latencyMs);
    }

    /**
     * A failed round trip, carrying a message that is safe to show the operator.
     */
    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    /**
     * Serialize for the front end.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'success' => $this->successful,
            'message' => $this->message,
            'latency_ms' => $this->latencyMs,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
