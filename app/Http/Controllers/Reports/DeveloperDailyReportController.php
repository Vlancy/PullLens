<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFilterRequest;
use App\Services\Reports\DeveloperDailyReportService;
use App\Services\Reports\ReportFilterOptionsService;
use App\Support\Reports\ReportPeriod;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-developer daily effort breakdown.
 */
class DeveloperDailyReportController extends Controller
{
    /**
     * Inject the developer daily report service and report filter options service this class delegates to.
     */
    public function __construct(
        private readonly DeveloperDailyReportService $developerDaily,
        private readonly ReportFilterOptionsService $options,
    ) {}

    /**
     * Render the reports developer daily page.
     */
    public function __invoke(ReportFilterRequest $request): Response
    {
        $period = $request->period(ReportPeriod::LastWeek);
        $repositoryId = $request->repositoryId();

        return Inertia::render('reports/developer-daily', [
            'rows' => $this->developerDaily->handle($period, $repositoryId),
            'period' => $period->value,
            'repo_id' => $repositoryId,
            'repositories' => $this->options->repositories(),
        ]);
    }
}
