<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFilterRequest;
use App\Services\Reports\DeveloperMetricsReportService;
use App\Services\Reports\ReportFilterOptionsService;
use App\Services\Reports\WeeklyVelocityReportService;
use App\Support\Reports\ReportPeriod;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Comparative table of developer productivity and quality metrics.
 */
class DevelopersReportController extends Controller
{
    public function __construct(
        private readonly DeveloperMetricsReportService $developers,
        private readonly WeeklyVelocityReportService $velocity,
        private readonly ReportFilterOptionsService $options,
    ) {}

    public function __invoke(ReportFilterRequest $request): Response
    {
        $period = $request->period(ReportPeriod::AllTime);
        $repositoryId = $request->repositoryId();

        return Inertia::render('reports/developers', [
            'developers' => $this->developers->handle($period, $repositoryId),
            'weekly_velocity' => $this->velocity->handle($repositoryId),
            'period' => $period->value,
            'repo_id' => $repositoryId,
            'repositories' => $this->options->repositories(),
            'sync_commit_stats_url' => route('reports.sync-commit-stats'),
        ]);
    }
}
