<?php

namespace App\Services\Reports;

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
 * Ranks developers by pull request volume for the overview page leaderboard.
 *
 * Commits and findings are fetched as two additional grouped queries keyed by login
 * rather than as joins onto the PR aggregate: joining them would multiply rows and
 * inflate the PR counts.
 */
class LeaderboardReportService
{
    /**
     * Execute the leaderboard report service job.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(ReportPeriod $period): array
    {
        $since = $period->startsAt();

        $authors = $this->pullRequestTotals($since);

        if ($authors->isEmpty()) {
            return [];
        }

        $logins = $authors->keys()->all();
        $commits = $this->commitTotals($since, $logins);
        $findings = $this->findingTotals($since, $logins);

        return $authors
            ->map(fn (object $row): array => [
                'author_login' => $row->author_login,
                'author_name' => $row->author_name,
                'author_avatar_url' => $row->author_avatar_url,
                'total_prs' => (int) $row->total_prs,
                'merged_prs' => (int) $row->merged_prs,
                'commits' => (int) ($commits[$row->author_login]->commits ?? 0),
                'findings' => (int) ($findings[$row->author_login]->findings ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * Opened and merged PR counts per author, ordered by volume.
     *
     * @return Collection<string, object>
     */
    private function pullRequestTotals(?CarbonInterface $since): Collection
    {
        return PullRequest::query()
            ->whereNotNull('author_login')
            ->when($since, fn (Builder $q) => $q->where('opened_at', '>=', $since))
            ->select([
                'author_login',
                // MAX() picks a representative display name; an author's name and avatar
                // can differ between PRs after a profile change.
                DB::raw('MAX(author_name) as author_name'),
                DB::raw('MAX(author_avatar_url) as author_avatar_url'),
                DB::raw('COUNT(*) as total_prs'),
                DB::raw('COUNT(CASE WHEN merged_at IS NOT NULL THEN 1 END) as merged_prs'),
            ])
            ->groupBy('author_login')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get()
            ->keyBy('author_login');
    }

    /**
     * Commit counts per author within the period.
     *
     * @param  array<int, string>  $logins
     * @return Collection<string, object>
     */
    private function commitTotals(?CarbonInterface $since, array $logins): Collection
    {
        return PullRequestCommit::query()
            ->from(Table::as(PullRequestCommit::class, 'c'))
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'c.pull_request_id')
            ->whereIn('c.author_login', $logins)
            ->when($since, fn (Builder $q) => $q->where('pr.opened_at', '>=', $since))
            ->select(['c.author_login', DB::raw('COUNT(c.id) as commits')])
            ->groupBy('c.author_login')
            ->get()
            ->keyBy('author_login');
    }

    /**
     * Finding counts per PR author within the period.
     *
     * @param  array<int, string>  $logins
     * @return Collection<string, object>
     */
    private function findingTotals(?CarbonInterface $since, array $logins): Collection
    {
        return PullRequestReviewFinding::query()
            ->from(Table::as(PullRequestReviewFinding::class, 'f'))
            ->join(Table::as(PullRequestReview::class, 'rev'), 'rev.id', '=', 'f.pull_request_review_id')
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'rev.pull_request_id')
            ->whereIn('pr.author_login', $logins)
            ->when($since, fn (Builder $q) => $q->where('f.created_at', '>=', $since))
            ->select(['pr.author_login', DB::raw('COUNT(f.id) as findings')])
            ->groupBy('pr.author_login')
            ->get()
            ->keyBy('author_login');
    }
}
