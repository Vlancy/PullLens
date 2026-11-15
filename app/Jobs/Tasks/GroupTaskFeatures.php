<?php

namespace App\Jobs\Tasks;

use App\Services\Tasks\Features\FeatureBackfillService;
use App\Support\Tasks\FeatureScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Groups one batch of a scope's ungrouped tasks, then queues the next batch.
 *
 * One batch per job keeps each job well inside the queue timeout however many tasks
 * the scope holds.
 */
class GroupTaskFeatures implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Never retried: a retry would pay for the same AI call twice. */
    public int $tries = 1;

    public int $timeout = 120;

    /**
     * Create the job for a project or repository scope.
     *
     * @param  ?string  $projectId  The project being grouped, if a project scope.
     * @param  ?string  $repositoryId  The repository being grouped, if a repository scope.
     * @param  string  $runId  The run this job belongs to; it does nothing once superseded.
     * @param  int  $batch  Which batch of the run this job handles, starting at 1.
     */
    public function __construct(
        public readonly ?string $projectId,
        public readonly ?string $repositoryId,
        public readonly string $runId,
        public readonly int $batch = 1,
    ) {}

    /**
     * Run one batch and queue the next while tasks remain.
     */
    public function handle(FeatureBackfillService $backfill): void
    {
        $scope = new FeatureScope($this->projectId, $this->repositoryId);

        // A newer run began while this job sat in the queue: stop without an AI call.
        if (! $backfill->isCurrentRun($scope, $this->runId)) {
            return;
        }

        try {
            $count = $backfill->runBatch($scope, $this->runId);
        } catch (Throwable $e) {
            Log::warning('feature_grouping.failed', [
                'scope' => $scope->key(),
                'batch' => $this->batch,
                'error' => $e->getMessage(),
            ]);

            $backfill->finish($scope, $this->runId, 'Grouping stopped because the AI call failed.');

            return;
        }

        if ($count > 0 && $this->batch < FeatureBackfillService::MAX_BATCHES) {
            // Staleness is measured from here, so a long queue wait is not mistaken for a dead run.
            $backfill->touch($scope, $this->runId);
            self::dispatch($this->projectId, $this->repositoryId, $this->runId, $this->batch + 1);

            return;
        }

        $backfill->finish($scope, $this->runId);
    }
}
