<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFilterRequest;
use App\Services\Reports\DeveloperMetricsReportService;
use App\Services\Reports\DeveloperProfileReportService;
use App\Services\Reports\ReportFilterOptionsService;
use App\Support\Reports\ReportPeriod;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deep dive into a single developer: contribution calendar, trends and recent PRs.
 */
class DeveloperProfileReportController extends Controller
{
    /**
     * Inject the developer profile report service, developer metrics report service and report filter options service this class delegates to.
     */
    public function __construct(
        private readonly DeveloperProfileReportService $profile,
        private readonly DeveloperMetricsReportService $developers,
        private readonly ReportFilterOptionsService $options,
    ) {}

    /**
     * Run this action.
     *
     * @param  string  $login  Provider login, taken from the route.
     */
    public function __invoke(ReportFilterRequest $request, string $login): Response
    {
        $repositoryId = $request->repositoryId();

        // The summary row is looked up from the same all-time dataset the developers
        // table renders, so the two pages never disagree about a developer's numbers.
        $summary = collect($this->developers->handle(ReportPeriod::AllTime, $repositoryId))
            ->firstWhere('author_login', $login);

        return Inertia::render('reports/developer-profile', [
            'login' => $login,
            'developer' => $summary,
            'profile' => $this->profile->handle($login, $repositoryId),
            'repo_id' => $repositoryId,
            'repositories' => $this->options->repositories(),
        ]);
    }
}
