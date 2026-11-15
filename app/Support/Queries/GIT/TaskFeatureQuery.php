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
use Illuminate\Support\Facades\DB;

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
        $page = $this->features()
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
            // Grouped by where the feature lives - projects first, then repositories,
            // each alphabetical - and most recently active first within a group.
            ->orderByRaw('CASE WHEN task_features.project_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('LOWER(COALESCE(projects.name, git_repositories.full_name))')
            ->orderBy('task_features.scope_key')
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
                'group_key' => $row->scope_key,
                'group_label' => $row->project_name ?? $row->repository_name,
                'group_kind' => self::kind($row->scope_key),
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
        $branches = $this->lastBranches($rows->pluck('id')->all());

        $rows = $rows->map(static fn (array $row): array => [
            ...$row,
            'contributors' => $contributors[$row['id']] ?? [],
            'last_branch' => $branches[$row['id']] ?? null,
        ]);

        return (new Paginator($rows->all(), $page->total(), $page->perPage(), $page->currentPage(), $page->getOptions()))
            ->withQueryString();
    }

    /**
     * One entry per group (project or repository scope) across every feature page.
     *
     * Sums the per-feature counts, so each task counts once - a task has one feature -
     * and ordered the same way as the rows. The Ungrouped row is not a group.
     *
     * @return array<int, array{key: string, label: string|null, kind: string, features: int, tasks: int, shipped: int, came_back: int}>
     */
    public function groups(): array
    {
        return $this->features()
            ->select('task_features.scope_key')
            ->selectRaw('MAX(projects.name) as project_name')
            ->selectRaw('MAX(git_repositories.full_name) as repository_name')
            ->selectRaw('COUNT(*) as features')
            ->selectRaw('SUM(agg.tasks) as tasks')
            ->selectRaw('SUM(agg.shipped) as shipped')
            ->selectRaw('SUM(agg.came_back) as came_back')
            ->groupBy('task_features.scope_key')
            // The same ordering as the rows, in SQL, so the collation agrees with
            // the paginated row order (which "(continued)" relies on).
            // scope_key is the grouped column; pgsql has no MAX(uuid), so the project-first
            // rule reads its prefix instead of aggregating project_id.
            ->orderByRaw("CASE WHEN task_features.scope_key LIKE 'project:%' THEN 0 ELSE 1 END")
            ->orderByRaw('LOWER(COALESCE(MAX(projects.name), MAX(git_repositories.full_name)))')
            ->orderBy('task_features.scope_key')
            ->get()
            ->map(static fn (object $row): array => [
                'key' => $row->scope_key,
                'label' => $row->project_name ?? $row->repository_name,
                'kind' => self::kind($row->scope_key),
                'features' => (int) $row->features,
                'tasks' => (int) $row->tasks,
                'shipped' => (int) $row->shipped,
                'came_back' => (int) $row->came_back,
            ])
            ->all();
    }

    /**
     * Active features with at least one filtered task, joined to their counts and scope names.
     */
    private function features(): QueryBuilder
    {
        return TaskFeature::query()
            ->toBase()
            ->joinSub($this->aggregate()->whereNotNull('task_feature_id')->groupBy('task_feature_id'), 'agg', 'agg.task_feature_id', '=', 'task_features.id')
            ->leftJoin('projects', 'projects.id', '=', 'task_features.project_id')
            ->leftJoin('git_repositories', 'git_repositories.id', '=', 'task_features.git_repository_id')
            ->whereNull('task_features.merged_into_id');
    }

    /**
     * Whether a scope key names a project or a repository.
     */
    private static function kind(string $scopeKey): string
    {
        return str_starts_with($scopeKey, 'project:') ? 'project' : 'repository';
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
        return self::withCounts((clone $this->tasks)->toBase()->select('task_feature_id'))
            ->selectRaw('MAX(COALESCE(delivered_at, created_at)) as last_activity_at');
    }

    /**
     * Add the shared count columns (tasks, shipped, came_back) to a task aggregate.
     *
     * The single definition of the three counts, shared by the board rollup and the tasks report.
     *
     * @param  QueryBuilder  $query  A base query over the tasks table.
     */
    public static function withCounts(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->selectRaw('COUNT(*) as tasks')
            ->selectRaw('SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) as shipped')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as came_back', [
                TaskStatus::Reworked->value,
                TaskStatus::Reverted->value,
            ]);
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
            'group_key' => null,
            'group_label' => null,
            'group_kind' => null,
            'source' => null,
            'tasks' => (int) $row->tasks,
            'shipped' => (int) $row->shipped,
            'came_back' => (int) $row->came_back,
            'last_activity_at' => self::iso($row->last_activity_at),
            'last_branch' => null,
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

    /** How many release hops (e.g. feature -> stage -> prod) a branch chip follows. */
    private const MAX_PROMOTIONS = 5;

    /**
     * The pull request of each feature's most recent filtered task, as a branch summary.
     *
     * "Most recent" uses the row's last-activity ordering (delivery, else when the
     * task was seen). One windowed query for the whole page - ROW_NUMBER() runs on
     * both sqlite and pgsql - so the row count does not add queries. The Ungrouped
     * row has no single line of work, so it gets no branch.
     *
     * When that pull request was merged, the work is followed through later release
     * merges in the same repository (stage -> prod): `path` lists every branch it
     * reached, the last one being where it lives now.
     *
     * @param  array<int, string>  $rowIds  Feature ids, possibly including the ungrouped sentinel.
     * @return array<string, array{source: string|null, target: string|null, state: string|null, number: int|null, url: string|null, path: array<int, string>, promoted_by: array{number: int|null, url: string|null}|null}>
     */
    private function lastBranches(array $rowIds): array
    {
        $featureIds = array_values(array_diff($rowIds, [self::UNGROUPED]));

        if ($featureIds === []) {
            return [];
        }

        $ranked = (clone $this->tasks)
            ->toBase()
            ->whereIn('pull_request_tasks.task_feature_id', $featureIds)
            ->whereNotNull('pull_request_tasks.pull_request_id')
            ->select('pull_request_tasks.task_feature_id', 'pull_request_tasks.pull_request_id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY pull_request_tasks.task_feature_id ORDER BY COALESCE(pull_request_tasks.delivered_at, pull_request_tasks.created_at) DESC, pull_request_tasks.created_at DESC, pull_request_tasks.id DESC) as recency_rank');

        $latest = DB::query()
            ->fromSub($ranked, 'ranked')
            ->join('pull_requests', 'pull_requests.id', '=', 'ranked.pull_request_id')
            ->where('ranked.recency_rank', 1)
            ->select([
                'ranked.task_feature_id',
                'pull_requests.git_repository_id',
                'pull_requests.source_branch',
                'pull_requests.target_branch',
                'pull_requests.state',
                'pull_requests.number',
                'pull_requests.web_url',
                'pull_requests.merged_at',
            ])
            ->get();

        $releases = $this->releaseMerges($latest);

        return $latest
            ->mapWithKeys(fn (object $row): array => [
                $row->task_feature_id => [
                    'source' => $row->source_branch,
                    'target' => $row->target_branch,
                    'state' => $row->state,
                    'number' => $row->number === null ? null : (int) $row->number,
                    'url' => $row->web_url,
                    ...$this->promotion($row, $releases[$row->git_repository_id] ?? []),
                ],
            ])
            ->all();
    }

    /**
     * Merged pull requests that could have carried the page's work onwards, per repository.
     *
     * One query for the whole page: merged pull requests in the repositories of the
     * merged rows, from the earliest of those merges on, oldest first.
     *
     * @param  Collection<int, object>  $latest
     * @return array<string, array<int, object>>
     */
    private function releaseMerges(Collection $latest): array
    {
        $merged = $latest->filter(static fn (object $row): bool => $row->merged_at !== null && $row->target_branch !== null);

        if ($merged->isEmpty()) {
            return [];
        }

        return DB::table('pull_requests')
            ->whereIn('git_repository_id', $merged->pluck('git_repository_id')->unique()->values()->all())
            ->whereNotNull('merged_at')
            ->where('merged_at', '>=', $merged->min('merged_at'))
            ->orderBy('merged_at')
            ->orderBy('number')
            ->get(['git_repository_id', 'source_branch', 'target_branch', 'number', 'web_url', 'merged_at'])
            ->groupBy('git_repository_id')
            ->map(static fn (Collection $rows): array => $rows->all())
            ->all();
    }

    /**
     * Follow a merged pull request through later release merges in its repository.
     *
     * From the branch it landed on, the next hop is the first later merge whose source
     * is that branch (stage -> prod merged after the feature reached stage), and so on.
     * Unmerged work stays where it is.
     *
     * @param  array<int, object>  $releases  Merged pull requests of the row's repository, oldest first.
     * @return array{path: array<int, string>, promoted_by: array{number: int|null, url: string|null}|null}
     */
    private function promotion(object $row, array $releases): array
    {
        if ($row->merged_at === null || $row->target_branch === null) {
            return ['path' => [], 'promoted_by' => null];
        }

        $path = [$row->target_branch];
        $branch = $row->target_branch;
        $since = Carbon::parse($row->merged_at);
        $promotedBy = null;

        for ($hop = 0; $hop < self::MAX_PROMOTIONS; $hop++) {
            $next = null;

            foreach ($releases as $release) {
                if ($release->source_branch === $branch
                    && $release->target_branch !== null
                    && ! in_array($release->target_branch, $path, true)
                    && Carbon::parse($release->merged_at)->greaterThan($since)) {
                    $next = $release;
                    break;
                }
            }

            if ($next === null) {
                break;
            }

            $path[] = $next->target_branch;
            $branch = $next->target_branch;
            $since = Carbon::parse($next->merged_at);
            $promotedBy = ['number' => $next->number === null ? null : (int) $next->number, 'url' => $next->web_url];
        }

        return ['path' => $path, 'promoted_by' => $promotedBy];
    }

    /**
     * Normalise a raw aggregate timestamp, which drivers return as a string, to ISO 8601.
     */
    private static function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toISOString();
    }
}
