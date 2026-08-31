<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFilterRequest;
use App\Services\Reports\DailyActivityReportService;
use App\Services\Reports\ReportFilterOptionsService;
use App\Support\Reports\ReportPeriod;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Day-by-day activity breakdown across the whole installation.
 */
class DailyReportController extends Controller
{
    public function __construct(
        private readonly DailyActivityReportService $daily,
        private readonly ReportFilterOptionsService $options,
    ) {}

    public function __invoke(ReportFilterRequest $request): Response
    {
        $period = $request->period(ReportPeriod::LastMonth);
        $author = $request->author();

        return Inertia::render('reports/daily', [
            'days' => $this->daily->handle($period, $author),
            'period' => $period->value,
            'author' => $author,
            'authors' => $this->options->authors(),
        ]);
    }
}
