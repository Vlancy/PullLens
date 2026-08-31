<?php

namespace App\Services\Reports;

use App\Enums\GIT\FindingSeverity;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestCommit;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\RepositoryCommit;
use App\Support\Database\Table;
use App\Support\Reports\DateSeries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the individual developer profile page renders.
 *
 * All series are gap-filled through DateSeries so the charts have a point for every
 * day or week, including the quiet ones.
 */
class DeveloperProfileReportService
{
    /** A full year of contribution squares, GitHub-style. */
    private const CALENDAR_DAYS = 364;

    /** Trailing weeks shown in the PR and finding trend charts. */
    private const TREND_WEEKS = 12;

    /** Most recent pull requests listed on the profile. */
    private const RECENT_PR_LIMIT = 15;

    /**
     * Upper bound (exclusive) of added lines for each PR size bucket. The final
     * bucket catches everything larger.
     */
    private const PR_SIZE_BUCKETS = ['xs' => 10, 'sm' => 50, 'md' => 200, 'lg' => 500];

    /**
     * Execute the developer profile report service job.
     *
     * @return array<string, mixed>
     */
    public function handle(string $login, ?string $repositoryId = null): array
    {
        $calendar = $this->contributionCalendar($login, $repositoryId);

        return [
            'calendar' => $calendar,
            'weekly_prs' => $this->weeklyPullRequests($login, $repositoryId),
            'weekly_findings' => $this->weeklyFindings($login, $repositoryId),
            'pr_sizes' => $this->pullRequestSizes($login, $repositoryId),
            'dow_pattern' => $this->dayOfWeekPattern($login, $repositoryId),
            'recent_prs' => $this->recentPullRequests($login, $repositoryId),
            'active_days' => $this->activeDays($calendar),
            'current_streak' => $this->currentStreak($calendar),
            'longest_streak' => $this->longestStreak($calendar),
        ];
    }

    /**
     * Daily commit and line counts for the trailing year.
     *
     * @return array<int, array<string, mixed>>
     */
    private function contributionCalendar(string $login, ?string $repositoryId): array
    {
        $rows = RepositoryCommit::query()
            ->where('author_login', $login)
            ->where('committed_at', '>=', now()->subDays(self::CALENDAR_DAYS - 1)->startOfDay())
            ->when($repositoryId, fn (Builder $q) => $q->where('git_repository_id', $repositoryId))
            ->select([
                DB::raw('CAST(committed_at AS DATE) as date'),
                DB::raw('COUNT(*) as commits'),
                DB::raw('SUM(additions) as additions'),
                DB::raw('SUM(deletions) as deletions'),
            ])
            ->groupBy(DB::raw('CAST(committed_at AS DATE)'))
            ->get()
            ->keyBy('date');

        return DateSeries::daily(self::CALENDAR_DAYS, $rows, static fn (?object $row, string $date): array => [
            'date' => $date,
            'commits' => (int) ($row->commits ?? 0),
            'additions' => (int) ($row->additions ?? 0),
            'deletions' => (int) ($row->deletions ?? 0),
        ]);
    }

    /**
     * PRs opened and merged per week.
     *
     * @return array<int, array<string, mixed>>
     */
    private function weeklyPullRequests(string $login, ?string $repositoryId): array
    {
        $rows = PullRequest::query()
            ->where('author_login', $login)
            ->where('opened_at', '>=', now()->startOfWeek()->subWeeks(self::TREND_WEEKS - 1))
            ->when($repositoryId, fn (Builder $q) => $q->where('git_repository_id', $repositoryId))
            ->select([
                DB::raw("DATE_TRUNC('week', opened_at)::date as week"),
                DB::raw('COUNT(*) as opened'),
                DB::raw('SUM(CASE WHEN merged_at IS NOT NULL THEN 1 ELSE 0 END) as merged'),
            ])
            ->groupBy(DB::raw("DATE_TRUNC('week', opened_at)::date"))
            ->get()
            ->keyBy('week');

        return DateSeries::weekly(self::TREND_WEEKS, $rows, static fn (?object $row, string $week): array => [
            'week' => $week,
            'opened' => (int) ($row->opened ?? 0),
            'merged' => (int) ($row->merged ?? 0),
        ]);
    }

    /**
     * Findings raised against this developer's PRs per week, with the high-risk share.
     *
     * @return array<int, array<string, mixed>>
     */
    private function weeklyFindings(string $login, ?string $repositoryId): array
    {
        $highRisk = implode("','", [FindingSeverity::Critical->value, FindingSeverity::High->value]);

        $rows = PullRequestReviewFinding::query()
            ->from(Table::as(PullRequestReviewFinding::class, 'f'))
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'f.pull_request_id')
            ->where('pr.author_login', $login)
            ->where('f.created_at', '>=', now()->startOfWeek()->subWeeks(self::TREND_WEEKS - 1))
            ->when($repositoryId, fn (Builder $q) => $q->where('f.git_repository_id', $repositoryId))
            ->select([
                DB::raw("DATE_TRUNC('week', f.created_at)::date as week"),
                DB::raw('COUNT(*) as count'),
                DB::raw("SUM(CASE WHEN f.severity IN ('{$highRisk}') THEN 1 ELSE 0 END) as high_risk"),
            ])
            ->groupBy(DB::raw("DATE_TRUNC('week', f.created_at)::date"))
            ->get()
            ->keyBy('week');

        return DateSeries::weekly(self::TREND_WEEKS, $rows, static fn (?object $row, string $week): array => [
            'week' => $week,
            'count' => (int) ($row->count ?? 0),
            'high_risk' => (int) ($row->high_risk ?? 0),
        ]);
    }

    /**
     * Distribution of this developer's PRs across size buckets, by lines added.
     *
     * @return array<string, int>
     */
    private function pullRequestSizes(string $login, ?string $repositoryId): array
    {
        $buckets = array_fill_keys([...array_keys(self::PR_SIZE_BUCKETS), 'xl'], 0);

        $rows = PullRequestCommit::query()
            ->from(Table::as(PullRequestCommit::class, 'c'))
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'c.pull_request_id')
            ->where('pr.author_login', $login)
            ->when($repositoryId, fn (Builder $q) => $q->where('pr.git_repository_id', $repositoryId))
            ->select(['pr.id', DB::raw('SUM(c.additions) as additions')])
            ->groupBy('pr.id')
            ->get();

        foreach ($rows as $row) {
            $buckets[$this->sizeBucket((int) $row->additions)]++;
        }

        return $buckets;
    }

    /**
     * The bucket a PR of the given size falls into.
     */
    private function sizeBucket(int $additions): string
    {
        foreach (self::PR_SIZE_BUCKETS as $bucket => $upperBound) {
            if ($additions < $upperBound) {
                return $bucket;
            }
        }

        return 'xl';
    }

    /**
     * Commits by day of week (0 = Sunday), over the trailing year.
     *
     * @return array<int, array<string, int>>
     */
    private function dayOfWeekPattern(string $login, ?string $repositoryId): array
    {
        $counts = RepositoryCommit::query()
            ->where('author_login', $login)
            ->where('committed_at', '>=', now()->subDays(self::CALENDAR_DAYS)->startOfDay())
            ->when($repositoryId, fn (Builder $q) => $q->where('git_repository_id', $repositoryId))
            ->select([
                DB::raw('EXTRACT(DOW FROM committed_at)::int as dow'),
                DB::raw('COUNT(*) as commits'),
            ])
            ->groupBy(DB::raw('EXTRACT(DOW FROM committed_at)::int'))
            ->pluck('commits', 'dow');

        return Collection::make(range(0, 6))
            ->map(static fn (int $day): array => ['day' => $day, 'commits' => (int) ($counts[$day] ?? 0)])
            ->values()
            ->all();
    }

    /**
     * The developer's most recent pull requests, with merge time and finding count.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentPullRequests(string $login, ?string $repositoryId): array
    {
        return PullRequest::query()
            ->from(Table::as(PullRequest::class, 'pr'))
            ->where('pr.author_login', $login)
            ->when($repositoryId, fn (Builder $q) => $q->where('pr.git_repository_id', $repositoryId))
            ->select([
                'pr.number',
                'pr.title',
                'pr.web_url',
                'pr.state',
                'pr.opened_at',
                'pr.merged_at',
                DB::raw('ROUND(EXTRACT(EPOCH FROM (pr.merged_at - pr.opened_at)) / 3600, 1) as merge_hours'),
            ])
            // Correlated count rather than a join, so a PR with many findings still
            // produces exactly one row. Written out instead of withCount(), which
            // correlates on the model's real table name and cannot see the "pr" alias.
            ->selectSub(
                PullRequestReviewFinding::query()
                    ->selectRaw('count(*)')
                    ->whereColumn(Table::of(PullRequestReviewFinding::class).'.pull_request_id', 'pr.id'),
                'findings_count',
            )
            ->orderByDesc('pr.opened_at')
            ->limit(self::RECENT_PR_LIMIT)
            ->get()
            ->map(static fn (PullRequest $pr): array => [
                'number' => $pr->number,
                'title' => $pr->title,
                'web_url' => $pr->web_url,
                'state' => $pr->state?->value,
                'opened_at' => $pr->opened_at?->toISOString(),
                'merged_at' => $pr->merged_at?->toISOString(),
                'merge_hours' => $pr->merge_hours === null ? null : (float) $pr->merge_hours,
                'findings_count' => (int) $pr->findings_count,
            ])
            ->all();
    }

    /**
     * Days in the calendar with at least one commit.
     *
     * @param  array<int, array<string, mixed>>  $calendar
     */
    private function activeDays(array $calendar): int
    {
        return count(array_filter($calendar, static fn (array $day): bool => $day['commits'] > 0));
    }

    /**
     * Consecutive committing days ending today.
     *
     * @param  array<int, array<string, mixed>>  $calendar
     */
    private function currentStreak(array $calendar): int
    {
        $streak = 0;

        foreach (array_reverse($calendar) as $day) {
            if ($day['commits'] === 0) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    /**
     * Longest run of consecutive committing days in the calendar.
     *
     * @param  array<int, array<string, mixed>>  $calendar
     */
    private function longestStreak(array $calendar): int
    {
        $longest = 0;
        $current = 0;

        foreach ($calendar as $day) {
            $current = $day['commits'] > 0 ? $current + 1 : 0;
            $longest = max($longest, $current);
        }

        return $longest;
    }
}
