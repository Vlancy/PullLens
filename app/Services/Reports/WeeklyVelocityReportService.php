<?php

namespace App\Services\Reports;

use App\Models\GIT\PullRequest;
use App\Support\Reports\DateSeries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * PRs opened and merged per week, for the velocity chart on the developers report.
 */
class WeeklyVelocityReportService
{
    /** Trailing weeks shown on the chart, including the current one. */
    private const WEEKS = 8;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(?string $repositoryId = null): array
    {
        $rows = PullRequest::query()
            ->where('opened_at', '>=', now()->startOfWeek()->subWeeks(self::WEEKS - 1))
            ->when($repositoryId, fn (Builder $q) => $q->where('git_repository_id', $repositoryId))
            ->select([
                DB::raw("DATE_TRUNC('week', opened_at)::date as week"),
                DB::raw('COUNT(*) as opened'),
                DB::raw('SUM(CASE WHEN merged_at IS NOT NULL THEN 1 ELSE 0 END) as merged'),
            ])
            ->groupBy(DB::raw("DATE_TRUNC('week', opened_at)::date"))
            ->get()
            ->keyBy('week');

        return DateSeries::weekly(self::WEEKS, $rows, static fn (?object $row, string $week): array => [
            'week' => $week,
            'opened' => (int) ($row->opened ?? 0),
            'merged' => (int) ($row->merged ?? 0),
        ]);
    }
}
