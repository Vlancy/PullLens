<?php

namespace App\Services\AI;

use Laravel\Ai\Responses\Data\Usage;

/**
 * Converts token counts into an estimated dollar cost.
 *
 * Providers do not return a price with the response, so cost is derived from a
 * maintained price table. Two rules keep that honest:
 *
 *   1. A model with no price entry produces a null cost, never a guessed one. A
 *      fabricated number is worse than a blank, because it gets budgeted against.
 *   2. Every cost is stored on the record when it is computed, so a later price
 *      change cannot silently restate what last month actually cost.
 */
class AiCostCalculator
{
    /** Prices are quoted per one million tokens. */
    private const TOKENS_PER_PRICE_UNIT = 1_000_000;

    /**
     * The estimated cost of one call, or null when the model's price is unknown.
     */
    public function cost(?string $model, Usage $usage): ?float
    {
        $pricing = $this->pricingFor($model);

        if ($pricing === null) {
            return null;
        }

        // Cached input is billed differently from fresh input: writing to the cache
        // costs a premium, reading from it is heavily discounted. promptTokens from
        // the provider already excludes the cached portion.
        $cost = ($usage->promptTokens * $this->rate($pricing, 'input'))
            + ($usage->completionTokens * $this->rate($pricing, 'output'))
            + ($usage->cacheWriteInputTokens * $this->rate($pricing, 'cache_write'))
            + ($usage->cacheReadInputTokens * $this->rate($pricing, 'cache_read'));

        // Reasoning tokens are billed as output by every provider that reports them.
        $cost += $usage->reasoningTokens * $this->rate($pricing, 'output');

        return round($cost, 8);
    }

    /**
     * Whether a price is known for this model, so the UI can distinguish "free" from
     * "not priced".
     */
    public function hasPricing(?string $model): bool
    {
        return $this->pricingFor($model) !== null;
    }

    /**
     * Resolve the price table entry for a model name.
     *
     * Matched as a case-insensitive prefix and longest-first, so a dated revision
     * like "claude-sonnet-4-5-20250929" resolves through "claude-sonnet-4-5" without
     * the table needing an entry per release.
     *
     * @return array<string, float>|null
     */
    private function pricingFor(?string $model): ?array
    {
        if (blank($model)) {
            return null;
        }

        /** @var array<string, array<string, float>> $table */
        $table = (array) config('ai_pricing.models', []);
        $needle = strtolower($model);

        $keys = array_keys($table);
        usort($keys, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($keys as $key) {
            if (str_starts_with($needle, strtolower($key))) {
                return $table[$key];
            }
        }

        /** @var array<string, float>|null $fallback */
        $fallback = config('ai_pricing.fallback');

        return $fallback;
    }

    /**
     * Per-token rate for one price component, falling back to the input rate when a
     * provider entry does not distinguish cache pricing.
     *
     * @param  array<string, float>  $pricing
     */
    private function rate(array $pricing, string $component): float
    {
        $perMillion = (float) ($pricing[$component] ?? $pricing['input'] ?? 0.0);

        return $perMillion / self::TOKENS_PER_PRICE_UNIT;
    }
}
