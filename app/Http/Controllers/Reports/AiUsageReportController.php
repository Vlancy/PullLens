<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFilterRequest;
use App\Services\AI\AiUsageReportService;
use App\Support\Access\RepositoryScope;
use App\Support\Reports\ReportPeriod;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What PullLens is spending on AI, and where.
 *
 * Defaults to the last 30 days: long enough for a trend, short enough that the
 * numbers reflect the current configuration rather than a model you switched away
 * from two months ago.
 */
class AiUsageReportController extends Controller
{
    public function __construct(private readonly AiUsageReportService $usage) {}

    public function __invoke(ReportFilterRequest $request): Response
    {
        $period = $request->period(ReportPeriod::LastMonth);
        $scope = RepositoryScope::forUser($request->user());

        return Inertia::render('reports/ai-usage', [
            'stats' => $this->usage->totals($period, $scope),
            'by_operation' => $this->usage->byOperation($period, $scope),
            'by_model' => $this->usage->byModel($period, $scope),
            'by_repository' => $this->usage->byRepository($period, $scope),
            'largest_calls' => $this->usage->largestCalls($period, $scope),
            'trend' => $this->usage->trend($scope),
            'period' => $period->value,
            'periods' => array_map(
                static fn (ReportPeriod $option): array => [
                    'value' => $option->value,
                    'label' => $option->label(),
                ],
                ReportPeriod::cases(),
            ),
        ]);
    }
}
