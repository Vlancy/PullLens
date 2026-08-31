<?php

namespace App\Services\Reports;

use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\PullRequestState;
use App\Enums\GIT\ReviewVerdict;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestReviewFinding;
use App\Support\Database\Table;
use App\Support\Reports\ReportPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * System-wide totals for the reports overview page.
 *
 * Every figure is scoped by the same period and optional author so the tiles on the
 * page are mutually consistent — a guarantee that is only possible because the
 * scoping is applied by shared helpers rather than repeated per metric.
 */
class OverviewReportService
{
    /**
     * Aggregate totals across pull requests, reviews and findings.
     *
     * @return array<string, int>
     */
    public function handle(ReportPeriod $period, ?string $authorLogin = null): array
    {
        $since = $period->startsAt();

        return [
            'total_prs' => $this->pullRequests($since, $authorLogin, 'opened_at')->count(),

            'open_prs' => $this->pullRequests($since, $authorLogin, 'opened_at')
                ->where('state', PullRequestState::Open->value)
                ->count(),

            // Scoped by merge date, not open date: a PR merged this week counts this
            // week even if it was opened before the window.
            'merged_prs' => $this->pullRequests($since, $authorLogin, 'merged_at')
                ->whereNotNull('merged_at')
                ->count(),

            'total_reviews' => $this->reviews($since, $authorLogin)->count('rev.id'),

            'request_changes_reviews' => $this->reviews($since, $authorLogin)
                ->where('rev.verdict', ReviewVerdict::RequestChanges->value)
                ->count('rev.id'),

            'avg_review_duration_ms' => (int) round((float) $this->reviews($since, $authorLogin)
                ->where('rev.review_duration_ms', '>', 0)
                ->avg('rev.review_duration_ms')),

            'total_findings' => $this->findings($since, $authorLogin)->count('f.id'),

            'critical_findings' => $this->findings($since, $authorLogin)
                ->where('f.severity', FindingSeverity::Critical->value)
                ->count('f.id'),

            'resolved_findings' => $this->findings($since, $authorLogin, 'f.resolved_at')
                ->whereNotNull('f.resolved_at')
                ->count('f.id'),

            // Distinct PRs carrying at least one unresolved high-or-critical finding.
            'high_risk_prs' => $this->highRiskPullRequests($since, $authorLogin),

            'active_repos' => GitRepository::query()->where('reviews_enabled', true)->count(),
            'connected_accounts' => GitAccount::query()->count(),
        ];
    }

    /**
     * Pull requests scoped by period (on the given date column) and author.
     *
     * @return Builder<PullRequest>
     */
    private function pullRequests(?CarbonInterface $since, ?string $authorLogin, string $dateColumn): Builder
    {
        return PullRequest::query()
            ->when($since, fn (Builder $q) => $q->where($dateColumn, '>=', $since))
            ->when($authorLogin, fn (Builder $q) => $q->where('author_login', $authorLogin));
    }

    /**
     * Reviews joined to their pull request so the author filter can apply.
     *
     * @return Builder<PullRequestReview>
     */
    private function reviews(?CarbonInterface $since, ?string $authorLogin): Builder
    {
        return PullRequestReview::query()
            ->from(Table::as(PullRequestReview::class, 'rev'))
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'rev.pull_request_id')
            ->when($since, fn (Builder $q) => $q->where('rev.created_at', '>=', $since))
            ->when($authorLogin, fn (Builder $q) => $q->where('pr.author_login', $authorLogin));
    }

    /**
     * Findings joined through their review to the pull request author.
     *
     * @param  string  $dateColumn  Column the period bound applies to.
     * @return Builder<PullRequestReviewFinding>
     */
    private function findings(?CarbonInterface $since, ?string $authorLogin, string $dateColumn = 'f.created_at'): Builder
    {
        return PullRequestReviewFinding::query()
            ->from(Table::as(PullRequestReviewFinding::class, 'f'))
            ->join(Table::as(PullRequestReview::class, 'rev'), 'rev.id', '=', 'f.pull_request_review_id')
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'rev.pull_request_id')
            ->when($since, fn (Builder $q) => $q->where($dateColumn, '>=', $since))
            ->when($authorLogin, fn (Builder $q) => $q->where('pr.author_login', $authorLogin));
    }

    /**
     * Count of distinct pull requests with an unresolved high or critical finding.
     *
     * Joined directly on `pull_request_id` (rather than through the review) because a
     * finding always carries its PR, and the direct join avoids a redundant hop.
     */
    private function highRiskPullRequests(?CarbonInterface $since, ?string $authorLogin): int
    {
        return PullRequestReviewFinding::query()
            ->from(Table::as(PullRequestReviewFinding::class, 'f'))
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'f.pull_request_id')
            ->whereIn('f.severity', [FindingSeverity::High->value, FindingSeverity::Critical->value])
            ->whereNull('f.resolved_at')
            ->when($since, fn (Builder $q) => $q->where('f.created_at', '>=', $since))
            ->when($authorLogin, fn (Builder $q) => $q->where('pr.author_login', $authorLogin))
            ->distinct()
            ->count('f.pull_request_id');
    }
}
