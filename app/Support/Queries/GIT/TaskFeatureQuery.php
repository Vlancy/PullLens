<?php

namespace App\Support\Queries\GIT;

use App\Enums\GIT\TaskStatus;
use App\Http\Requests\Tasks\IndexTasksRequest;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequestTask;
use App\Models\GIT\TaskFeature;
use App\Support\Access\RepositoryScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The feature rollup on the tasks board: one row per feature with its counts.
 *
 * Aggregates the already-filtered (and already viewer-scoped) task query, so a
 * feature only counts the tasks this viewer can see under these filters, and a
 * feature with none of them does not appear at all.
 */
class TaskFeatureQuery
{
    /** The id of the synthetic row holding tasks without a feature - the board's filter value. */
    public const UNGROUPED = IndexTasksRequest::UNGROUPED;

    /** How many contributors a row lists. */
    private const CONTRIBUTORS = 5;

    /** @var Builder<PullRequestTask> */
    private Builder $tasks;

    /**
     * Snapshot the filtered task query, so later narrowing of it does not leak in.
     *
     * @param  TaskQuery  $filtered  The board's filtered, viewer-scoped tasks.
     */
    public function __construct(TaskQuery $filtered)
    {
        $this->tasks = clone $filtered->builder();
    }

    /**
     * One page of feature rows, most recently active first.
     *
     * The Ungrouped row is appended to the last page only, so it always sorts after
     * every feature; it does not count toward the paginator's total.
     *
     * @param  int  $perPage  Feature rows per page.
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function rows(int $perPage): LengthAwarePaginator
    {
        $page = TaskFeature::query()
            ->toBase()
            ->joinSub($this->aggregate()->whereNotNull('task_feature_id')->groupBy('task_feature_id'), 'agg', 'agg.task_feature_id', '=', 'task_features.id')
            ->leftJoin('projects', 'projects.id', '=', 'task_features.project_id')
            ->leftJoin('git_repositories', 'git_repositories.id', '=', 'task_features.git_repository_id')
            ->whereNull('task_features.merged_into_id')
            ->select([
                'task_features.id',
                'task_features.name',
                'task_features.description',
                'task_features.source',
                'task_features.scope_key',
                'projects.name as project_name',
                'git_repositories.full_name as repository_name',
                'agg.tasks',
                'agg.shipped',
                'agg.came_back',
                'agg.last_activity_at',
            ])
            ->orderByDesc('agg.last_activity_at')
            ->orderBy('task_features.name')
            ->orderBy('task_features.id')
            ->paginate($perPage, pageName: 'features_page')
            ->withQueryString();

        $rows = Collection::make($page->items())
            ->map(static fn (object $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'description' => $row->description,
                'scope_key' => $row->scope_key,
                'scope_label' => $row->project_name ?? $row->repository_name,
                'source' => $row->source,
                'tasks' => (int) $row->tasks,
                'shipped' => (int) $row->shipped,
                'came_back' => (int) $row->came_back,
                'last_activity_at' => self::iso($row->last_activity_at),
            ]);

        if ($page->currentPage() === $page->lastPage() && ($ungrouped = $this->ungrouped()) !== null) {
            $rows->push($ungrouped);
        }

        $contributors = $this->contributors($rows->pluck('id')->all());

        $rows = $rows->map(static fn (array $row): array => [
            ...$row,
            'contributors' => $contributors[$row['id']] ?? [],
        ]);

        return (new Paginator($rows->all(), $page->total(), $page->perPage(), $page->currentPage(), $page->getOptions()))
            ->withQueryString();
    }

    /**
     * Active features a manager may move tasks into or merge into, within the visible scopes.
     *
     * @param  RepositoryScope  $scope  The viewer's repository grants.
     * @return array<int, array{id: string, name: string, scope_key: string, scope_label: string|null}>
     */
    public static function options(RepositoryScope $scope): array
    {
        $query = TaskFeature::query()
            ->active()
            ->leftJoin('projects', 'projects.id', '=', 'task_features.project_id')
            ->leftJoin('git_repositories', 'git_repositories.id', '=', 'task_features.git_repository_id')
            ->select([
                'task_features.id',
                'task_features.name',
                'task_features.scope_key',
                'projects.name as project_name',
                'git_repositories.full_name as repository_name',
            ])
            ->orderBy('task_features.name');

        $ids = $scope->ids();

        if ($ids !== null) {
            $query->where(fn (Builder $inner) => $inner
                ->whereIn('task_features.git_repository_id', $ids)
                ->orWhereIn('task_features.project_id', GitRepository::query()
                    ->whereIn('id', $ids)
                    ->whereNotNull('project_id')
                    ->select('project_id')));
        }

        return $query->toBase()->get()
            ->map(static fn (object $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'scope_key' => $row->scope_key,
                'scope_label' => $row->project_name ?? $row->repository_name,
            ])
            ->all();
    }

    /**
     * The count columns over the filtered tasks, ready to be grouped or narrowed.
     */
    private function aggregate(): QueryBuilder
    {
        return (clone $this->tasks)
            ->toBase()
            ->select('task_feature_id')
            ->selectRaw('COUNT(*) as tasks')
            ->selectRaw('SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) as shipped')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as came_back', [
                TaskStatus::Reworked->value,
                TaskStatus::Reverted->value,
            ])
            ->selectRaw('MAX(COALESCE(delivered_at, created_at)) as last_activity_at');
    }

    /**
     * The synthetic row for filtered tasks that have no feature, or null when there are none.
     *
     * @return array<string, mixed>|null
     */
    private function ungrouped(): ?array
    {
        $row = $this->aggregate()
            ->whereNull('task_feature_id')
            ->groupBy('task_feature_id')
            ->first();

        if ($row === null || (int) $row->tasks === 0) {
            return null;
        }

        return [
            'id' => self::UNGROUPED,
            'name' => 'Ungrouped',
            'description' => null,
            'scope_key' => null,
            'scope_label' => null,
            'source' => null,
            'tasks' => (int) $row->tasks,
            'shipped' => (int) $row->shipped,
            'came_back' => (int) $row->came_back,
            'last_activity_at' => self::iso($row->last_activity_at),
        ];
    }

    /**
     * The top contributors of each row, by how many of its filtered tasks they authored.
     *
     * One grouped query for the whole page, so the row count does not add queries.
     *
     * @param  array<int, string>  $rowIds  Feature ids, possibly including the ungrouped sentinel.
     * @return array<string, array<int, array{login: string, name: string|null, avatar_url: string|null}>>
     */
    private function contributors(array $rowIds): array
    {
        if ($rowIds === []) {
            return [];
        }

        $featureIds = array_values(array_diff($rowIds, [self::UNGROUPED]));
        $withUngrouped = in_array(self::UNGROUPED, $rowIds, true);

        return (clone $this->tasks)
            ->toBase()
            ->whereNotNull('author_login')
            ->where(fn (QueryBuilder $inner) => $inner
                ->whereIn('task_feature_id', $featureIds)
                ->when($withUngrouped, fn (QueryBuilder $q) => $q->orWhereNull('task_feature_id')))
            ->select('task_feature_id', 'author_login')
            ->selectRaw('COUNT(*) as tasks')
            ->selectRaw('MAX(author_name) as author_name')
            ->selectRaw('MAX(author_avatar_url) as author_avatar_url')
            ->groupBy('task_feature_id', 'author_login')
            ->orderByDesc('tasks')
            ->orderBy('author_login')
            ->get()
            ->groupBy(static fn (object $row): string => $row->task_feature_id ?? self::UNGROUPED)
            ->map(static fn (Collection $group): array => $group
                ->take(self::CONTRIBUTORS)
                ->map(static fn (object $row): array => [
                    'login' => $row->author_login,
                    'name' => $row->author_name,
                    'avatar_url' => $row->author_avatar_url,
                ])
                ->values()
                ->all())
            ->all();
    }

    /**
     * Normalise a raw aggregate timestamp, which drivers return as a string, to ISO 8601.
     */
    private static function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toISOString();
    }
}
