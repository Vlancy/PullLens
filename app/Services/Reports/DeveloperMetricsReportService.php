<?php

namespace App\Services\Reports;

use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\ReviewVerdict;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestCommit;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestReviewFinding;
use App\Support\Database\Table;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\SeniorityScoreCalculator;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-developer productivity and quality metrics for the developers report.
 *
 * Attribution rules, which the queries below implement deliberately:
 *
 *  - Volume metrics (PRs, commits, lines) are attributed per author-per-PR, so a PR
 *    with three committers contributes to all three developers with each one's own
 *    commit count.
 *  - PR-level metrics that cannot be split (findings, estimated effort) go to the
 *    PR's *primary* author - the developer who added the most lines - otherwise a
 *    one-line drive-by commit would inherit the whole PR's findings.
 *  - Review outcomes (verdict, time-to-review) apply to everyone who committed,
 *    because the review covers the whole PR.
 */
class DeveloperMetricsReportService
{
    /**
     * Inject the seniority score calculator this class delegates to.
     */
    public function __construct(private readonly SeniorityScoreCalculator $scores) {}

    /**
     * Execute the developer metrics report service job.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(ReportPeriod $period, ?string $repositoryId = null): array
    {
        $since = $period->startsAt();

        $authors = $this->authorTotals($since, $repositoryId);

        if ($authors->isEmpty()) {
            return [];
        }

        $reviewStats = $this->reviewStatsByAuthor($since, $repositoryId);
        $effort = $this->averageEffortByAuthor($since, $repositoryId);
        $findings = $this->findingBreakdownByAuthor($since, $repositoryId);

        $rows = $authors
            ->map(fn (object $author, string $login): array => $this->composeRow(
                $login,
                $author,
                $reviewStats->get($login),
                $effort->get($login),
                $findings[$login] ?? [],
            ))
            ->values()
            ->all();

        usort($rows, static fn (array $a, array $b): int => $b['total_prs'] <=> $a['total_prs']);

        return $rows;
    }

    /**
     * Assemble one developer's row from the four aggregate result sets.
     *
     * @param  array<string, array<string, int>>  $findings  Severity breakdown: all, false positives, resolved.
     * @return array<string, mixed>
     */
    private function composeRow(
        string $login,
        object $author,
        ?object $reviewStats,
        ?object $effort,
        array $findings,
    ): array {
        $all = $findings['all'] ?? [];
        $falsePositives = $findings['false_positives'] ?? [];
        $resolvedReal = (int) ($findings['resolved_real'] ?? 0);

        // "Real" findings exclude those the developer successfully disputed, so a
        // noisy reviewer does not drag their score down.
        $real = [];
        foreach (FindingSeverity::values() as $severity) {
            $real[$severity] = max(0, ($all[$severity] ?? 0) - ($falsePositives[$severity] ?? 0));
        }

        $reviewCount = (int) ($reviewStats->review_count ?? 0);
        $requestChanges = (int) ($reviewStats->request_changes_count ?? 0);

        $assessment = $this->scores->assess($real, $resolvedReal, $reviewCount, $requestChanges);

        // Informational findings are excluded from the headline count: they are advice,
        // not defects, and inflating the total would distort the comparison between developers.
        $scoredSeverities = [
            FindingSeverity::Critical->value,
            FindingSeverity::High->value,
            FindingSeverity::Medium->value,
            FindingSeverity::Low->value,
        ];

        $totalFindings = array_sum(array_map(
            static fn (string $severity): int => $all[$severity] ?? 0,
            $scoredSeverities,
        ));

        return [
            'author_login' => $login,
            'author_name' => $author->author_name,
            'author_avatar_url' => $author->author_avatar_url,
            'total_prs' => (int) $author->total_prs,
            'merged_prs' => (int) $author->merged_prs,
            'total_additions' => (int) $author->total_additions,
            'total_deletions' => (int) $author->total_deletions,
            'total_commits' => (int) $author->total_commits,
            'avg_merge_hours' => $this->round($author->avg_merge_hours),
            'findings_by_severity' => [
                'critical' => $all[FindingSeverity::Critical->value] ?? 0,
                'high' => $all[FindingSeverity::High->value] ?? 0,
                'medium' => $all[FindingSeverity::Medium->value] ?? 0,
                'low' => $all[FindingSeverity::Low->value] ?? 0,
                'false_positive' => array_sum($falsePositives),
            ],
            'total_findings' => $totalFindings,
            'resolved_findings_count' => $resolvedReal,
            'resolution_rate' => $assessment->resolutionRate,
            'seniority_score' => $assessment->score,
            'seniority_level' => $assessment->level,
            'request_changes_count' => $requestChanges,
            'avg_time_to_first_review_hours' => $this->round($reviewStats?->avg_time_to_first_review_hours),
            'avg_estimated_hours' => $this->round($effort?->avg_estimated_hours),
        ];
    }

    /**
     * Volume metrics per author, aggregated from a per-(author, PR) subquery.
     *
     * The subquery is what makes multi-author PRs correct: each developer gets their
     * own commit count within the PR while sharing its line totals.
     *
     * @return Collection<string, object>
     */
    private function authorTotals(?CarbonInterface $since, ?string $repositoryId): Collection
    {
        $authorPrPairs = PullRequestCommit::query()
            ->from(Table::as(PullRequestCommit::class, 'c'))
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'c.pull_request_id')
            ->whereNotNull('c.author_login')
            ->when($since, fn (Builder $q) => $q->where('pr.opened_at', '>=', $since))
            ->when($repositoryId, fn (Builder $q) => $q->where('pr.git_repository_id', $repositoryId))
            ->select([
                'c.author_login',
                DB::raw('MAX(c.author_name) as author_name'),
                DB::raw('MAX(c.author_avatar_url) as author_avatar_url'),
                'pr.id as pr_id',
                DB::raw('COUNT(c.id) as author_commits_in_pr'),
                DB::raw('SUM(c.additions) as additions'),
                DB::raw('SUM(c.deletions) as deletions'),
                DB::raw('MAX(pr.merged_at) as merged_at'),
                DB::raw('MAX(pr.opened_at) as opened_at'),
            ])
            ->groupBy('c.author_login', 'pr.id');

        return DB::query()
            ->fromSub($authorPrPairs->getQuery(), 'author_pr_pairs')
            ->select([
                'author_login',
                DB::raw('MAX(author_name) as author_name'),
                DB::raw('MAX(author_avatar_url) as author_avatar_url'),
                DB::raw('COUNT(*) as total_prs'),
                DB::raw('SUM(CASE WHEN merged_at IS NOT NULL THEN 1 ELSE 0 END) as merged_prs'),
                DB::raw('SUM(additions) as total_additions'),
                DB::raw('SUM(deletions) as total_deletions'),
                DB::raw('SUM(author_commits_in_pr) as total_commits'),
                DB::raw('AVG(CASE WHEN merged_at IS NOT NULL AND opened_at IS NOT NULL THEN EXTRACT(EPOCH FROM (merged_at - opened_at)) / 3600 END) as avg_merge_hours'),
            ])
            ->groupBy('author_login')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get()
            ->keyBy('author_login');
    }

    /**
     * Review outcomes per author across every PR they committed to.
     *
     * Distinct (author, review) pairs prevent a developer's many commits on one PR
     * from counting its single review many times.
     *
     * @return Collection<string, object>
     */
    private function reviewStatsByAuthor(?CarbonInterface $since, ?string $repositoryId): Collection
    {
        $pairs = PullRequestCommit::query()
            ->from(Table::as(PullRequestCommit::class, 'c'))
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'c.pull_request_id')
            ->join(Table::as(PullRequestReview::class, 'rev'), 'rev.pull_request_id', '=', 'pr.id')
            ->whereNotNull('c.author_login')
            ->when($since, fn (Builder $q) => $q->where('pr.opened_at', '>=', $since))
            ->when($repositoryId, fn (Builder $q) => $q->where('pr.git_repository_id', $repositoryId))
            ->select([
                'c.author_login',
                'rev.id as rev_id',
                'pr.id as pr_id',
                'rev.risk_level',
                'rev.verdict',
                'pr.opened_at',
                // Older reviews predate the reviewed_at column being populated.
                DB::raw('COALESCE(rev.reviewed_at, rev.created_at) as effective_reviewed_at'),
            ])
            ->distinct();

        return DB::query()
            ->fromSub($pairs->getQuery(), 'author_reviews')
            ->select([
                'author_login',
                DB::raw('COUNT(*) as review_count'),
                DB::raw("COUNT(DISTINCT CASE WHEN verdict = '".ReviewVerdict::RequestChanges->value."' THEN pr_id END) as request_changes_count"),
                DB::raw('AVG(CASE WHEN opened_at IS NOT NULL THEN EXTRACT(EPOCH FROM (effective_reviewed_at - opened_at)) / 3600 END) as avg_time_to_first_review_hours'),
            ])
            ->groupBy('author_login')
            ->get()
            ->keyBy('author_login');
    }

    /**
     * Average AI-estimated effort, attributed to each PR's primary author only.
     *
     * @return Collection<string, object>
     */
    private function averageEffortByAuthor(?CarbonInterface $since, ?string $repositoryId): Collection
    {
        return PullRequestReview::query()
            ->from(Table::as(PullRequestReview::class, 'rev'))
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'rev.pull_request_id')
            ->joinSub($this->primaryAuthors(), 'pa', 'pa.pull_request_id', '=', 'rev.pull_request_id')
            ->when($since, fn (Builder $q) => $q->where('pr.opened_at', '>=', $since))
            ->when($repositoryId, fn (Builder $q) => $q->where('pr.git_repository_id', $repositoryId))
            ->select(['pa.author_login', DB::raw('AVG(rev.estimated_hours) as avg_estimated_hours')])
            ->groupBy('pa.author_login')
            ->get()
            ->keyBy('author_login');
    }

    /**
     * Finding counts per author, split into all / false-positive / resolved-real.
     *
     * Returned as a nested array rather than a query result because three different
     * tallies are derived from one grouped result set.
     *
     * @return array<string, array<string, mixed>>
     */
    private function findingBreakdownByAuthor(?CarbonInterface $since, ?string $repositoryId): array
    {
        $distinctFindings = PullRequestReviewFinding::query()
            ->from(Table::as(PullRequestReviewFinding::class, 'f'))
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'f.pull_request_id')
            ->joinSub($this->primaryAuthors(), 'pa', 'pa.pull_request_id', '=', 'f.pull_request_id')
            ->when($since, fn (Builder $q) => $q->where('pr.opened_at', '>=', $since))
            ->when($repositoryId, fn (Builder $q) => $q->where('pr.git_repository_id', $repositoryId))
            ->select([
                'pa.author_login',
                'f.id as finding_id',
                'f.severity',
                'f.resolution_type',
                DB::raw('CASE WHEN f.resolved_at IS NOT NULL THEN 1 ELSE 0 END as is_resolved'),
            ])
            ->distinct();

        $rows = DB::query()
            ->fromSub($distinctFindings->getQuery(), 'author_findings')
            ->select(['author_login', 'severity', 'resolution_type', 'is_resolved', DB::raw('COUNT(*) as count')])
            ->groupBy('author_login', 'severity', 'resolution_type', 'is_resolved')
            ->get();

        $breakdown = [];

        foreach ($rows as $row) {
            $login = $row->author_login;
            $severity = (string) $row->severity;
            $count = (int) $row->count;

            $breakdown[$login]['all'][$severity] = ($breakdown[$login]['all'][$severity] ?? 0) + $count;

            if ($row->resolution_type === FindingResolutionType::FalsePositive->value) {
                $breakdown[$login]['false_positives'][$severity] =
                    ($breakdown[$login]['false_positives'][$severity] ?? 0) + $count;

                continue;
            }

            if ((int) $row->is_resolved === 1) {
                $breakdown[$login]['resolved_real'] = ($breakdown[$login]['resolved_real'] ?? 0) + $count;
            }
        }

        return $breakdown;
    }

    /**
     * Subquery mapping each pull request to its primary author.
     *
     * Primary author = most lines added, with commit count as the tiebreaker.
     */
    private function primaryAuthors(): QueryBuilder
    {
        $ranked = PullRequestCommit::query()
            ->from(Table::as(PullRequestCommit::class, 'c'))
            ->whereNotNull('c.author_login')
            ->select([
                'c.pull_request_id',
                'c.author_login',
                DB::raw('ROW_NUMBER() OVER (PARTITION BY c.pull_request_id ORDER BY SUM(c.additions) DESC, COUNT(c.id) DESC) as rn'),
            ])
            ->groupBy('c.pull_request_id', 'c.author_login');

        return DB::query()
            ->fromSub($ranked->getQuery(), 'ranked_authors')
            ->where('rn', 1)
            ->select(['pull_request_id', 'author_login']);
    }

    /**
     * Round a nullable numeric aggregate to one decimal place.
     */
    private function round(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 1);
    }
}
