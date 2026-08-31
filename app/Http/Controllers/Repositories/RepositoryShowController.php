<?php

namespace App\Http\Controllers\Repositories;

use App\Http\Controllers\Controller;
use App\Models\GIT\GitRepository;
use App\Services\Findings\FindingFilterOptionsService;
use App\Services\Repositories\RepositoryOverviewService;
use App\Support\Presenters\GIT\FindingPresenter;
use App\Support\Presenters\GIT\PullRequestPresenter;
use App\Support\Presenters\GIT\RepositoryPresenter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Detail page for one tracked repository: activity, risk and recent findings.
 */
class RepositoryShowController extends Controller
{
    /**
     * Inject the repository overview service and finding filter options service this class delegates to.
     */
    public function __construct(
        private readonly RepositoryOverviewService $overview,
        private readonly FindingFilterOptionsService $findingOptions,
    ) {}

    /**
     * Render the repositories show page.
     */
    public function __invoke(GitRepository $gitRepository): Response
    {
        // Route model binding resolves any id; the policy decides whether this user
        // is allowed to see this particular repository.
        $this->authorize('view', $gitRepository);

        return Inertia::render('repositories/show', [
            'repository' => RepositoryPresenter::toArray($gitRepository),
            'stats' => $this->overview->statistics($gitRepository),
            'findings_by_severity' => $this->overview->openFindingCountsBySeverity($gitRepository),
            'pull_requests' => PullRequestPresenter::collection($this->overview->pullRequests($gitRepository)),
            'recent_findings' => FindingPresenter::summaries($this->overview->recentFindings($gitRepository)),
            'resolution_types' => $this->findingOptions->resolutionTypes(),
        ]);
    }
}
