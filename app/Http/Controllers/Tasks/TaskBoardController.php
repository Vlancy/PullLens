<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\GIT\TaskRelation;
use App\Enums\GIT\TaskStatus;
use App\Enums\GIT\TaskType;
use App\Enums\Users\UserPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\IndexTasksRequest;
use App\Services\Reports\ReportFilterOptionsService;
use App\Support\Access\RepositoryScope;
use App\Support\Presenters\GIT\TaskPresenter;
use App\Support\Queries\GIT\TaskFeatureQuery;
use App\Support\Queries\GIT\TaskQuery;
use App\Support\Reports\ReportPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tasks board: every unit of work PullLens has identified, searchable and
 * filterable, with the history of what came back to each one.
 *
 * Lives outside /reports on purpose. The reports answer "how did we do over a
 * period"; this answers "what is the state of this piece of work", which is a
 * different question people arrive at from a different direction.
 */
class TaskBoardController extends Controller
{
    /**
     * Inject the report filter options service this class delegates to.
     */
    public function __construct(private readonly ReportFilterOptionsService $options) {}

    /**
     * Render the tasks page.
     */
    public function __invoke(IndexTasksRequest $request): Response
    {
        // The viewer's grants first, then the repository they picked, so a crafted
        // repo_id can only narrow the result set.
        $visible = RepositoryScope::forUser($request->user());
        $selected = $visible->intersect($request->repositoryId());

        $query = (new TaskQuery)
            ->withinScope($selected)
            ->matching($request->keyword())
            ->ofType($request->type())
            ->withStatus($request->status())
            ->authoredBy($request->author())
            ->inPeriod($request->period())
            ->onlyNeedingRework($request->onlyRework())
            ->relatedTo($request->relatedTo());

        // Snapshot the rollup and the summary before narrowing to one feature: in the
        // features view, expanding a row loads its tasks without changing the rows.
        $features = $request->wantsFeatures() ? new TaskFeatureQuery($query) : null;
        $summary = $request->wantsFeatures() ? new TaskQuery(clone $query->builder()) : $query;

        $query->inFeature($request->feature());
        $paginator = $query->paginate(IndexTasksRequest::PER_PAGE);
        $canManage = $request->user()->hasPermission(UserPermission::ManageRepositories);

        return Inertia::render('tasks/index', [
            'tasks' => TaskPresenter::collection($paginator->items()),
            // Closures, so expanding a feature row (a partial reload of the task list)
            // does not recompute the summary, the rollup or the options.
            'summary' => fn (): array => $summary->summary(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                // The 1-based bounds of this page, so the board can say which slice
                // of the result set is on screen rather than only the page number.
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'next_page_url' => $paginator->nextPageUrl(),
                // Which feature these tasks were loaded for, so an expanded row
                // knows the list on screen is its own.
                'feature' => $request->feature(),
            ],
            'filters' => $request->filterState(),
            'types' => TaskType::options(),
            'statuses' => TaskStatus::options(),
            'relations' => TaskRelation::options(),
            'periods' => array_map(
                static fn (ReportPeriod $period): array => [
                    'value' => $period->value,
                    'label' => $period->label(),
                ],
                ReportPeriod::cases(),
            ),
            'authors' => $this->options->authors(),
            'repositories' => $this->options->repositories(),
            'can_manage_features' => $canManage,
            // Rename, merge and move targets - only for managers, only in visible scopes.
            'feature_options' => fn (): array => $canManage ? TaskFeatureQuery::options($visible) : [],
            ...($features === null ? [] : [
                'features' => fn (): array => $this->featureRows($features->rows(IndexTasksRequest::FEATURES_PER_PAGE)),
            ]),
        ]);
    }

    /**
     * Shape a page of feature rows with the same pagination keys as the task list.
     *
     * @param  LengthAwarePaginator<int, array<string, mixed>>  $rows
     * @return array{data: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    private function featureRows(LengthAwarePaginator $rows): array
    {
        return [
            'data' => $rows->items(),
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ];
    }
}
