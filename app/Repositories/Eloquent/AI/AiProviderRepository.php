<?php

namespace App\Repositories\Eloquent\AI;

use App\Models\AI\AiProvider;
use App\Repositories\Contracts\AI\AiProviderRepositoryInterface;
use App\Repositories\Eloquent\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<AiProvider>
 */
class AiProviderRepository extends BaseRepository implements AiProviderRepositoryInterface
{
    protected string $model = AiProvider::class;

    /**
     * Return enabled AI providers ordered for selection UIs.
     *
     * @return Collection<int, AiProvider>
     */
    public function enabled(): Collection
    {
        return $this->query()
            ->where('is_enabled', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /**
     * Return the default enabled AI provider, if configured.
     */
    public function default(): ?AiProvider
    {
        return $this->query()
            ->where('is_enabled', true)
            ->where('is_default', true)
            ->first();
    }

    /**
     * Persist the given provider as the only default provider.
     */
    public function markDefault(AiProvider $provider): AiProvider
    {
        return DB::transaction(function () use ($provider) {
            $this->query()->whereKeyNot($provider->id)->update(['is_default' => false]);

            return $this->update($provider, [
                'is_default' => true,
                'is_enabled' => true,
            ]);
        });
    }
}
