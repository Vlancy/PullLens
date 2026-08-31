<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFilterRequest;
use App\Services\Reports\LeaderboardReportService;
use App\Services\Reports\OverviewReportService;
use App\Services\Reports\ReportFilterOptionsService;
use App\Support\Reports\ReportPeriod;
use Inertia\Inertia;
use Inertia\Response;

/**
 * System-wide reporting overview.
 *
 * Defaults to today: the overview answers "what is happening right now", and an
 * all-time default would bury current activity under historical totals.
 */
class OverviewReportController extends Controller
{
    /**
     * Inject the overview report service, leaderboard report service and report filter options service this class delegates to.
     */
    public function __construct(
        private readonly OverviewReportService $overview,
        private readonly LeaderboardReportService $leaderboard,
        private readonly ReportFilterOptionsService $options,
    ) {}

    /**
     * Render the reports overview page.
     */
    public function __invoke(ReportFilterRequest $request): Response
    {
        $period = $request->period(ReportPeriod::Today);
        $author = $request->author();

        return Inertia::render('reports/overview', [
            'stats' => $this->overview->handle($period, $author),
            'leaderboard' => $this->leaderboard->handle($period),
            'period' => $period->value,
            'author' => $author,
            'authors' => $this->options->authors(),
        ]);
    }
}
