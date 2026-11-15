<?php

namespace App\Console\Commands\Repositories;

use App\Jobs\GIT\RefreshRepositoryProfile;
use App\Models\GIT\GitRepository;
use Illuminate\Console\Command;

/**
 * Queues an AI profile refresh for every repository whose profile is missing or stale.
 */
class RefreshRepositoryProfiles extends Command
{
    /** @var string */
    protected $signature = 'pulllens:refresh-repository-profiles {--stale-days=30 : Refresh profiles older than this many days}';

    /** @var string */
    protected $description = 'Queue an AI profile refresh for repositories whose tags are missing or stale';

    /**
     * Dispatch a refresh job per stale repository.
     */
    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('stale-days'));
        $queued = 0;

        GitRepository::query()
            ->where(fn ($query) => $query->whereNull('profile_refreshed_at')->orWhere('profile_refreshed_at', '<', $cutoff))
            ->select('id')
            ->each(function (GitRepository $repository) use (&$queued): void {
                RefreshRepositoryProfile::dispatch($repository->id);
                $queued++;
            });

        $this->info("Queued {$queued} repository profile refresh(es).");

        return self::SUCCESS;
    }
}
