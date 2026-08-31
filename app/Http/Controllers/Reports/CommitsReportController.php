<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFilterRequest;
use App\Services\Reports\CommitQualityReportService;
use App\Support\Reports\ReportPeriod;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Commit-message quality per developer.
 */
class CommitsReportController extends Controller
{
    public function __construct(private readonly CommitQualityReportService $commits) {}

    public function __invoke(ReportFilterRequest $request): Response
    {
        $period = $request->period(ReportPeriod::AllTime);

        return Inertia::render('reports/commits', [
            'commits' => $this->commits->handle($period),
            'period' => $period->value,
        ]);
    }
}
