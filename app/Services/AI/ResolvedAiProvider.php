<?php

namespace App\Services\AI;

use App\Models\AI\AiProvider;

readonly class ResolvedAiProvider
{
    /**
     * Create a resolved AI provider configuration value object.
     */
    public function __construct(
        public AiProvider $provider,
        public string $configName,
        public ?string $model,
    ) {}
}
