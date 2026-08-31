<?php

namespace App\Repositories\Contracts\AI;

use App\Models\AI\AiProvider;
use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface AiProviderRepositoryInterface extends RepositoryInterface
{
    /**
     * Return enabled AI providers ordered for selection UIs.
     *
     * @return Collection<int, AiProvider>
     */
    public function enabled(): Collection;

    /**
     * Return the default enabled AI provider, if configured.
     */
    public function default(): ?AiProvider;

    /**
     * Persist the given provider as the only default provider.
     */
    public function markDefault(AiProvider $provider): AiProvider;
}
