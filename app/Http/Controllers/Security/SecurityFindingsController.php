<?php

namespace App\Http\Controllers\Security;

use App\Enums\GIT\SecurityFindingKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\IndexSecurityRequest;
use App\Services\Findings\FindingFilterOptionsService;
use App\Services\Security\SecurityStatisticsService;
use App\Support\Access\RepositoryScope;
use App\Support\Presenters\GIT\SecurityFindingPresenter;
use App\Support\Queries\GIT\SecurityFindingQuery;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Security page: leaked secrets, vulnerable dependencies and insecure
 * infrastructure settings, apart from the AI review findings.
 *
 * Everything is bounded by the caller's RepositoryScope, exactly like Findings.
 */
class SecurityFindingsController extends Controller
{
    /**
     * Inject the security statistics service and finding filter options service this class delegates to.
     */
    public function __construct(
        private readonly SecurityStatisticsService $statistics,
        private readonly FindingFilterOptionsService $options,
    ) {}

    /**
     * Render the Security page.
     */
    public function __invoke(IndexSecurityRequest $request): Response
    {
        // The user's grants first, then the repository they picked, so a crafted
        // repository_id can only narrow the result set.
        $visible = RepositoryScope::forUser($request->user());
        $selected = $visible->intersect($request->repositoryId());

        $query = (new SecurityFindingQuery)
            ->withinScope($selected)
            ->ofKind($request->kind())
            ->withSeverities($request->severities())
            ->withStatus($request->status())
            ->matching($request->search())
            ->sortBy($request->sort());

        $page = $request->page();

        return Inertia::render('security/index', [
            'findings' => SecurityFindingPresenter::collection($query->page($page, IndexSecurityRequest::PER_PAGE)),
            'total' => $query->count(),
            'page' => $page,
            'per_page' => IndexSecurityRequest::PER_PAGE,
            'tiles' => $this->statistics->tiles($selected),
            'open_counts' => $this->statistics->openCounts($selected),
            'scanning_enabled' => $this->statistics->scanningEnabled($selected),
            'repositories' => $this->options->repositories($visible),
            // Only manualCases(), which the bulk and single resolve endpoints also enforce.
            'resolution_types' => $this->options->resolutionTypes(),
            'kinds' => array_map(fn (SecurityFindingKind $k) => ['value' => $k->value, 'label' => $k->label()], SecurityFindingKind::cases()),
            'filters' => $request->filterState(),
        ]);
    }
}
