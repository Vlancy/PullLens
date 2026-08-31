<?php

namespace App\Services\Reports;

use App\Models\GIT\PullRequest;
use App\Models\GIT\RepositoryCommit;
use App\Support\Reports\LowEffortCommitRule;
use App\Support\Reports\ReportPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One row per (developer, day) describing that day's effort.
 *
 * Sourced from `repository_commits` rather than `pull_request_commits`: it holds
 * every commit — direct pushes included — deduplicated by SHA, so line statistics
 * are neither missed nor double-counted when a commit later lands in a PR.
 */
class DeveloperDailyReportService
{
    /** Window used when the requested period has no fixed length ("all time"). */
    private const DEFAULT_DAYS = 7;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(ReportPeriod $period, ?string $repositoryId = null): array
    {
        $since = now()->subDays($period->days() ?? self::DEFAULT_DAYS)->startOfDay();

        $commitRows = $this->commitsByAuthorAndDay($since, $repositoryId);
        $prsOpened = $this->pullRequestsByAuthorAndDay($since, $repositoryId);

        return $commitRows
            ->map(function (object $row) use ($prsOpened): array {
                $total = (int) $row->total_commits;
                $lowEffort = (int) $row->low_effort_commits;
                $useful = $total - $lowEffort;

                return [
                    'date' => (string) $row->date,
                    'author_login' => $row->author_login,
                    'author_name' => $row->author_name,
                    'author_avatar_url' => $row->author_avatar_url,
                    'total_commits' => $total,
                    'low_effort_commits' => $lowEffort,
                    'useful_commits' => $useful,
                    'additions' => (int) $row->additions,
                    'deletions' => (int) $row->deletions,
                    'active_hours' => round((float) $row->active_hours, 1),
                    'prs_opened' => (int) ($prsOpened["{$row->author_login}|{$row->date}"] ?? 0),
                    'is_productive' => $useful > 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Commit totals, line counts and an activity span per developer per day.
     *
     * `active_hours` is the span between the first and last commit of the day. It is
     * a proxy for engaged time, not a timesheet — a single commit yields zero.
     *
     * @return Collection<int, object>
     */
    private function commitsByAuthorAndDay(CarbonInterface $since, ?string $repositoryId): Collection
    {
        $day = DB::raw('CAST(committed_at AS DATE)');

        return RepositoryCommit::query()
            ->whereNotNull('author_login')
            ->where('committed_at', '>=', $since)
            ->when($repositoryId, fn (Builder $q) => $q->where('git_repository_id', $repositoryId))
            ->select([
                'author_login',
                DB::raw('MAX(author_name) as author_name'),
                DB::raw('MAX(author_avatar_url) as author_avatar_url'),
                DB::raw('CAST(committed_at AS DATE) as date'),
                DB::raw('COUNT(*) as total_commits'),
                DB::raw('SUM(additions) as additions'),
                DB::raw('SUM(deletions) as deletions'),
                DB::raw('EXTRACT(EPOCH FROM (MAX(committed_at) - MIN(committed_at))) / 3600 as active_hours'),
                DB::raw('SUM('.LowEffortCommitRule::sqlCaseExpression('message').') as low_effort_commits'),
            ])
            ->groupBy('author_login', $day)
            ->orderByDesc($day)
            ->orderBy('author_login')
            ->get();
    }

    /**
     * PRs opened per developer per day, keyed as "login|date" for O(1) lookup.
     *
     * @return array<string, int>
     */
    private function pullRequestsByAuthorAndDay(CarbonInterface $since, ?string $repositoryId): array
    {
        $rows = PullRequest::query()
            ->whereNotNull('author_login')
            ->where('opened_at', '>=', $since)
            ->when($repositoryId, fn (Builder $q) => $q->where('git_repository_id', $repositoryId))
            ->select([
                'author_login',
                DB::raw('CAST(opened_at AS DATE) as date'),
                DB::raw('COUNT(*) as prs_opened'),
            ])
            ->groupBy('author_login', DB::raw('CAST(opened_at AS DATE)'))
            ->get();

        $keyed = [];

        foreach ($rows as $row) {
            $keyed["{$row->author_login}|{$row->date}"] = (int) $row->prs_opened;
        }

        return $keyed;
    }
}
