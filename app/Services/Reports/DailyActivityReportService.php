<?php

namespace App\Services\Reports;

use App\Enums\GIT\FindingSeverity;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestCommit;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestReviewFinding;
use App\Support\Database\Table;
use App\Support\Reports\ReportPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Day-by-day activity across pull requests, commits, reviews and findings.
 *
 * Each dataset is grouped by date independently and then merged over the union of
 * the dates that appear - a single joined query would multiply rows across the four
 * relations and produce inflated counts.
 */
class DailyActivityReportService
{
    /** Window used when the requested period has no fixed length ("all time"). */
    private const DEFAULT_DAYS = 30;

    /**
     * Execute the daily activity report service job.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(ReportPeriod $period, ?string $authorLogin = null): array
    {
        $since = now()->subDays($period->days() ?? self::DEFAULT_DAYS)->startOfDay();

        $pullRequests = $this->pullRequestsByDate($since, $authorLogin);
        $commits = $this->commitsByDate($since, $authorLogin);
        $reviews = $this->reviewsByDate($since, $authorLogin);
        $findings = $this->findingsByDate($since, $authorLogin);

        return $this->mergeDates($pullRequests, $commits, $reviews, $findings)
            ->map(fn (string $date): array => [
                'date' => $date,
                'prs_opened' => (int) ($pullRequests[$date]->prs_opened ?? 0),
                'prs_merged' => (int) ($pullRequests[$date]->prs_merged ?? 0),
                'commits' => (int) ($commits[$date]->commits ?? 0),
                'additions' => (int) ($commits[$date]->additions ?? 0),
                'deletions' => (int) ($commits[$date]->deletions ?? 0),
                'findings' => (int) ($findings[$date]->findings ?? 0),
                'critical_findings' => (int) ($findings[$date]->critical_findings ?? 0),
                'reviews' => (int) ($reviews[$date]->reviews ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * The sorted union of dates present in any dataset.
     *
     * @return Collection<int, string>
     */
    private function mergeDates(Collection ...$datasets): Collection
    {
        return Collection::make($datasets)
            ->flatMap(static fn (Collection $set): array => $set->keys()->all())
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * Pull request counts grouped by day.
     *
     * @return Collection<string, object>
     */
    private function pullRequestsByDate(CarbonInterface $since, ?string $authorLogin): Collection
    {
        return PullRequest::query()
            ->where('opened_at', '>=', $since)
            ->when($authorLogin, fn (Builder $q) => $q->where('author_login', $authorLogin))
            ->select([
                DB::raw('CAST(opened_at AS DATE) as date'),
                DB::raw('COUNT(*) as prs_opened'),
                DB::raw('SUM(CASE WHEN merged_at IS NOT NULL THEN 1 ELSE 0 END) as prs_merged'),
            ])
            ->groupBy(DB::raw('CAST(opened_at AS DATE)'))
            ->get()
            ->keyBy('date');
    }

    /**
     * Commit counts and line totals grouped by day.
     *
     * @return Collection<string, object>
     */
    private function commitsByDate(CarbonInterface $since, ?string $authorLogin): Collection
    {
        return PullRequestCommit::query()
            ->where('committed_at', '>=', $since)
            ->when($authorLogin, fn (Builder $q) => $q->where('author_login', $authorLogin))
            ->select([
                DB::raw('CAST(committed_at AS DATE) as date'),
                DB::raw('COUNT(*) as commits'),
                DB::raw('SUM(additions) as additions'),
                DB::raw('SUM(deletions) as deletions'),
            ])
            ->groupBy(DB::raw('CAST(committed_at AS DATE)'))
            ->get()
            ->keyBy('date');
    }

    /**
     * Review counts grouped by day.
     *
     * @return Collection<string, object>
     */
    private function reviewsByDate(CarbonInterface $since, ?string $authorLogin): Collection
    {
        return PullRequestReview::query()
            ->from(Table::as(PullRequestReview::class, 'rev'))
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'rev.pull_request_id')
            ->where('rev.reviewed_at', '>=', $since)
            ->when($authorLogin, fn (Builder $q) => $q->where('pr.author_login', $authorLogin))
            ->select([
                DB::raw('CAST(rev.reviewed_at AS DATE) as date'),
                DB::raw('COUNT(rev.id) as reviews'),
            ])
            ->groupBy(DB::raw('CAST(rev.reviewed_at AS DATE)'))
            ->get()
            ->keyBy('date');
    }

    /**
     * Findings are dated by the review that produced them, so they line up with the
     * review counts on the same row.
     *
     * @return Collection<string, object>
     */
    private function findingsByDate(CarbonInterface $since, ?string $authorLogin): Collection
    {
        return PullRequestReviewFinding::query()
            ->from(Table::as(PullRequestReviewFinding::class, 'f'))
            ->join(Table::as(PullRequestReview::class, 'rev'), 'rev.id', '=', 'f.pull_request_review_id')
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'rev.pull_request_id')
            ->where('rev.reviewed_at', '>=', $since)
            ->when($authorLogin, fn (Builder $q) => $q->where('pr.author_login', $authorLogin))
            ->select([
                DB::raw('CAST(rev.reviewed_at AS DATE) as date'),
                DB::raw('COUNT(f.id) as findings'),
                DB::raw("SUM(CASE WHEN f.severity = '".FindingSeverity::Critical->value."' THEN 1 ELSE 0 END) as critical_findings"),
            ])
            ->groupBy(DB::raw('CAST(rev.reviewed_at AS DATE)'))
            ->get()
            ->keyBy('date');
    }
}
