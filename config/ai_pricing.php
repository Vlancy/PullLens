<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Model pricing
    |---------------------------------------------------------------------------
    |
    | USD per one million tokens, used to cost each recorded AI call. Providers
    | change prices and PullLens cannot read them from an API, so these are a
    | best-effort estimate maintained here — every computed cost is stored with a
    | flag marking it estimated, and a model with no entry records tokens with a
    | null cost rather than a fabricated one.
    |
    | Keys are matched against the model name as a case-insensitive prefix, so
    | "claude-sonnet-4-5-20250929" resolves via the "claude-sonnet-4-5" entry and
    | survives a dated model revision without an edit here.
    |
    | `cache_write` and `cache_read` apply to providers that support prompt caching.
    | Cache reads are typically a tenth of the input price, which is what makes
    | caching the largest single lever on review cost.
    |
    */

    'models' => [
        // Anthropic
        'claude-opus-4' => ['input' => 15.00, 'output' => 75.00, 'cache_write' => 18.75, 'cache_read' => 1.50],
        'claude-sonnet-4' => ['input' => 3.00, 'output' => 15.00, 'cache_write' => 3.75, 'cache_read' => 0.30],
        'claude-haiku-4' => ['input' => 1.00, 'output' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],
        'claude-3-5-haiku' => ['input' => 0.80, 'output' => 4.00, 'cache_write' => 1.00, 'cache_read' => 0.08],

        // OpenAI
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60, 'cache_write' => 0.15, 'cache_read' => 0.075],
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 1.25],
        'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60, 'cache_write' => 0.40, 'cache_read' => 0.10],
        'gpt-4.1' => ['input' => 2.00, 'output' => 8.00, 'cache_write' => 2.00, 'cache_read' => 0.50],
        'o3-mini' => ['input' => 1.10, 'output' => 4.40, 'cache_write' => 1.10, 'cache_read' => 0.55],

        // Google
        'gemini-2.5-pro' => ['input' => 1.25, 'output' => 10.00, 'cache_write' => 1.625, 'cache_read' => 0.31],
        'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50, 'cache_write' => 0.3833, 'cache_read' => 0.075],
        'gemini-2.0-flash' => ['input' => 0.10, 'output' => 0.40, 'cache_write' => 0.13, 'cache_read' => 0.025],

        // Meta / Mistral, commonly self-hosted or via a gateway
        'llama-3' => ['input' => 0.20, 'output' => 0.20, 'cache_write' => 0.20, 'cache_read' => 0.20],
        'mistral-large' => ['input' => 2.00, 'output' => 6.00, 'cache_write' => 2.00, 'cache_read' => 2.00],
    ],

    /*
    |---------------------------------------------------------------------------
    | Unknown models
    |---------------------------------------------------------------------------
    |
    | When a model has no pricing entry the call is still recorded, with tokens but
    | no cost. Set a fallback only if a rough figure is more useful to you than an
    | explicit "unknown" — a wrong number is easy to mistake for a real one.
    |
    */

    'fallback' => null,

];
