<?php

namespace App\Http\Controllers\Reports;

use App\Enums\GIT\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\TaskReportRequest;
use App\Services\Reports\ReportFilterOptionsService;
use App\Services\Tasks\TaskReportService;
use App\Support\Access\RepositoryScope;
use App\Support\Reports\ReportPeriod;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What each developer delivered over a period.
 *
 * Defaults to the current calendar month, since the question this answers is
 * usually asked at month end.
 */
class TaskReportController extends Controller
{
    public function __construct(
        private readonly TaskReportService $tasks,
        private readonly ReportFilterOptionsService $options,
    ) {}

    public function __invoke(TaskReportRequest $request): Response
    {
        $period = $request->period(ReportPeriod::ThisCalendarMonth);
        $author = $request->author();
        $type = $request->taskType();

        $scope = RepositoryScope::forUser($request->user())
            ->intersect($request->repositoryId());

        return Inertia::render('reports/tasks', [
            'developers' => $this->tasks->byDeveloper($period, $scope, $author, $type),
            'stats' => $this->tasks->totals($period, $scope, $author, $type),
            'period' => $period->value,
            'periods' => array_map(
                static fn (ReportPeriod $option): array => [
                    'value' => $option->value,
                    'label' => $option->label(),
                ],
                ReportPeriod::cases(),
            ),
            'author' => $author,
            'authors' => $this->options->authors(),
            'repo_id' => $request->repositoryId(),
            'repositories' => $this->options->repositories(),
            'type' => $type?->value,
            'types' => TaskType::options(),
        ]);
    }
}
