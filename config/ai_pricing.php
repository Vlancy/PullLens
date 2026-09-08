<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Model pricing
    |---------------------------------------------------------------------------
    |
    | USD per one million tokens, used to cost each recorded AI call. Providers
    | change prices and PullLens cannot read them from an API, so these are a
    | best-effort estimate maintained here - every computed cost is stored with a
    | flag marking it estimated, and a model with no entry records tokens with a
    | null cost rather than a fabricated one.
    |
    | Keys are matched against the model name as a case-insensitive prefix, longest
    | first, so "claude-haiku-4-5-20251001" resolves via the "claude-haiku-4-5" entry
    | and survives a dated model revision without an edit here.
    |
    | Only prices confirmed against vendor documentation are listed. A model absent
    | from this table records its tokens with no cost rather than a guessed one -
    | OpenAI GPT-5.6, Gemini 3.x, Grok, Mistral and Groq are deliberately omitted
    | until their published rates are confirmed.
    |
    | `cache_write` and `cache_read` apply to providers that support prompt caching.
    | Cache reads are typically a tenth of the input price, which is what makes
    | caching the largest single lever on review cost.
    |
    */

    'models' => [
        // ── Anthropic ────────────────────────────────────────────────────────
        // Verified against platform.claude.com model docs, August 2026.
        // Cache reads are 10% of the base input price; cache writes are 1.25x.
        'claude-fable-5' => ['input' => 10.00, 'output' => 50.00, 'cache_write' => 12.50, 'cache_read' => 1.00],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],
        // Bedrock exposes the same models under a prefixed id.
        'anthropic.claude-fable-5' => ['input' => 10.00, 'output' => 50.00, 'cache_write' => 12.50, 'cache_read' => 1.00],
        'anthropic.claude-opus-5' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'anthropic.claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20],
        'anthropic.claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],
        // Legacy Claude generations, still selectable on some installations.
        'claude-opus-4' => ['input' => 15.00, 'output' => 75.00, 'cache_write' => 18.75, 'cache_read' => 1.50],
        'claude-sonnet-4' => ['input' => 3.00, 'output' => 15.00, 'cache_write' => 3.75, 'cache_read' => 0.30],

        // ── DeepSeek ─────────────────────────────────────────────────────────
        // Verified against api-docs.deepseek.com, August 2026. DeepSeek charges
        // half price off-peak; the peak rate is used here so a cost is never
        // understated.
        'deepseek-v4-pro' => ['input' => 1.32, 'output' => 3.96, 'cache_write' => 1.32, 'cache_read' => 0.044],
        'deepseek-v4-flash' => ['input' => 0.44, 'output' => 1.32, 'cache_write' => 0.44, 'cache_read' => 0.014],

        // ── Cohere ───────────────────────────────────────────────────────────
        'command-r-plus' => ['input' => 2.50, 'output' => 10.00],

        // ── Locally hosted ───────────────────────────────────────────────────
        // Ollama runs on your own hardware, so there is no per-token charge. Zero
        // is the true price here, not a missing one.
        'qwen3-coder' => ['input' => 0.0, 'output' => 0.0],
        'devstral' => ['input' => 0.0, 'output' => 0.0],
        'glm-4.7-flash' => ['input' => 0.0, 'output' => 0.0],
        'gpt-oss:' => ['input' => 0.0, 'output' => 0.0],
    ],

    /*
    |---------------------------------------------------------------------------
    | Unknown models
    |---------------------------------------------------------------------------
    |
    | When a model has no pricing entry the call is still recorded, with tokens but
    | no cost. Set a fallback only if a rough figure is more useful to you than an
    | explicit "unknown" - a wrong number is easy to mistake for a real one.
    |
    */

    'fallback' => null,

];
