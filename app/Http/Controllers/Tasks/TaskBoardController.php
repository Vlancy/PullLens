<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\GIT\TaskRelation;
use App\Enums\GIT\TaskStatus;
use App\Enums\GIT\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\IndexTasksRequest;
use App\Services\Reports\ReportFilterOptionsService;
use App\Support\Access\RepositoryScope;
use App\Support\Presenters\GIT\TaskPresenter;
use App\Support\Queries\GIT\TaskQuery;
use App\Support\Reports\ReportPeriod;
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
    public function __construct(private readonly ReportFilterOptionsService $options) {}

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

        $paginator = $query->paginate(IndexTasksRequest::PER_PAGE);

        return Inertia::render('tasks/index', [
            'tasks' => TaskPresenter::collection($paginator->items()),
            'summary' => $query->summary(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'next_page_url' => $paginator->nextPageUrl(),
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
        ]);
    }
}
