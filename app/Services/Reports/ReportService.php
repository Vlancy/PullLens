<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Return system-wide totals across all entities.
     */
    public function overview(): array
    {
        return [
            'total_prs' => DB::table('pull_requests')->count(),
            'open_prs' => DB::table('pull_requests')->where('state', 'open')->count(),
            'merged_prs' => DB::table('pull_requests')->whereNotNull('merged_at')->count(),
            'total_reviews' => DB::table('pull_request_reviews')->count(),
            'total_findings' => DB::table('pull_request_review_findings')->count(),
            'active_repos' => DB::table('git_repositories')->where('reviews_enabled', true)->count(),
            'connected_accounts' => DB::table('git_accounts')->count(),
            'critical_findings' => DB::table('pull_request_review_findings')->where('severity', 'critical')->count(),
            'high_risk_prs' => DB::table('pull_requests as pr')
                ->join('pull_request_reviews as rev', 'rev.pull_request_id', '=', 'pr.id')
                ->whereIn('rev.risk_level', ['high', 'critical'])
                ->distinct()
                ->count('pr.id'),
            'avg_review_duration_ms' => (int) round((float) DB::table('pull_request_reviews')->where('review_duration_ms', '>', 0)->avg('review_duration_ms')),
            'resolved_findings' => DB::table('pull_request_review_findings')->whereNotNull('resolved_at')->count(),
            'request_changes_reviews' => DB::table('pull_request_reviews')->where('verdict', 'request_changes')->count(),
        ];
    }

    /**
     * Return per-author PR and finding metrics, optionally filtered by period and repository.
     */
    public function developers(string $period = 'all', ?string $repoId = null): array
    {
        $periodStart = $this->resolvePeriodStart($period);

        // Per-author-per-PR aggregation: actual commit count per developer, full PR line stats.
        // Each PR with N authors produces N rows — one per developer — so multi-author PRs
        // correctly attribute individual commit counts while sharing the PR's additions/deletions.
        $authorPrPairsQuery = DB::table('pull_request_commits as c')
            ->join('pull_requests as pr', 'pr.id', '=', 'c.pull_request_id')
            ->whereNotNull('c.author_login')
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

        if ($periodStart) {
            $authorPrPairsQuery->where('pr.opened_at', '>=', $periodStart);
        }
        if ($repoId) {
            $authorPrPairsQuery->where('pr.git_repository_id', $repoId);
        }

        $prRows = DB::query()
            ->fromSub($authorPrPairsQuery, 'author_pr_pairs')
            ->select([
                'author_login',
                DB::raw('MAX(author_name) as author_name'),
                DB::raw('MAX(author_avatar_url) as author_avatar_url'),
                DB::raw('COUNT(*) as total_prs'),
                DB::raw('SUM(CASE WHEN merged_at IS NOT NULL THEN 1 ELSE 0 END) as merged_prs'),
                DB::raw('SUM(additions) as total_additions'),
                DB::raw('SUM(deletions) as total_deletions'),
                DB::raw('SUM(author_commits_in_pr) as total_commits'),
                DB::raw('AVG(CASE WHEN merged_at IS NOT NULL AND opened_at IS NOT NULL THEN EXTRACT(EPOCH FROM (merged_at - opened_at)) / 3600 ELSE NULL END) as avg_merge_hours'),
            ])
            ->groupBy('author_login')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get()
            ->keyBy('author_login');

        // Primary author per PR: developer with the most additions (commit count as tiebreaker).
        // Used for PR-level metrics that can't be split per-author: findings and estimated effort.
        $primaryAuthorsQuery = DB::query()
            ->fromSub(
                DB::table('pull_request_commits as c')
                    ->whereNotNull('c.author_login')
                    ->select([
                        'c.pull_request_id',
                        'c.author_login',
                        DB::raw('ROW_NUMBER() OVER (PARTITION BY c.pull_request_id ORDER BY SUM(c.additions) DESC, COUNT(c.id) DESC) as rn'),
                    ])
                    ->groupBy('c.pull_request_id', 'c.author_login'),
                'ranked_authors'
            )
            ->where('rn', 1)
            ->select(['pull_request_id', 'author_login']);

        // Distinct (author, review) pairs — risk/verdict/time go to all committers of a PR.
        // estimated_hours is excluded here; it's handled via primary-author attribution below.
        $distinctReviewQuery = DB::table('pull_request_commits as c')
            ->join('pull_requests as pr', 'pr.id', '=', 'c.pull_request_id')
            ->join('pull_request_reviews as rev', 'rev.pull_request_id', '=', 'pr.id')
            ->whereNotNull('c.author_login')
            ->select([
                'c.author_login',
                'rev.id as rev_id',
                'pr.id as pr_id',
                'rev.risk_level',
                'rev.verdict',
                'pr.opened_at',
                DB::raw('COALESCE(rev.reviewed_at, rev.created_at) as effective_reviewed_at'),
            ])
            ->distinct();

        if ($periodStart) {
            $distinctReviewQuery->where('pr.opened_at', '>=', $periodStart);
        }
        if ($repoId) {
            $distinctReviewQuery->where('pr.git_repository_id', $repoId);
        }

        $reviewStatsByAuthor = DB::query()
            ->fromSub($distinctReviewQuery, 'dr')
            ->select([
                'author_login',
                DB::raw('COUNT(*) as review_count'),
                DB::raw('COUNT(DISTINCT CASE WHEN risk_level IN (\'high\',\'critical\') THEN pr_id END) as high_risk_prs'),
                DB::raw('COUNT(DISTINCT CASE WHEN verdict = \'request_changes\' THEN pr_id END) as request_changes_count'),
                DB::raw('AVG(CASE WHEN opened_at IS NOT NULL THEN EXTRACT(EPOCH FROM (effective_reviewed_at - opened_at)) / 3600 END) as avg_time_to_first_review_hours'),
            ])
            ->groupBy('author_login')
            ->get()
            ->keyBy('author_login');

        // avg_estimated_hours attributed to the primary author of each PR only.
        $avgEffortQuery = DB::table('pull_request_reviews as rev')
            ->join('pull_requests as pr', 'pr.id', '=', 'rev.pull_request_id')
            ->joinSub($primaryAuthorsQuery, 'pa', 'pa.pull_request_id', '=', 'rev.pull_request_id')
            ->select([
                'pa.author_login',
                DB::raw('AVG(rev.estimated_hours) as avg_estimated_hours'),
            ])
            ->groupBy('pa.author_login');

        if ($periodStart) {
            $avgEffortQuery->where('pr.opened_at', '>=', $periodStart);
        }
        if ($repoId) {
            $avgEffortQuery->where('pr.git_repository_id', $repoId);
        }

        $avgEstimatedHoursByAuthor = $avgEffortQuery->get()->keyBy('author_login');

        // Findings attributed to the primary author of each PR only.
        $distinctFindingQuery = DB::table('pull_request_review_findings as f')
            ->join('pull_requests as pr', 'pr.id', '=', 'f.pull_request_id')
            ->joinSub($primaryAuthorsQuery, 'pa', 'pa.pull_request_id', '=', 'f.pull_request_id')
            ->select([
                'pa.author_login',
                'f.id as finding_id',
                'f.severity',
                'f.resolution_type',
            ])
            ->distinct();

        if ($periodStart) {
            $distinctFindingQuery->where('pr.opened_at', '>=', $periodStart);
        }
        if ($repoId) {
            $distinctFindingQuery->where('pr.git_repository_id', $repoId);
        }

        $findings = DB::query()
            ->fromSub($distinctFindingQuery, 'df')
            ->select([
                'author_login',
                'severity',
                'resolution_type',
                DB::raw('COUNT(*) as count'),
            ])
            ->groupBy('author_login', 'severity', 'resolution_type')
            ->get();

        // Group findings by author — track all findings and false positives separately.
        // false_positive findings are shown historically but excluded from the seniority score.
        $findingsByAuthor = [];
        $falsePositivesByAuthor = [];
        foreach ($findings as $row) {
            $login = $row->author_login;
            $sev = $row->severity;
            $count = (int) $row->count;
            $findingsByAuthor[$login][$sev] = ($findingsByAuthor[$login][$sev] ?? 0) + $count;
            if ($row->resolution_type === 'false_positive') {
                $falsePositivesByAuthor[$login][$sev] = ($falsePositivesByAuthor[$login][$sev] ?? 0) + $count;
            }
        }

        $result = [];
        foreach ($prRows as $login => $pr) {
            $severities = $findingsByAuthor[$login] ?? [];
            $fpSeverities = $falsePositivesByAuthor[$login] ?? [];
            $critical = $severities['critical'] ?? 0;
            $high = $severities['high'] ?? 0;
            $medium = $severities['medium'] ?? 0;
            $low = $severities['low'] ?? 0;
            $falsePositive = array_sum($fpSeverities);
            $totalFindings = $critical + $high + $medium + $low;
            $totalPrs = max((int) $pr->total_prs, 1);

            // Exclude false positives from score — they appear in history but don't penalise the developer.
            $scoreCritical = max(0, $critical - ($fpSeverities['critical'] ?? 0));
            $scoreHigh = max(0, $high - ($fpSeverities['high'] ?? 0));
            $scoreMedium = max(0, $medium - ($fpSeverities['medium'] ?? 0));
            $scoreLow = max(0, $low - ($fpSeverities['low'] ?? 0));
            $seniorityScore = max(0, min(100, 100 - ($scoreCritical * 15 + $scoreHigh * 8 + $scoreMedium * 3 + $scoreLow * 1) / $totalPrs));

            $seniorityLevel = match (true) {
                $seniorityScore >= 80 => 'Expert',
                $seniorityScore >= 60 => 'Senior',
                $seniorityScore >= 35 => 'Mid',
                default => 'Junior',
            };

            $reviewStats = $reviewStatsByAuthor[$login] ?? null;
            // Require at least 3 AI-reviewed PRs before assigning a seniority level.
            // With fewer reviews the score is statistically meaningless (defaults to 100 = Lead).
            $hasEnoughData = $reviewStats !== null && (int) $reviewStats->review_count >= 3;

            $result[] = [
                'author_login' => $login,
                'author_name' => $pr->author_name,
                'author_avatar_url' => $pr->author_avatar_url,
                'total_prs' => (int) $pr->total_prs,
                'merged_prs' => (int) $pr->merged_prs,
                'total_additions' => (int) $pr->total_additions,
                'total_deletions' => (int) $pr->total_deletions,
                'total_commits' => (int) $pr->total_commits,
                'avg_merge_hours' => $pr->avg_merge_hours !== null ? round((float) $pr->avg_merge_hours, 1) : null,
                'findings_by_severity' => [
                    'critical' => $critical,
                    'high' => $high,
                    'medium' => $medium,
                    'low' => $low,
                    'false_positive' => $falsePositive,
                ],
                'total_findings' => $totalFindings,
                'seniority_score' => $hasEnoughData ? round($seniorityScore, 1) : null,
                'seniority_level' => $hasEnoughData ? $seniorityLevel : null,
                'high_risk_prs' => $reviewStats ? (int) $reviewStats->high_risk_prs : 0,
                'request_changes_count' => $reviewStats ? (int) $reviewStats->request_changes_count : 0,
                'avg_time_to_first_review_hours' => $reviewStats && $reviewStats->avg_time_to_first_review_hours !== null
                    ? round((float) $reviewStats->avg_time_to_first_review_hours, 1)
                    : null,
                'avg_estimated_hours' => isset($avgEstimatedHoursByAuthor[$login]) && $avgEstimatedHoursByAuthor[$login]->avg_estimated_hours !== null
                    ? round((float) $avgEstimatedHoursByAuthor[$login]->avg_estimated_hours, 1)
                    : null,
            ];
        }

        usort($result, fn ($a, $b) => $b['total_prs'] <=> $a['total_prs']);

        return $result;
    }

    /**
     * Return per-repository PR and finding statistics for all review-enabled repos.
     */
    public function repositories(): array
    {
        $repos = DB::table('git_repositories as gr')
            ->leftJoin('pull_requests as pr', 'pr.git_repository_id', '=', 'gr.id')
            ->leftJoin('pull_request_review_findings as f', 'f.git_repository_id', '=', 'gr.id')
            ->select([
                'gr.id',
                'gr.name',
                'gr.full_name',
                'gr.owner_login',
                'gr.web_url',
                DB::raw('COUNT(DISTINCT pr.id) as total_prs'),
                DB::raw('SUM(CASE WHEN pr.merged_at IS NOT NULL THEN 1 ELSE 0 END) as merged_prs'),
                DB::raw('SUM(CASE WHEN pr.state = \'open\' THEN 1 ELSE 0 END) as open_prs'),
                DB::raw('COUNT(DISTINCT CASE WHEN f.resolved_at IS NULL THEN f.id END) as total_findings'),
                DB::raw('MAX(pr.opened_at) as last_pr_at'),
            ])
            ->where('gr.reviews_enabled', 1)
            ->groupBy('gr.id', 'gr.name', 'gr.full_name', 'gr.owner_login', 'gr.web_url')
            ->orderByDesc('total_prs')
            ->get();

        $topCategories = DB::table('pull_request_review_findings')
            ->select(['git_repository_id', 'category', DB::raw('COUNT(*) as cnt')])
            ->groupBy('git_repository_id', 'category')
            ->orderByDesc('cnt')
            ->get();

        // Pick the top category per repo (first one wins due to ORDER BY cnt DESC)
        $topCategoryByRepo = [];
        foreach ($topCategories as $row) {
            if (! isset($topCategoryByRepo[$row->git_repository_id])) {
                $topCategoryByRepo[$row->git_repository_id] = $row->category;
            }
        }

        $repoReviewStats = DB::table('pull_requests as pr')
            ->join('pull_request_reviews as rev', 'rev.pull_request_id', '=', 'pr.id')
            ->select([
                'pr.git_repository_id',
                DB::raw('COUNT(DISTINCT CASE WHEN rev.risk_level IN (\'high\',\'critical\') THEN pr.id END) as high_risk_prs'),
                DB::raw('COUNT(rev.id) as total_reviews'),
                DB::raw('SUM(CASE WHEN rev.verdict = \'approve\' THEN 1 ELSE 0 END) as approve_count'),
            ])
            ->groupBy('pr.git_repository_id')
            ->get()
            ->keyBy('git_repository_id');

        $resolvedFindingsByRepo = DB::table('pull_request_review_findings')
            ->select(['git_repository_id', DB::raw('COUNT(*) as resolved_count')])
            ->whereNotNull('resolved_at')
            ->groupBy('git_repository_id')
            ->get()
            ->keyBy('git_repository_id');

        return $repos->map(function ($repo) use ($topCategoryByRepo, $repoReviewStats, $resolvedFindingsByRepo) {
            $reviewStats = $repoReviewStats[$repo->id] ?? null;
            $totalReviews = $reviewStats ? (int) $reviewStats->total_reviews : 0;
            $approveCount = $reviewStats ? (int) $reviewStats->approve_count : 0;
            $approveRate = $totalReviews > 0 ? round(($approveCount / $totalReviews) * 100, 1) : 0.0;

            return [
                'id' => $repo->id,
                'name' => $repo->name,
                'full_name' => $repo->full_name,
                'owner_login' => $repo->owner_login,
                'web_url' => $repo->web_url,
                'total_prs' => (int) $repo->total_prs,
                'merged_prs' => (int) $repo->merged_prs,
                'open_prs' => (int) $repo->open_prs,
                'total_findings' => (int) $repo->total_findings,
                'top_category' => $topCategoryByRepo[$repo->id] ?? null,
                'last_pr_at' => $repo->last_pr_at,
                'high_risk_prs' => $reviewStats ? (int) $reviewStats->high_risk_prs : 0,
                'total_reviews' => $totalReviews,
                'approve_rate' => $approveRate,
                'resolved_findings' => isset($resolvedFindingsByRepo[$repo->id]) ? (int) $resolvedFindingsByRepo[$repo->id]->resolved_count : 0,
            ];
        })->values()->all();
    }

    /**
     * Return commit quality metrics per author, sorted by low-effort commit percentage descending.
     */
    public function commits(string $period = 'all'): array
    {
        $periodStart = $this->resolvePeriodStart($period);

        $query = DB::table('pull_request_commits')
            ->select([
                'author_login',
                DB::raw('MAX(author_name) as author_name'),
                DB::raw('MAX(author_avatar_url) as author_avatar_url'),
                DB::raw('COUNT(*) as total_commits'),
            ])
            ->groupBy('author_login');

        if ($periodStart) {
            $query->where('committed_at', '>=', $periodStart);
        }

        $rows = $query->get();

        $prStatsQuery = DB::table('pull_request_commits')
            ->select([
                'author_login',
                DB::raw('SUM(additions) as total_additions'),
                DB::raw('SUM(deletions) as total_deletions'),
            ])
            ->groupBy('author_login');

        if ($periodStart) {
            $prStatsQuery->where('committed_at', '>=', $periodStart);
        }

        $prStatsByAuthor = $prStatsQuery->get()->keyBy('author_login');

        // Fetch messages per author separately to avoid GROUP_CONCAT issues
        $messageQuery = DB::table('pull_request_commits')
            ->select(['author_login', 'message']);

        if ($periodStart) {
            $messageQuery->where('committed_at', '>=', $periodStart);
        }

        $messageRows = $messageQuery->get();

        $messagesByAuthor = [];
        foreach ($messageRows as $row) {
            $messagesByAuthor[$row->author_login][] = $row->message;
        }

        $result = [];
        foreach ($rows as $row) {
            $messages = $messagesByAuthor[$row->author_login] ?? [];
            $lowEffortMessages = array_filter($messages, fn ($msg) => $this->isLowEffortCommit($msg));
            $lowEffortMessages = array_values($lowEffortMessages);

            $totalCommits = (int) $row->total_commits;
            $lowEffortCount = count($lowEffortMessages);
            $lowEffortPct = $totalCommits > 0 ? round(($lowEffortCount / $totalCommits) * 100, 1) : 0;

            $prStats = $prStatsByAuthor[$row->author_login] ?? null;

            $result[] = [
                'author_login' => $row->author_login,
                'author_name' => $row->author_name,
                'author_avatar_url' => $row->author_avatar_url,
                'total_commits' => $totalCommits,
                'low_effort_count' => $lowEffortCount,
                'low_effort_pct' => $lowEffortPct,
                'low_effort_messages' => array_slice($lowEffortMessages, 0, 5),
                'total_additions' => $prStats ? (int) $prStats->total_additions : 0,
                'total_deletions' => $prStats ? (int) $prStats->total_deletions : 0,
            ];
        }

        usort($result, fn ($a, $b) => $b['low_effort_pct'] <=> $a['low_effort_pct']);

        return $result;
    }

    /** Return per-developer daily effort breakdown, one row per (author, date). */
    public function developerDaily(string $period = '7d', ?string $repoId = null): array
    {
        $periodStart = match ($period) {
            'today' => Carbon::now()->startOfDay(),
            '30d' => Carbon::now()->subDays(30)->startOfDay(),
            default => Carbon::now()->subDays(7)->startOfDay(),
        };

        $commitQuery = DB::table('pull_request_commits as c')
            ->select([
                'c.author_login',
                DB::raw('MAX(c.author_name) as author_name'),
                DB::raw('MAX(c.author_avatar_url) as author_avatar_url'),
                DB::raw('CAST(c.committed_at AS DATE) as date'),
                DB::raw('COUNT(*) as total_commits'),
                DB::raw('SUM(c.additions) as additions'),
                DB::raw('SUM(c.deletions) as deletions'),
                DB::raw('MIN(c.committed_at) as first_commit_at'),
                DB::raw('MAX(c.committed_at) as last_commit_at'),
                DB::raw('EXTRACT(EPOCH FROM (MAX(c.committed_at) - MIN(c.committed_at))) / 3600 as active_hours'),
                DB::raw("SUM(CASE WHEN LENGTH(TRIM(c.message)) <= 4
                    OR LOWER(TRIM(c.message)) ~* '^(wip|fix|test|temp|dev|tmp|ok|patch|update|changes|misc|asdf|asd|pr|bump|commit|merge|done|work|initial|init|lol|heh|yo|hey|minor|hotfix|quickfix|revert|reverted)$'
                    THEN 1 ELSE 0 END) as low_effort_commits"),
            ])
            ->where('c.committed_at', '>=', $periodStart)
            ->whereNotNull('c.author_login')
            ->groupBy('c.author_login', DB::raw('CAST(c.committed_at AS DATE)'))
            ->orderByDesc(DB::raw('CAST(c.committed_at AS DATE)'))
            ->orderBy('c.author_login');

        if ($repoId) {
            $commitQuery->join('pull_requests as pr', 'pr.id', '=', 'c.pull_request_id')
                ->where('pr.git_repository_id', $repoId);
        }

        $commitRows = $commitQuery->get();

        $prQuery = DB::table('pull_requests')
            ->select([
                'author_login',
                DB::raw('CAST(opened_at AS DATE) as date'),
                DB::raw('COUNT(*) as prs_opened'),
            ])
            ->where('opened_at', '>=', $periodStart)
            ->groupBy('author_login', DB::raw('CAST(opened_at AS DATE)'));

        if ($repoId) {
            $prQuery->where('git_repository_id', $repoId);
        }

        $prMap = [];
        foreach ($prQuery->get() as $pr) {
            $prMap["{$pr->author_login}|{$pr->date}"] = [
                'prs_opened' => (int) $pr->prs_opened,
            ];
        }

        $result = [];
        foreach ($commitRows as $row) {
            $key = "{$row->author_login}|{$row->date}";
            $totalCommits = (int) $row->total_commits;
            $lowEffort = (int) $row->low_effort_commits;
            $usefulCommits = $totalCommits - $lowEffort;
            $activeHours = round((float) $row->active_hours, 1);

            $prData = $prMap[$key] ?? null;

            $result[] = [
                'date' => (string) $row->date,
                'author_login' => $row->author_login,
                'author_name' => $row->author_name,
                'author_avatar_url' => $row->author_avatar_url,
                'total_commits' => $totalCommits,
                'low_effort_commits' => $lowEffort,
                'useful_commits' => $usefulCommits,
                'additions' => (int) $row->additions,
                'deletions' => (int) $row->deletions,
                'active_hours' => $activeHours,
                'prs_opened' => $prData['prs_opened'] ?? 0,
                'is_productive' => $usefulCommits > 0,
            ];
        }

        return $result;
    }

    /**
     * Return daily activity breakdown for a given period (7d, 30d, or 90d).
     */
    public function daily(string $period = '30d'): array
    {
        $days = match ($period) {
            '7d' => 7,
            '90d' => 90,
            default => 30,
        };

        $periodStart = Carbon::now()->subDays($days)->startOfDay();

        $prRows = DB::table('pull_requests')
            ->select([
                DB::raw('CAST(opened_at AS DATE) as date'),
                DB::raw('COUNT(*) as prs_opened'),
                DB::raw('SUM(CASE WHEN merged_at IS NOT NULL THEN 1 ELSE 0 END) as prs_merged'),
            ])
            ->where('opened_at', '>=', $periodStart)
            ->groupBy(DB::raw('CAST(opened_at AS DATE)'))
            ->get()
            ->keyBy('date');

        $commitRows = DB::table('pull_request_commits')
            ->select([
                DB::raw('CAST(committed_at AS DATE) as date'),
                DB::raw('COUNT(*) as commits'),
                DB::raw('SUM(additions) as additions'),
                DB::raw('SUM(deletions) as deletions'),
            ])
            ->where('committed_at', '>=', $periodStart)
            ->groupBy(DB::raw('CAST(committed_at AS DATE)'))
            ->get()
            ->keyBy('date');

        $reviewRows = DB::table('pull_request_reviews')
            ->select([
                DB::raw('CAST(reviewed_at AS DATE) as date'),
                DB::raw('COUNT(*) as reviews'),
            ])
            ->where('reviewed_at', '>=', $periodStart)
            ->groupBy(DB::raw('CAST(reviewed_at AS DATE)'))
            ->get()
            ->keyBy('date');

        $findingRows = DB::table('pull_request_review_findings as f')
            ->join('pull_request_reviews as rev', 'rev.id', '=', 'f.pull_request_review_id')
            ->select([
                DB::raw('CAST(rev.reviewed_at AS DATE) as date'),
                DB::raw('COUNT(f.id) as findings'),
                DB::raw('SUM(CASE WHEN f.severity = \'critical\' THEN 1 ELSE 0 END) as critical_findings'),
            ])
            ->where('rev.reviewed_at', '>=', $periodStart)
            ->groupBy(DB::raw('CAST(rev.reviewed_at AS DATE)'))
            ->get()
            ->keyBy('date');

        // Union of all dates that appear in any dataset
        $allDates = collect()
            ->merge($prRows->keys())
            ->merge($commitRows->keys())
            ->merge($reviewRows->keys())
            ->merge($findingRows->keys())
            ->unique()
            ->sort()
            ->values();

        return $allDates->map(function (string $date) use ($prRows, $commitRows, $reviewRows, $findingRows) {
            $pr = $prRows[$date] ?? null;
            $commit = $commitRows[$date] ?? null;
            $review = $reviewRows[$date] ?? null;
            $finding = $findingRows[$date] ?? null;

            return [
                'date' => $date,
                'prs_opened' => $pr ? (int) $pr->prs_opened : 0,
                'prs_merged' => $pr ? (int) $pr->prs_merged : 0,
                'commits' => $commit ? (int) $commit->commits : 0,
                'additions' => $commit ? (int) $commit->additions : 0,
                'deletions' => $commit ? (int) $commit->deletions : 0,
                'findings' => $finding ? (int) $finding->findings : 0,
                'critical_findings' => $finding ? (int) $finding->critical_findings : 0,
                'reviews' => $review ? (int) $review->reviews : 0,
            ];
        })->values()->all();
    }

    /**
     * Resolve a period string to a Carbon start date, or null for 'all'.
     */
    private function resolvePeriodStart(string $period): ?Carbon
    {
        return match ($period) {
            'today' => Carbon::now()->startOfDay(),
            '7d' => Carbon::now()->subDays(7)->startOfDay(),
            '30d' => Carbon::now()->subDays(30)->startOfDay(),
            '90d' => Carbon::now()->subDays(90)->startOfDay(),
            default => null,
        };
    }

    /**
     * Determine whether a commit message is low-effort.
     */
    private function isLowEffortCommit(string $msg): bool
    {
        $msg = trim($msg);

        if (strlen($msg) <= 4) {
            return true;
        }

        $pattern = '/^(wip|fix|test|temp|dev|tmp|ok|patch|update|changes|misc|asdf|asd|pr|bump|commit|merge|done|work|initial|init|lol|heh|yo|hey|test commit|minor|hotfix|quickfix|quick fix|revert|reverted)$/i';

        return (bool) preg_match($pattern, $msg);
    }
}
