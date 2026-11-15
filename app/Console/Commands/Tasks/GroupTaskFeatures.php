<?php

namespace App\Console\Commands\Tasks;

use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;
use App\Services\Tasks\Features\FeatureBackfillService;
use App\Support\Tasks\FeatureScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Groups a project's or a repository's existing tasks under features, synchronously.
 */
class GroupTaskFeatures extends Command
{
    /** @var string */
    protected $signature = 'tasks:group-features
        {--repository= : Repository id or owner/name (a repository without a project)}
        {--project= : Project id or name}';

    /** @var string */
    protected $description = 'Group existing tasks that have no feature, using the AI provider';

    /**
     * Resolve the scope from the options and group its tasks batch by batch.
     */
    public function handle(FeatureBackfillService $backfill): int
    {
        $scope = $this->scope();

        if ($scope === null) {
            return self::FAILURE;
        }

        $preview = $backfill->preview($scope);

        if ($preview['running']) {
            $this->error('Grouping is already running for this scope.');

            return self::FAILURE;
        }

        $this->info("Grouping {$preview['ungrouped']} task(s) in up to {$preview['batches']} AI call(s).");

        try {
            $processed = $backfill->run($scope, function (int $done, int $total): void {
                $this->line("  {$done} / {$total} task(s) processed");
            });
        } catch (ValidationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Done. {$processed} task(s) processed.");

        return self::SUCCESS;
    }

    /**
     * The scope named by the options, or null after printing why there is none.
     */
    private function scope(): ?FeatureScope
    {
        $repositoryOption = $this->option('repository');
        $projectOption = $this->option('project');

        if (($repositoryOption === null) === ($projectOption === null)) {
            $this->error('Pass exactly one of --repository or --project.');

            return null;
        }

        if ($projectOption !== null) {
            $project = Project::query()->whereKey($this->uuidOrNull($projectOption))
                ->orWhere('name', $projectOption)
                ->first();

            if ($project === null) {
                $this->error("Project [{$projectOption}] not found.");

                return null;
            }

            return new FeatureScope($project->id, null);
        }

        $repository = GitRepository::query()->whereKey($this->uuidOrNull($repositoryOption))
            ->orWhere('full_name', $repositoryOption)
            ->first();

        if ($repository === null) {
            $this->error("Repository [{$repositoryOption}] not found.");

            return null;
        }

        if ($repository->project_id !== null) {
            $this->error("Repository [{$repository->full_name}] belongs to a project; group the project with --project instead.");

            return null;
        }

        return new FeatureScope(null, $repository->id);
    }

    /**
     * The value when it looks like a UUID, so a name never reaches a uuid column comparison.
     */
    private function uuidOrNull(string $value): ?string
    {
        return Str::isUuid($value) ? $value : null;
    }
}
