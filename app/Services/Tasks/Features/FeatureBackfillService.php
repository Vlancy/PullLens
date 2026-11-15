<?php

namespace App\Services\Tasks\Features;

use App\Ai\Agents\FeatureGroupingAgent;
use App\Enums\AI\AiOperation;
use App\Enums\GIT\AttributionSource;
use App\Jobs\Tasks\GroupTaskFeatures;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequestTask;
use App\Services\AI\AiProviderConfigResolver;
use App\Services\AI\AiUsageRecorder;
use App\Services\AI\ResolvedAiProvider;
use App\Support\Tasks\FeatureScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Groups tasks recorded before features existed, one AI call per batch of tasks.
 *
 * Runs only when a person asks for it (settings button or artisan command), never on
 * upgrade. Progress lives in the cache so the settings page can poll it.
 */
class FeatureBackfillService
{
    /** Tasks sent to the model per call. */
    public const BATCH_SIZE = 25;

    /** Most batches one run makes, a guard against a run that never ends. */
    public const MAX_BATCHES = 200;

    /** How long a run's progress is kept, in seconds. */
    private const TTL = 86400;

    /** A run silent for this long (seconds) is treated as dead, e.g. its worker was killed. */
    private const STALE_AFTER = 900;

    /**
     * Inject the collaborators grouping needs.
     *
     * @param  FeatureResolver  $resolver  Maps the model's feature names onto stored features.
     * @param  FeatureCandidateProvider  $candidates  Lists the existing features offered to the model.
     * @param  AiProviderConfigResolver  $providers  Resolves the AI provider for the scope.
     * @param  AiUsageRecorder  $usage  Records the cost of each call.
     * @param  FeatureManager  $features  Prunes AI features left without tasks when a run ends.
     */
    public function __construct(
        private readonly FeatureResolver $resolver,
        private readonly FeatureCandidateProvider $candidates,
        private readonly AiProviderConfigResolver $providers,
        private readonly AiUsageRecorder $usage,
        private readonly FeatureManager $features,
    ) {}

    /**
     * How much work grouping the scope would take, and how far a running job has got.
     *
     * @param  FeatureScope  $scope  The project or repository to group.
     * @return array{ungrouped: int, batches: int, running: bool, processed: int, total: int, error: ?string}
     */
    public function preview(FeatureScope $scope): array
    {
        $ungrouped = $this->eligible($scope)->count();
        $state = $this->state($scope);

        return [
            'ungrouped' => $ungrouped,
            'batches' => (int) ceil($ungrouped / self::BATCH_SIZE),
            'running' => $this->isRunning($state),
            'processed' => (int) ($state['processed'] ?? 0),
            'total' => (int) ($state['total'] ?? $ungrouped),
            'error' => $state['error'] ?? null,
        ];
    }

    /**
     * Queue grouping for the scope; does nothing while a run is already going or when nothing is left.
     *
     * @param  FeatureScope  $scope  The project or repository to group.
     *
     * @throws ValidationException When the scope has no AI provider to call.
     */
    public function start(FeatureScope $scope): void
    {
        $this->provider($scope);

        $runId = $this->claim($scope);

        if ($runId !== null) {
            GroupTaskFeatures::dispatch($scope->projectId, $scope->repositoryId, $runId);
        }
    }

    /**
     * Group every eligible task in the scope now, batch after batch.
     *
     * @param  FeatureScope  $scope  The project or repository to group.
     * @param  ?Closure(int, int): void  $onBatch  Called after each batch with tasks processed so far and the total.
     * @return ?int The number of tasks processed; null when another run is already going.
     *
     * @throws Throwable When a batch fails; progress is marked stopped first.
     */
    public function run(FeatureScope $scope, ?Closure $onBatch = null): ?int
    {
        $runId = $this->claim($scope, allowEmpty: true);

        if ($runId === null) {
            return null;
        }

        $total = (int) ($this->state($scope)['total'] ?? 0);

        try {
            for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
                if ($this->runBatch($scope, $runId) === 0) {
                    break;
                }

                if ($onBatch !== null) {
                    $onBatch((int) ($this->state($scope)['processed'] ?? 0), $total);
                }
            }
        } catch (Throwable $e) {
            $this->finish($scope, $runId, 'Grouping stopped because the AI call failed.');

            throw $e;
        }

        $this->finish($scope, $runId);

        return (int) ($this->state($scope)['processed'] ?? 0);
    }

    /**
     * Send the oldest batch of ungrouped tasks to the model and file them under its features.
     *
     * Every task sent counts as attempted and is left out of later batches, so tasks the
     * model cannot place do not stall the run.
     *
     * @param  FeatureScope  $scope  The project or repository to group.
     * @param  string  $runId  The run this batch belongs to; a superseded run does nothing.
     * @return int The number of tasks in the batch; 0 when nothing is left or the run was superseded.
     */
    public function runBatch(FeatureScope $scope, string $runId): int
    {
        $state = $this->state($scope);

        if (! $this->owns($state, $runId)) {
            return 0;
        }

        $attempted = (array) ($state['attempted'] ?? []);

        $tasks = $this->eligible($scope)
            ->when($attempted !== [], fn (Builder $query) => $query->whereNotIn('id', $attempted))
            ->with('repository:id,full_name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        if ($tasks->isEmpty()) {
            return 0;
        }

        $resolved = $this->provider($scope);
        $agent = new FeatureGroupingAgent;
        $startedAt = microtime(true);

        $result = $agent->prompt(
            $agent->buildPrompt(
                $this->candidates->features($scope),
                $tasks->map(fn (PullRequestTask $task): array => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'type' => $task->type->value,
                    'description' => $task->description,
                    'repository' => $task->repository?->full_name ?? '',
                ])->values()->all(),
            ),
            provider: $resolved->configName,
            model: $resolved->model,
        );

        $this->usage->record(
            AiOperation::FeatureGrouping,
            $resolved,
            $result->usage,
            (int) round((microtime(true) - $startedAt) * 1000),
            ['git_repository_id' => $scope->repositoryId ?? $tasks->first()->git_repository_id],
        );

        $batchIds = $tasks->pluck('id')->all();

        foreach ((array) data_get($result, 'assignments', []) as $assignment) {
            $taskId = (string) data_get($assignment, 'task_id', '');

            if (! in_array($taskId, $batchIds, true)) {
                continue;
            }

            $feature = $this->resolver->resolve($scope, [
                'existing_slug' => data_get($assignment, 'existing_slug'),
                'new_name' => data_get($assignment, 'new_name'),
                'new_description' => data_get($assignment, 'new_description'),
            ]);

            if ($feature === null) {
                continue;
            }

            // Re-checked in the update: a person may have filed the task meanwhile.
            $this->eligible($scope)->whereKey($taskId)->update([
                'task_feature_id' => $feature->id,
                'feature_source' => AttributionSource::Ai->value,
            ]);
        }

        // Re-read: a newer run may have taken over during the AI call; leave its progress alone.
        $state = $this->state($scope);

        if (! $this->owns($state, $runId)) {
            return 0;
        }

        $this->put($scope, [
            ...$state,
            'processed' => (int) ($state['processed'] ?? 0) + count($batchIds),
            'attempted' => [...(array) ($state['attempted'] ?? []), ...$batchIds],
        ]);

        return count($batchIds);
    }

    /**
     * Whether the scope's current run is the given one.
     *
     * @param  FeatureScope  $scope  The project or repository being grouped.
     * @param  string  $runId  The run to check.
     */
    public function isCurrentRun(FeatureScope $scope, string $runId): bool
    {
        return $this->owns($this->state($scope), $runId);
    }

    /**
     * Mark the run as still alive, e.g. when its next batch is queued.
     *
     * @param  FeatureScope  $scope  The project or repository being grouped.
     * @param  string  $runId  The run to refresh; a superseded run is ignored.
     */
    public function touch(FeatureScope $scope, string $runId): void
    {
        $state = $this->state($scope);

        if ($this->owns($state, $runId)) {
            $this->put($scope, $state);
        }
    }

    /**
     * Mark the run as stopped, with an error when it failed, and prune AI features left
     * empty in the scope; a superseded run is ignored.
     *
     * @param  FeatureScope  $scope  The project or repository being grouped.
     * @param  string  $runId  The run that stopped.
     * @param  ?string  $error  Why the run stopped early, if it did.
     */
    public function finish(FeatureScope $scope, string $runId, ?string $error = null): void
    {
        $state = $this->state($scope);

        if (! $this->owns($state, $runId)) {
            return;
        }

        unset($state['attempted']);

        $this->put($scope, [...$state, 'running' => false, 'error' => $error]);
        $this->features->pruneEmpty($scope);
    }

    /**
     * The cache key holding the scope's progress.
     *
     * @param  FeatureScope  $scope  The project or repository being grouped.
     */
    public function cacheKey(FeatureScope $scope): string
    {
        return 'feature-grouping:'.$scope->key();
    }

    /**
     * Ungrouped tasks in the scope that a person has not filed by hand.
     *
     * @param  FeatureScope  $scope  The project or repository to group.
     * @return Builder<PullRequestTask>
     */
    private function eligible(FeatureScope $scope): Builder
    {
        return PullRequestTask::query()
            ->whereIn('git_repository_id', $this->repositories($scope)->select('id'))
            ->whereNull('task_feature_id')
            ->where(fn (Builder $query) => $query
                ->whereNull('feature_source')
                ->orWhere('feature_source', '!=', AttributionSource::Manual->value));
    }

    /**
     * The repositories whose tasks share the scope's features.
     *
     * @param  FeatureScope  $scope  The project or repository to group.
     * @return Builder<GitRepository>
     */
    private function repositories(FeatureScope $scope): Builder
    {
        return $scope->projectId !== null
            ? GitRepository::query()->where('project_id', $scope->projectId)
            : GitRepository::query()->whereKey($scope->repositoryId)->whereNull('project_id');
    }

    /**
     * Resolve the AI provider for the scope from its first repository.
     *
     * @param  FeatureScope  $scope  The project or repository to group.
     *
     * @throws ValidationException When no repository or no provider is available.
     */
    private function provider(FeatureScope $scope): ResolvedAiProvider
    {
        $repository = $this->repositories($scope)->orderBy('full_name')->first();

        try {
            if ($repository === null) {
                throw new RuntimeException('No repository in scope.');
            }

            return $this->providers->forRepository($repository);
        } catch (Throwable) {
            throw ValidationException::withMessages(['scope' => 'No AI provider configured']);
        }
    }

    /**
     * Whether stored progress belongs to a live run.
     *
     * @param  array<string, mixed>  $state  The stored progress.
     */
    private function isRunning(array $state): bool
    {
        return ($state['running'] ?? false) === true
            && (int) ($state['updated_at'] ?? 0) >= now()->getTimestamp() - self::STALE_AFTER;
    }

    /**
     * Whether stored progress belongs to the given run.
     *
     * @param  array<string, mixed>  $state  The stored progress.
     * @param  string  $runId  The run to check.
     */
    private function owns(array $state, string $runId): bool
    {
        return ($state['run_id'] ?? null) === $runId;
    }

    /**
     * Begin a new run unless one is live, under a lock so two callers cannot both begin.
     *
     * @param  FeatureScope  $scope  The project or repository being grouped.
     * @param  bool  $allowEmpty  Begin even when no task is waiting.
     * @return ?string The new run's id; null when a run is live, nothing is waiting, or the lock was busy.
     */
    private function claim(FeatureScope $scope, bool $allowEmpty = false): ?string
    {
        $claimed = Cache::lock($this->cacheKey($scope).':start', 10)->get(function () use ($scope, $allowEmpty): ?string {
            if ($this->isRunning($this->state($scope))) {
                return null;
            }

            $total = $this->eligible($scope)->count();

            if ($total === 0 && ! $allowEmpty) {
                return null;
            }

            $runId = (string) Str::uuid();
            $this->put($scope, ['run_id' => $runId, 'running' => true, 'processed' => 0, 'total' => $total, 'attempted' => [], 'error' => null]);

            return $runId;
        });

        return is_string($claimed) ? $claimed : null;
    }

    /**
     * The scope's stored progress.
     *
     * @param  FeatureScope  $scope  The project or repository being grouped.
     * @return array<string, mixed>
     */
    private function state(FeatureScope $scope): array
    {
        return (array) Cache::get($this->cacheKey($scope), []);
    }

    /**
     * Store the scope's progress.
     *
     * @param  FeatureScope  $scope  The project or repository being grouped.
     * @param  array<string, mixed>  $state  The progress to keep.
     */
    private function put(FeatureScope $scope, array $state): void
    {
        Cache::put($this->cacheKey($scope), [...$state, 'updated_at' => now()->getTimestamp()], self::TTL);
    }
}
