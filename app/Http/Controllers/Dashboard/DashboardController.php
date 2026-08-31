<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardStatisticsService;
use App\Services\Dashboard\SystemAlertService;
use App\Support\Access\RepositoryScope;
use App\Support\Presenters\GIT\RepositoryPresenter;
use App\Support\Presenters\GIT\ReviewPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The landing page after sign-in: system health, headline metrics and recent activity.
 *
 * Aggregation lives in DashboardStatisticsService and the health checks in
 * SystemAlertService, so this action only composes their output into page props.
 */
class DashboardController extends Controller
{
    /**
     * Inject the dashboard statistics service and system alert service this class delegates to.
     */
    public function __construct(
        private readonly DashboardStatisticsService $statistics,
        private readonly SystemAlertService $alerts,
    ) {}

    /**
     * Render the dashboard page.
     */
    public function __invoke(Request $request): Response
    {
        // A user restricted to certain repositories gets a dashboard describing only
        // those repositories, rather than installation-wide totals they cannot drill into.
        $scope = RepositoryScope::forUser($request->user());

        return Inertia::render('dashboard', [
            'system_alerts' => $this->alerts->handle($request->user()),
            'stats' => $this->statistics->totals($scope),
            'findings_by_severity' => $this->statistics->findingCountsBySeverity($scope),
            'findings_by_category' => $this->statistics->findingCountsByCategory($scope),
            'verdict_distribution' => $this->statistics->reviewCountsByVerdict($scope),
            'recent_reviews' => ReviewPresenter::collection($this->statistics->recentReviews($scope)),
            'top_repositories' => RepositoryPresenter::collectionWithCounts($this->statistics->topRepositories($scope)),
        ]);
    }
}
