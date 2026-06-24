<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Return system-wide totals across all entities, optionally scoped to a time period.
     */
    public function overview(string $period = 'today', ?string $authorLogin = null): array
    {
        $p = $this->resolvePeriodStart($period);

        return [
            'total_prs' => DB::table('pull_requests')
                ->when($p, fn ($q) => $q->where('opened_at', '>=', $p))
                ->when($authorLogin, fn ($q) => $q->where('author_login', $authorLogin))
                ->count(),
            'open_prs' => DB::table('pull_requests')
                ->where('state', 'open')
                ->when($p, fn ($q) => $q->where('opened_at', '>=', $p))
                ->when($authorLogin, fn ($q) => $q->where('author_login', $authorLogin))
                ->count(),
            'merged_prs' => DB::table('pull_requests')
                ->whereNotNull('merged_at')
                ->when($p, fn ($q) => $q->where('merged_at', '>=', $p))
                ->when($authorLogin, fn ($q) => $q->where('author_login', $authorLogin))
                ->count(),
            'total_reviews' => DB::table('pull_request_reviews as rev')
                ->join('pull_requests as pr', 'pr.id', '=', 'rev.pull_request_id')
                ->when($p, fn ($q) => $q->where('rev.created_at', '>=', $p))
                ->when($authorLogin, fn ($q) => $q->where('pr.author_login', $authorLogin))
                ->count('rev.id'),
            'total_findings' => DB::table('pull_request_review_findings as f')
                ->join('pull_request_reviews as rev', 'rev.id', '=', 'f.pull_request_review_id')
                ->join('pull_requests as pr', 'pr.id', '=', 'rev.pull_request_id')
                ->when($p, fn ($q) => $q->where('f.created_at', '>=', $p))
                ->when($authorLogin, fn ($q) => $q->where('pr.author_login', $authorLogin))
                ->count('f.id'),
            'active_repos' => DB::table('git_repositories')->where('reviews_enabled', true)->count(),
            'connected_accounts' => DB::table('git_accounts')->count(),
            'critical_findings' => DB::table('pull_request_review_findings as f')
                ->join('pull_request_reviews as rev', 'rev.id', '=', 'f.pull_request_review_id')
                ->join('pull_requests as pr', 'pr.id', '=', 'rev.pull_request_id')
                ->where('f.severity', 'critical')
                ->when($p, fn ($q) => $q->where('f.created_at', '>=', $p))
                ->when($authorLogin, fn ($q) => $q->where('pr.author_login', $authorLogin))
                ->count('f.id'),
            'high_risk_prs' => DB::table('pull_request_review_findings as f')
                ->join('pull_requests as pr', 'pr.id', '=', 'f.pull_request_id')
                ->whereIn('f.severity', ['high', 'critical'])
                ->whereNull('f.resolved_at')
                ->when($p, fn ($q) => $q->where('f.created_at', '>=', $p))
                ->when($authorLogin, fn ($q) => $q->where('pr.author_login', $authorLogin))
                ->distinct()
                ->count('f.pull_request_id'),
            'avg_review_duration_ms' => (int) round((float) DB::table('pull_request_reviews as rev')
                ->join('pull_requests as pr', 'pr.id', '=', 'rev.pull_request_id')
                ->where('rev.review_duration_ms', '>', 0)
                ->when($p, fn ($q) => $q->where('rev.created_at', '>=', $p))
                ->when($authorLogin, fn ($q) => $q->where('pr.author_login', $authorLogin))
                ->avg('rev.review_duration_ms')),
            'resolved_findings' => DB::table('pull_request_review_findings as f')
                ->join('pull_request_reviews as rev', 'rev.id', '=', 'f.pull_request_review_id')
                ->join('pull_requests as pr', 'pr.id', '=', 'rev.pull_request_id')
                ->whereNotNull('f.resolved_at')
                ->when($p, fn ($q) => $q->where('f.resolved_at', '>=', $p))
                ->when($authorLogin, fn ($q) => $q->where('pr.author_login', $authorLogin))
                ->count('f.id'),
            'request_changes_reviews' => DB::table('pull_request_reviews as rev')
                ->join('pull_requests as pr', 'pr.id', '=', 'rev.pull_request_id')
                ->where('rev.verdict', 'request_changes')
                ->when($p, fn ($q) => $q->where('rev.created_at', '>=', $p))
                ->when($authorLogin, fn ($q) => $q->where('pr.author_login', $authorLogin))
                ->count('rev.id'),
        ];
    }

    /**
     * Return a ranked developer leaderboard for the given period.
     */
    public function leaderboard(string $period = 'today'): array
    {
        $p = $this->resolvePeriodStart($period);

        $prRows = DB::table('pull_requests')
            ->whereNotNull('author_login')
            ->when($p, fn ($q) => $q->where('opened_at', '>=', $p))
            ->select([
                'author_login',
                DB::raw('MAX(author_name) as author_name'),
                DB::raw('MAX(author_avatar_url) as author_avatar_url'),
                DB::raw('COUNT(*) as total_prs'),
                DB::raw('COUNT(CASE WHEN merged_at IS NOT NULL THEN 1 END) as merged_prs'),
            ])
            ->groupBy('author_login')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get()
            ->keyBy('author_login');

        if ($prRows->isEmpty()) {
            return [];
        }

        $logins = $prRows->keys()->toArray();

        $commitRows = DB::table('pull_request_commits as c')
            ->join('pull_requests as pr', 'pr.id', '=', 'c.pull_request_id')
            ->whereNotNull('c.author_login')
            ->whereIn('c.author_login', $logins)
            ->when($p, fn ($q) => $q->where('pr.opened_at', '>=', $p))
            ->select(['c.author_login', DB::raw('COUNT(c.id) as commits')])
            ->groupBy('c.author_login')
            ->get()
            ->keyBy('author_login');

        $findingRows = DB::table('pull_request_review_findings as f')
            ->join('pull_request_reviews as rev', 'rev.id', '=', 'f.pull_request_review_id')
            ->join('pull_requests as pr', 'pr.id', '=', 'rev.pull_request_id')
            ->whereIn('pr.author_login', $logins)
            ->when($p, fn ($q) => $q->where('f.created_at', '>=', $p))
            ->select(['pr.author_login', DB::raw('COUNT(f.id) as findings')])
            ->groupBy('pr.author_login')
            ->get()
            ->keyBy('author_login');

        return $prRows->map(function ($row) use ($commitRows, $findingRows) {
            return [
                'author_login'      => $row->author_login,
                'author_name'       => $row->author_name,
                'author_avatar_url' => $row->author_avatar_url,
                'total_prs'         => (int) $row->total_prs,
                'merged_prs'        => (int) $row->merged_prs,
                'commits'           => (int) ($commitRows[$row->author_login]->commits ?? 0),
                'findings'          => (int) ($findingRows[$row->author_login]->findings ?? 0),
            ];
        })->values()->all();
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
                DB::raw('CASE WHEN f.resolved_at IS NOT NULL THEN 1 ELSE 0 END as is_resolved'),
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
                'is_resolved',
                DB::raw('COUNT(*) as count'),
            ])
            ->groupBy('author_login', 'severity', 'resolution_type', 'is_resolved')
            ->get();

        // Group findings by author — track all findings, false positives, and resolved real findings.
        $findingsByAuthor = [];
        $falsePositivesByAuthor = [];
        $resolvedRealByAuthor = [];
        foreach ($findings as $row) {
            $login = $row->author_login;
            $sev = $row->severity;
            $count = (int) $row->count;
            $findingsByAuthor[$login][$sev] = ($findingsByAuthor[$login][$sev] ?? 0) + $count;
            if ($row->resolution_type === 'false_positive') {
                $falsePositivesByAuthor[$login][$sev] = ($falsePositivesByAuthor[$login][$sev] ?? 0) + $count;
            } elseif ((int) $row->is_resolved === 1) {
                $resolvedRealByAuthor[$login] = ($resolvedRealByAuthor[$login] ?? 0) + $count;
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

            // Real findings = all findings minus false positives, by severity.
            $realCritical = max(0, $critical - ($fpSeverities['critical'] ?? 0));
            $realHigh     = max(0, $high     - ($fpSeverities['high']     ?? 0));
            $realMedium   = max(0, $medium   - ($fpSeverities['medium']   ?? 0));
            $realLow      = max(0, $low      - ($fpSeverities['low']      ?? 0));
            $totalRealFindings = $realCritical + $realHigh + $realMedium + $realLow;

            $reviewStats  = $reviewStatsByAuthor[$login] ?? null;
            $reviewCount  = $reviewStats ? max(1, (int) $reviewStats->review_count) : 0;

            // ── Factor 1 (60%): Code quality ─────────────────────────────────
            // Weighted finding rate per reviewed PR; reference ceiling = 3.0 points/PR.
            // critical=4, high=2, medium=0.75, low=0.25
            $weightedRate = $reviewCount > 0
                ? ($realCritical * 4.0 + $realHigh * 2.0 + $realMedium * 0.75 + $realLow * 0.25) / $reviewCount
                : 0.0;
            $findingScore = max(0.0, min(100.0, 100.0 * (1.0 - $weightedRate / 3.0)));

            // ── Factor 2 (25%): Fix rate ─────────────────────────────────────
            // What share of real findings the developer actually resolved.
            $resolvedReal    = $resolvedRealByAuthor[$login] ?? 0;
            $resolutionScore = $totalRealFindings > 0
                ? min(100.0, ($resolvedReal / $totalRealFindings) * 100.0)
                : 100.0; // no findings → perfect score
            $resolutionRate  = $totalRealFindings > 0
                ? round(($resolvedReal / $totalRealFindings) * 100.0, 1)
                : null;

            // ── Factor 3 (15%): Review verdict ───────────────────────────────
            // How often does the AI say "needs changes" on this developer's PRs.
            $requestChangesCount = $reviewStats ? (int) $reviewStats->request_changes_count : 0;
            $verdictScore = $reviewCount > 0
                ? max(0.0, 100.0 - ($requestChangesCount / $reviewCount) * 100.0)
                : 50.0; // neutral when no reviews yet

            $seniorityScore = ($findingScore * 0.60) + ($resolutionScore * 0.25) + ($verdictScore * 0.15);

            $seniorityLevel = match (true) {
                $seniorityScore >= 80 => 'Expert',
                $seniorityScore >= 60 => 'Senior',
                $seniorityScore >= 35 => 'Mid',
                default => 'Junior',
            };

            // Require at least 3 AI-reviewed PRs — fewer makes the score statistically meaningless.
            $hasEnoughData = $reviewStats !== null && (int) $reviewStats->review_count >= 3;

            $result[] = [
                'author_login'        => $login,
                'author_name'         => $pr->author_name,
                'author_avatar_url'   => $pr->author_avatar_url,
                'total_prs'           => (int) $pr->total_prs,
                'merged_prs'          => (int) $pr->merged_prs,
                'total_additions'     => (int) $pr->total_additions,
                'total_deletions'     => (int) $pr->total_deletions,
                'total_commits'       => (int) $pr->total_commits,
                'avg_merge_hours'     => $pr->avg_merge_hours !== null ? round((float) $pr->avg_merge_hours, 1) : null,
                'findings_by_severity' => [
                    'critical'       => $critical,
                    'high'           => $high,
                    'medium'         => $medium,
                    'low'            => $low,
                    'false_positive' => $falsePositive,
                ],
                'total_findings'              => $totalFindings,
                'resolved_findings_count'     => $resolvedReal,
                'resolution_rate'             => $resolutionRate,
                'seniority_score'             => $hasEnoughData ? round($seniorityScore, 1) : null,
                'seniority_level'             => $hasEnoughData ? $seniorityLevel : null,
                'request_changes_count'       => $requestChangesCount,
                'avg_time_to_first_review_hours' => $reviewStats && $reviewStats->avg_time_to_first_review_hours !== null
                    ? round((float) $reviewStats->avg_time_to_first_review_hours, 1)
                    : null,
                'avg_estimated_hours'         => isset($avgEstimatedHoursByAuthor[$login]) && $avgEstimatedHoursByAuthor[$login]->avg_estimated_hours !== null
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

        // Use repository_commits as the authoritative source — it includes all commits (PR and
        // direct pushes) deduped by SHA, so line stats are never double-counted.
        $commitQuery = DB::table('repository_commits as c')
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
            $commitQuery->where('c.git_repository_id', $repoId);
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
    public function daily(string $period = '30d', ?string $authorLogin = null): array
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
            ->when($authorLogin, fn ($q) => $q->where('author_login', $authorLogin))
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
            ->when($authorLogin, fn ($q) => $q->where('author_login', $authorLogin))
            ->groupBy(DB::raw('CAST(committed_at AS DATE)'))
            ->get()
            ->keyBy('date');

        $reviewRows = DB::table('pull_request_reviews as rev')
            ->join('pull_requests as pr', 'pr.id', '=', 'rev.pull_request_id')
            ->select([
                DB::raw('CAST(rev.reviewed_at AS DATE) as date'),
                DB::raw('COUNT(rev.id) as reviews'),
            ])
            ->where('rev.reviewed_at', '>=', $periodStart)
            ->when($authorLogin, fn ($q) => $q->where('pr.author_login', $authorLogin))
            ->groupBy(DB::raw('CAST(rev.reviewed_at AS DATE)'))
            ->get()
            ->keyBy('date');

        $findingRows = DB::table('pull_request_review_findings as f')
            ->join('pull_request_reviews as rev', 'rev.id', '=', 'f.pull_request_review_id')
            ->join('pull_requests as pr', 'pr.id', '=', 'rev.pull_request_id')
            ->select([
                DB::raw('CAST(rev.reviewed_at AS DATE) as date'),
                DB::raw('COUNT(f.id) as findings'),
                DB::raw('SUM(CASE WHEN f.severity = \'critical\' THEN 1 ELSE 0 END) as critical_findings'),
            ])
            ->where('rev.reviewed_at', '>=', $periodStart)
            ->when($authorLogin, fn ($q) => $q->where('pr.author_login', $authorLogin))
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
     * Per-developer profile data: contribution calendar, weekly trends, PR sizes,
     * day-of-week pattern, recent PRs, and streak stats.
     *
     * @return array<string, mixed>
     */
    public function developerProfile(string $login, ?string $repoId = null): array
    {
        // ── Contribution calendar (always last 364 days) ──────────────────
        $rawCal = DB::table('repository_commits')
            ->selectRaw('CAST(committed_at AS DATE) as date, COUNT(*) as commits, SUM(additions) as additions, SUM(deletions) as deletions')
            ->where('author_login', $login)
            ->where('committed_at', '>=', Carbon::now()->subDays(363)->startOfDay())
            ->when($repoId, fn ($q) => $q->where('git_repository_id', $repoId))
            ->groupByRaw('CAST(committed_at AS DATE)')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $calendar = collect(range(363, 0))
            ->map(function (int $i) use ($rawCal): array {
                $date = Carbon::now()->subDays($i)->format('Y-m-d');
                $row  = $rawCal->get($date);
                return [
                    'date'      => $date,
                    'commits'   => (int) ($row?->commits ?? 0),
                    'additions' => (int) ($row?->additions ?? 0),
                    'deletions' => (int) ($row?->deletions ?? 0),
                ];
            })
            ->values();

        // ── Weekly PR trend (last 12 weeks) ──────────────────────────────
        $rawWeeklyPrs = DB::table('pull_requests')
            ->selectRaw("DATE_TRUNC('week', opened_at)::date as week, COUNT(*) as opened, SUM(CASE WHEN merged_at IS NOT NULL THEN 1 ELSE 0 END) as merged")
            ->where('author_login', $login)
            ->where('opened_at', '>=', Carbon::now()->startOfWeek()->subWeeks(11))
            ->when($repoId, fn ($q) => $q->where('git_repository_id', $repoId))
            ->groupByRaw("DATE_TRUNC('week', opened_at)::date")
            ->orderBy('week')
            ->get()
            ->keyBy('week');

        $weeklyPrs = collect(range(11, 0))
            ->map(function (int $i) use ($rawWeeklyPrs): array {
                $week = Carbon::now()->startOfWeek()->subWeeks($i)->format('Y-m-d');
                $row  = $rawWeeklyPrs->get($week);
                return ['week' => $week, 'opened' => (int) ($row?->opened ?? 0), 'merged' => (int) ($row?->merged ?? 0)];
            })
            ->values();

        // ── Weekly finding trend (last 12 weeks) ─────────────────────────
        $rawFindings = DB::table('pull_request_review_findings as f')
            ->join('pull_requests as pr', 'pr.id', '=', 'f.pull_request_id')
            ->selectRaw("DATE_TRUNC('week', f.created_at)::date as week, COUNT(*) as count, SUM(CASE WHEN f.severity IN ('critical','high') THEN 1 ELSE 0 END) as high_risk")
            ->where('pr.author_login', $login)
            ->where('f.created_at', '>=', Carbon::now()->startOfWeek()->subWeeks(11))
            ->when($repoId, fn ($q) => $q->where('f.git_repository_id', $repoId))
            ->groupByRaw("DATE_TRUNC('week', f.created_at)::date")
            ->orderBy('week')
            ->get()
            ->keyBy('week');

        $weeklyFindings = collect(range(11, 0))
            ->map(function (int $i) use ($rawFindings): array {
                $week = Carbon::now()->startOfWeek()->subWeeks($i)->format('Y-m-d');
                $row  = $rawFindings->get($week);
                return ['week' => $week, 'count' => (int) ($row?->count ?? 0), 'high_risk' => (int) ($row?->high_risk ?? 0)];
            })
            ->values();

        // ── PR size distribution (XS/S/M/L/XL by additions) ──────────────
        $prSizes = DB::table('pull_request_commits as c')
            ->join('pull_requests as pr', 'pr.id', '=', 'c.pull_request_id')
            ->selectRaw('pr.id, SUM(c.additions) as additions')
            ->where('pr.author_login', $login)
            ->when($repoId, fn ($q) => $q->where('pr.git_repository_id', $repoId))
            ->groupBy('pr.id')
            ->get()
            ->reduce(function (array $carry, object $row): array {
                $a = (int) $row->additions;
                $b = match (true) {
                    $a < 10  => 'xs',
                    $a < 50  => 'sm',
                    $a < 200 => 'md',
                    $a < 500 => 'lg',
                    default  => 'xl',
                };
                $carry[$b]++;
                return $carry;
            }, ['xs' => 0, 'sm' => 0, 'md' => 0, 'lg' => 0, 'xl' => 0]);

        // ── Day-of-week commit pattern ────────────────────────────────────
        $rawDow = DB::table('repository_commits')
            ->selectRaw('EXTRACT(DOW FROM committed_at)::int as dow, COUNT(*) as commits')
            ->where('author_login', $login)
            ->where('committed_at', '>=', Carbon::now()->subDays(364)->startOfDay())
            ->when($repoId, fn ($q) => $q->where('git_repository_id', $repoId))
            ->groupByRaw('EXTRACT(DOW FROM committed_at)::int')
            ->orderBy('dow')
            ->pluck('commits', 'dow');

        $dowPattern = collect(range(0, 6))
            ->map(fn (int $d) => ['day' => $d, 'commits' => (int) ($rawDow[$d] ?? 0)])
            ->values();

        // ── Recent PRs (last 15) ──────────────────────────────────────────
        $recentPrs = DB::table('pull_requests as pr')
            ->selectRaw("
                pr.number, pr.title, pr.web_url, pr.state, pr.opened_at, pr.merged_at,
                ROUND(EXTRACT(EPOCH FROM (pr.merged_at - pr.opened_at)) / 3600, 1) as merge_hours,
                (SELECT COUNT(*) FROM pull_request_review_findings WHERE pull_request_id = pr.id) as findings_count
            ")
            ->where('pr.author_login', $login)
            ->when($repoId, fn ($q) => $q->where('pr.git_repository_id', $repoId))
            ->orderBy('pr.opened_at', 'desc')
            ->limit(15)
            ->get();

        // ── Streak computation ────────────────────────────────────────────
        $calArr     = $calendar->toArray();
        $activeDays = $calendar->filter(fn ($d) => $d['commits'] > 0)->count();

        $currentStreak = 0;
        foreach (array_reverse($calArr) as $day) {
            if ($day['commits'] === 0) break;
            $currentStreak++;
        }

        $longestStreak = $streak = 0;
        foreach ($calArr as $day) {
            if ($day['commits'] > 0) {
                $longestStreak = max($longestStreak, ++$streak);
            } else {
                $streak = 0;
            }
        }

        return [
            'calendar'        => $calendar,
            'weekly_prs'      => $weeklyPrs,
            'weekly_findings' => $weeklyFindings,
            'pr_sizes'        => $prSizes,
            'dow_pattern'     => $dowPattern,
            'recent_prs'      => $recentPrs,
            'active_days'     => $activeDays,
            'current_streak'  => $currentStreak,
            'longest_streak'  => $longestStreak,
        ];
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
