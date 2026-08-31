<?php

namespace App\Services\Reports;

use App\Models\GIT\PullRequestCommit;
use App\Support\Reports\LowEffortCommitRule;
use App\Support\Reports\ReportPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Commit-message quality per developer, ranked worst first.
 *
 * The low-effort classification is evaluated in SQL via LowEffortCommitRule so the
 * whole message set no longer has to be pulled into PHP; only a small sample of the
 * offending messages is fetched, to show as examples.
 */
class CommitQualityReportService
{
    /** How many example low-effort messages to surface per developer. */
    private const EXAMPLE_LIMIT = 5;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(ReportPeriod $period): array
    {
        $since = $period->startsAt();

        $rows = $this->authorTotals($since);

        if ($rows->isEmpty()) {
            return [];
        }

        $examples = $this->lowEffortExamples($since, $rows->pluck('author_login')->all());

        $result = $rows
            ->map(function (object $row) use ($examples): array {
                $total = (int) $row->total_commits;
                $lowEffort = (int) $row->low_effort_commits;

                return [
                    'author_login' => $row->author_login,
                    'author_name' => $row->author_name,
                    'author_avatar_url' => $row->author_avatar_url,
                    'total_commits' => $total,
                    'low_effort_count' => $lowEffort,
                    'low_effort_pct' => $total > 0 ? round(($lowEffort / $total) * 100, 1) : 0.0,
                    'low_effort_messages' => $examples[$row->author_login] ?? [],
                    'total_additions' => (int) $row->total_additions,
                    'total_deletions' => (int) $row->total_deletions,
                ];
            })
            ->values()
            ->all();

        usort($result, static fn (array $a, array $b): int => $b['low_effort_pct'] <=> $a['low_effort_pct']);

        return $result;
    }

    /**
     * Commit counts, line totals and the low-effort tally per author, in one pass.
     *
     * @return Collection<int, object>
     */
    private function authorTotals(?CarbonInterface $since): Collection
    {
        return PullRequestCommit::query()
            ->whereNotNull('author_login')
            ->when($since, fn (Builder $q) => $q->where('committed_at', '>=', $since))
            ->select([
                'author_login',
                DB::raw('MAX(author_name) as author_name'),
                DB::raw('MAX(author_avatar_url) as author_avatar_url'),
                DB::raw('COUNT(*) as total_commits'),
                DB::raw('SUM('.LowEffortCommitRule::sqlCaseExpression('message').') as low_effort_commits'),
                DB::raw('SUM(additions) as total_additions'),
                DB::raw('SUM(deletions) as total_deletions'),
            ])
            ->groupBy('author_login')
            ->get();
    }

    /**
     * A handful of representative low-effort messages per author.
     *
     * Fetched as distinct messages and capped in PHP: the sample is only illustrative,
     * so a window function would be more machinery than the feature warrants.
     *
     * @param  array<int, string>  $logins
     * @return array<string, array<int, string>>
     */
    private function lowEffortExamples(?CarbonInterface $since, array $logins): array
    {
        $rows = PullRequestCommit::query()
            ->whereIn('author_login', $logins)
            ->when($since, fn (Builder $q) => $q->where('committed_at', '>=', $since))
            ->whereRaw(LowEffortCommitRule::sqlCaseExpression('message').' = 1')
            ->select(['author_login', 'message'])
            ->distinct()
            ->get();

        $examples = [];

        foreach ($rows as $row) {
            $login = $row->author_login;

            if (count($examples[$login] ?? []) >= self::EXAMPLE_LIMIT) {
                continue;
            }

            $examples[$login][] = (string) $row->message;
        }

        return $examples;
    }
}
