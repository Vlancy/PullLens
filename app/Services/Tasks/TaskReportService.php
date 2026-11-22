<?php

namespace App\Services\Tasks;

use App\Enums\GIT\TaskType;
use App\Models\GIT\PullRequestTask;
use App\Models\GIT\TaskFeature;
use App\Support\Access\RepositoryScope;
use App\Support\Queries\GIT\TaskFeatureQuery;
use App\Support\Reports\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Answers "who did what" over a period.
 *
 * Groups the delivered tasks by developer, so a month-end review is one page rather
 * than a trawl through pull requests. Counts come from a grouped query and the task
 * rows are fetched once and grouped in memory, so the page cost does not grow with
 * the number of developers.
 */
class TaskReportService
{
    /** Tasks listed per developer before the UI asks for more. */
    private const TASKS_PER_DEVELOPER = 100;

    /** Features listed in the by-feature breakdown. */
    private const TOP_FEATURES = 20;

    /**
     * Per-developer task breakdown for the period.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byDeveloper(
        ReportPeriod $period,
        RepositoryScope $scope,
        ?string $authorLogin = null,
        ?TaskType $type = null,
    ): array {
        $tasks = $this->scoped($period, $scope, $authorLogin, $type)
            ->with(['pullRequest:id,number,title,web_url,state,merged_at', 'repository:id,name,full_name'])
            ->orderByDesc('delivered_at')
            ->limit(self::TASKS_PER_DEVELOPER * 25)
            ->get();

        if ($tasks->isEmpty()) {
            return [];
        }

        $commitCounts = $this->commitCountsByPullRequest($tasks);

        return $tasks
            ->groupBy('author_login')
            ->map(fn (Collection $group, string $login): array => $this->developerRow($login, $group, $commitCounts))
            ->sortByDesc('total_tasks')
            ->values()
            ->all();
    }

    /**
     * Installation-wide totals for the period, for the tiles above the table.
     *
     * @return array<string, mixed>
     */
    public function totals(
        ReportPeriod $period,
        RepositoryScope $scope,
        ?string $authorLogin = null,
        ?TaskType $type = null,
    ): array {
        $row = $this->scoped($period, $scope, $authorLogin, $type)
            ->selectRaw('COUNT(*) as total, COUNT(DISTINCT author_login) as developers, SUM(estimated_hours) as hours')
            ->first();

        $byType = $this->scoped($period, $scope, $authorLogin, $type)
            ->select(['type', DB::raw('COUNT(*) as total')])
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return [
            'total_tasks' => (int) ($row->total ?? 0),
            'developers' => (int) ($row->developers ?? 0),
            'estimated_hours' => round((float) ($row->hours ?? 0), 1),
            'by_type' => $this->fillTypes($byType),
        ];
    }

    /**
     * The busiest features for the period, by task count.
     *
     * Uses the board rollup's count definitions and the report's own filters and
     * viewer scope. Ungrouped tasks are left out: they are not a feature, and the
     * developer list above already accounts for them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byFeature(
        ReportPeriod $period,
        RepositoryScope $scope,
        ?string $authorLogin = null,
        ?TaskType $type = null,
    ): array {
        $aggregate = TaskFeatureQuery::withCounts(
            $this->scoped($period, $scope, $authorLogin, $type)
                ->toBase()
                ->select('task_feature_id')
        )
            ->selectRaw('SUM(estimated_hours) as hours')
            ->whereNotNull('task_feature_id')
            ->groupBy('task_feature_id');

        return TaskFeature::query()
            ->toBase()
            ->joinSub($aggregate, 'agg', 'agg.task_feature_id', '=', 'task_features.id')
            // The scope name tells apart features of the same name in different projects.
            ->leftJoin('projects', 'projects.id', '=', 'task_features.project_id')
            ->leftJoin('git_repositories', 'git_repositories.id', '=', 'task_features.git_repository_id')
            ->whereNull('task_features.merged_into_id')
            ->orderByDesc('agg.tasks')
            ->orderBy('task_features.name')
            ->orderBy('task_features.id')
            ->limit(self::TOP_FEATURES)
            ->get([
                'task_features.id',
                'task_features.name',
                'projects.name as project_name',
                'git_repositories.full_name as repository_name',
                'agg.tasks',
                'agg.shipped',
                'agg.came_back',
                'agg.hours',
            ])
            ->map(static fn (object $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'scope_label' => $row->project_name ?? $row->repository_name,
                'tasks' => (int) $row->tasks,
                'shipped' => (int) $row->shipped,
                'came_back' => (int) $row->came_back,
                'estimated_hours' => round((float) $row->hours, 1),
            ])
            ->all();
    }

    /**
     * One developer's row: their tasks plus the totals derived from them.
     *
     * @param  Collection<int, PullRequestTask>  $tasks
     * @param  array<string, int>  $commitCounts
     * @return array<string, mixed>
     */
    private function developerRow(string $login, Collection $tasks, array $commitCounts): array
    {
        $first = $tasks->first();

        $byType = [];

        foreach ($tasks as $task) {
            $value = $task->type?->value ?? TaskType::Chore->value;
            $byType[$value] = ($byType[$value] ?? 0) + 1;
        }

        return [
            'author_login' => $login,
            'author_name' => $first->author_name ?? $login,
            'author_avatar_url' => $first->author_avatar_url,
            'total_tasks' => $tasks->count(),
            'estimated_hours' => round((float) $tasks->sum('estimated_hours'), 1),
            // Distinct PRs, since one PR can deliver several tasks.
            'pull_requests' => $tasks->pluck('pull_request_id')->unique()->count(),
            'commits' => $tasks
                ->pluck('pull_request_id')
                ->unique()
                ->sum(static fn (string $id): int => $commitCounts[$id] ?? 0),
            'by_type' => $this->fillTypes($byType),
            'tasks' => $tasks
                ->take(self::TASKS_PER_DEVELOPER)
                ->map(fn (PullRequestTask $task): array => $this->taskRow($task, $commitCounts))
                ->values()
                ->all(),
        ];
    }

    /**
     * Serialize one task for the report.
     *
     * @param  array<string, int>  $commitCounts
     * @return array<string, mixed>
     */
    private function taskRow(PullRequestTask $task, array $commitCounts): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'type' => $task->type?->value,
            'type_label' => $task->type?->label(),
            'description' => $task->description,
            'estimated_hours' => $task->estimated_hours,
            'files' => $task->files ?? [],
            'delivered_at' => $task->delivered_at?->toISOString(),
            'commits' => $commitCounts[$task->pull_request_id] ?? 0,
            'repository' => $task->repository === null ? null : [
                'id' => $task->repository->id,
                'full_name' => $task->repository->full_name,
            ],
            'pull_request' => $task->pullRequest === null ? null : [
                'number' => $task->pullRequest->number,
                'title' => $task->pullRequest->title,
                'web_url' => $task->pullRequest->web_url,
                'state' => $task->pullRequest->state?->value,
            ],
        ];
    }

    /**
     * Commit counts for the pull requests behind these tasks, keyed by PR id.
     *
     * A task inherits its pull request's commits, so this is counted once per PR
     * rather than once per task.
     *
     * @param  Collection<int, PullRequestTask>  $tasks
     * @return array<string, int>
     */
    private function commitCountsByPullRequest(Collection $tasks): array
    {
        return DB::table('pull_request_commits')
            ->whereIn('pull_request_id', $tasks->pluck('pull_request_id')->unique()->all())
            ->select(['pull_request_id', DB::raw('COUNT(*) as total')])
            ->groupBy('pull_request_id')
            ->pluck('total', 'pull_request_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * Base query: delivered tasks inside the window, scope, author and type filters.
     *
     * Only merged work counts. Tasks on an open PR describe intent, and counting them
     * as delivered would let an unmerged branch inflate somebody's month.
     *
     * @return Builder<PullRequestTask>
     */
    private function scoped(
        ReportPeriod $period,
        RepositoryScope $scope,
        ?string $authorLogin,
        ?TaskType $type,
    ): Builder {
        $query = PullRequestTask::query()
            ->delivered()
            ->deliveredBetween($period->startsAt(), $period->endsAt())
            ->when($authorLogin, fn (Builder $q) => $q->where('author_login', $authorLogin))
            ->when($type, fn (Builder $q) => $q->where('type', $type->value));

        return $scope->applyTo($query);
    }

    /**
     * Ensure every type appears, so the chart legend is stable between periods.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function fillTypes(array $counts): array
    {
        $filled = [];

        foreach (TaskType::cases() as $type) {
            $filled[$type->value] = $counts[$type->value] ?? 0;
        }

        return $filled;
    }
}
