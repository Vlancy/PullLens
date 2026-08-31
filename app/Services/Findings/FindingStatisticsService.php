<?php

namespace App\Services\Findings;

use App\Enums\GIT\FindingSeverity;
use App\Models\GIT\PullRequestReviewFinding;
use App\Support\Access\RepositoryScope;
use App\Support\Reports\DateSeries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Headline counts, category ranking and the 30-day trend for the findings page.
 *
 * Scoped only by repository and author — deliberately *not* by severity, status or
 * search. The tiles are meant to describe the whole backlog for that scope, so that
 * narrowing the list to "critical only" does not also change the totals being
 * compared against.
 */
class FindingStatisticsService
{
    /** Days covered by the trend chart. */
    private const TREND_DAYS = 30;

    /** Categories shown in the breakdown. */
    private const TOP_CATEGORY_LIMIT = 8;

    /**
     * Severity, resolution and total counts.
     *
     * @return array<string, int>
     */
    public function totals(RepositoryScope $scope, ?string $authorLogin): array
    {
        $row = $this->scoped($scope, $authorLogin)
            ->selectRaw($this->totalsExpression())
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'critical' => (int) ($row->critical ?? 0),
            'high' => (int) ($row->high ?? 0),
            'medium' => (int) ($row->medium ?? 0),
            'low' => (int) ($row->low ?? 0),
            'informational' => (int) ($row->informational ?? 0),
            'resolved' => (int) ($row->resolved ?? 0),
            'open' => (int) ($row->open_count ?? 0),
        ];
    }

    /**
     * The most common finding categories in this scope.
     *
     * @return array<int, array{category: string, count: int}>
     */
    public function topCategories(RepositoryScope $scope, ?string $authorLogin): array
    {
        return $this->scoped($scope, $authorLogin)
            ->whereNotNull('category')
            ->select(['category', DB::raw('COUNT(*) as count')])
            ->groupBy('category')
            ->orderByDesc('count')
            ->limit(self::TOP_CATEGORY_LIMIT)
            ->get()
            ->map(static fn (PullRequestReviewFinding $row): array => [
                'category' => $row->category?->value ?? (string) $row->getAttribute('category'),
                'count' => (int) $row->getAttribute('count'),
            ])
            ->all();
    }

    /**
     * Findings raised per day over the trend window, with zero-filled gaps.
     *
     * @return array<int, array{date: string, count: int}>
     */
    public function trend(RepositoryScope $scope, ?string $authorLogin): array
    {
        $rows = $this->scoped($scope, $authorLogin)
            ->where('created_at', '>=', now()->subDays(self::TREND_DAYS - 1)->startOfDay())
            ->select([
                DB::raw('CAST(created_at AS DATE) as date'),
                DB::raw('COUNT(*) as count'),
            ])
            ->groupBy(DB::raw('CAST(created_at AS DATE)'))
            ->get()
            ->keyBy('date');

        return DateSeries::daily(self::TREND_DAYS, $rows, static fn (mixed $row, string $date): array => [
            'date' => $date,
            'count' => (int) ($row?->getAttribute('count') ?? 0),
        ]);
    }

    /**
     * Base query carrying only the repository scope and author filter.
     *
     * @return Builder<PullRequestReviewFinding>
     */
    private function scoped(RepositoryScope $scope, ?string $authorLogin): Builder
    {
        $query = PullRequestReviewFinding::query();

        $scope->applyTo($query);

        return $query
            ->when($authorLogin, fn (Builder $q) => $q->whereHas(
                'pullRequest',
                fn (Builder $pr) => $pr->where('author_login', $authorLogin),
            ));
    }

    /**
     * Conditional-aggregate expression producing every tile in a single scan.
     *
     * Severity names are enum values, not request input, so interpolating them here
     * introduces no injection surface.
     */
    private function totalsExpression(): string
    {
        $severityCounts = array_map(
            static fn (FindingSeverity $severity): string => sprintf(
                "SUM(CASE WHEN severity = '%s' THEN 1 ELSE 0 END) as %s",
                $severity->value,
                $severity->value,
            ),
            FindingSeverity::cases(),
        );

        return implode(', ', [
            'COUNT(*) as total',
            ...$severityCounts,
            'SUM(CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END) as resolved',
            'SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) as open_count',
        ]);
    }
}
