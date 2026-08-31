<?php

namespace App\Http\Controllers\Findings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Findings\IndexFindingsRequest;
use App\Services\Findings\FindingFilterOptionsService;
use App\Services\Findings\FindingStatisticsService;
use App\Support\Access\RepositoryScope;
use App\Support\Presenters\GIT\FindingPresenter;
use App\Support\Queries\GIT\FindingQuery;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The global, filterable findings backlog.
 *
 * Filtering and sorting live in FindingQuery, the tiles and trend in
 * FindingStatisticsService, and the dropdown data in FindingFilterOptionsService —
 * leaving this action to validate input, delegate, and assemble the page props.
 *
 * Everything below is bounded by the caller's RepositoryScope, so a user restricted
 * to a subset of repositories cannot read findings — or even repository and developer
 * names — from outside it.
 */
class FindingsController extends Controller
{
    /**
     * Inject the finding statistics service and finding filter options service this class delegates to.
     */
    public function __construct(
        private readonly FindingStatisticsService $statistics,
        private readonly FindingFilterOptionsService $options,
    ) {}

    /**
     * Render the findings page.
     */
    public function __invoke(IndexFindingsRequest $request): Response
    {
        // The user's grants first, then the repository they picked in the UI. Narrowing
        // in that order means a crafted repository_id can never widen the result set.
        $visible = RepositoryScope::forUser($request->user());
        $selected = $visible->intersect($request->repositoryId());

        $authorLogin = $request->authorLogin();

        $query = (new FindingQuery)
            ->withinScope($selected)
            ->withSeverities($request->severities())
            ->inCategory($request->category())
            ->withStatus($request->status())
            ->matching($request->search())
            ->authoredBy($authorLogin)
            ->sortBy($request->sort());

        $page = $request->page();

        return Inertia::render('findings/index', [
            'findings' => FindingPresenter::collection($query->page($page, IndexFindingsRequest::PER_PAGE)),
            'total' => $query->count(),
            'page' => $page,
            'per_page' => IndexFindingsRequest::PER_PAGE,

            // Statistics intentionally ignore the severity/status/search filters so the
            // tiles describe the whole backlog for this repository and author.
            'stats' => $this->statistics->totals($selected, $authorLogin),
            'top_categories' => $this->statistics->topCategories($selected, $authorLogin),
            'trend' => $this->statistics->trend($selected, $authorLogin),

            // Options come from the user's full grant set, not the current selection,
            // so choosing one repository does not empty the repository dropdown.
            'repositories' => $this->options->repositories($visible),
            'developers' => $this->options->developers($selected),
            'categories' => $this->options->categories($visible),
            'resolution_types' => $this->options->resolutionTypes(),

            'filters' => $request->filterState(),
        ]);
    }
}
