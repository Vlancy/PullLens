<?php

namespace App\Services\Dashboard;

use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\PullRequestState;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestReviewFinding;
use App\Support\Access\RepositoryScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the headline numbers and distributions on the dashboard.
 *
 * Counts are produced by grouped queries rather than one COUNT per state, so the
 * page costs a fixed handful of queries no matter how many states or severities the
 * enums grow to.
 *
 * Every figure is bounded by the caller's RepositoryScope: a user restricted to a
 * subset of repositories sees a dashboard describing only those repositories.
 */
class DashboardStatisticsService
{
    /** Most recent reviews listed in the activity feed. */
    private const RECENT_REVIEW_LIMIT = 10;

    /** Repositories shown in the "most active" panel. */
    private const TOP_REPOSITORY_LIMIT = 5;

    /**
     * Aggregate totals for the period.
     *
     * @return array<string, int|null>
     */
    public function totals(RepositoryScope $scope): array
    {
        $states = $this->pullRequestCountsByState($scope);
        $severities = $this->findingCountsBySeverity($scope);

        return [
            'total_repositories' => $scope->applyToRepositories(GitRepository::query())->count(),
            'total_prs' => (int) $states->sum(),
            'open_prs' => (int) $states->get(PullRequestState::Open->value, 0),
            'merged_prs' => (int) $states->get(PullRequestState::Merged->value, 0),
            'draft_prs' => (int) $states->get(PullRequestState::Draft->value, 0),
            'closed_prs' => (int) $states->get(PullRequestState::Closed->value, 0),
            'total_reviews' => $this->reviewsInScope($scope)->count(),
            'avg_review_duration_ms' => $this->averageReviewDurationMs($scope),
            'total_findings' => (int) array_sum($severities),
            'critical_high_findings' => $this->unresolvedHighRiskOnOpenPullRequests($scope),
        ];
    }

    /**
     * All findings grouped by severity, resolved ones included.
     *
     * @return array<string, int>
     */
    public function findingCountsBySeverity(RepositoryScope $scope): array
    {
        return $this->countGrouped($scope->applyTo(PullRequestReviewFinding::query()), 'severity');
    }

    /**
     * Findings grouped by category.
     *
     * @return array<string, int>
     */
    public function findingCountsByCategory(RepositoryScope $scope): array
    {
        return $this->countGrouped($scope->applyTo(PullRequestReviewFinding::query()), 'category');
    }

    /**
     * Reviews grouped by verdict.
     *
     * @return array<string, int>
     */
    public function reviewCountsByVerdict(RepositoryScope $scope): array
    {
        return $this->countGrouped($this->reviewsInScope($scope), 'verdict');
    }

    /**
     * The most recently completed reviews, with their pull request and repository.
     *
     * @return Collection<int, PullRequestReview>
     */
    public function recentReviews(RepositoryScope $scope): Collection
    {
        return $this->reviewsInScope($scope)
            ->with(['pullRequest.repository'])
            ->withCount('findings')
            ->whereNotNull('reviewed_at')
            ->latest('reviewed_at')
            ->limit(self::RECENT_REVIEW_LIMIT)
            ->get();
    }

    /**
     * Busiest repositories by pull request volume, with their finding counts attached.
     *
     * @return Collection<int, GitRepository>
     */
    public function topRepositories(RepositoryScope $scope): Collection
    {
        return $scope->applyToRepositories(GitRepository::query())
            ->withCount(['pullRequests', 'findings'])
            ->orderByDesc('pull_requests_count')
            ->limit(self::TOP_REPOSITORY_LIMIT)
            ->get();
    }

    /**
     * Pull request counts keyed by state.
     *
     * @return Collection<string, int>
     */
    private function pullRequestCountsByState(RepositoryScope $scope): Collection
    {
        return $scope->applyTo(PullRequest::query())
            ->select(['state', DB::raw('COUNT(*) as total')])
            ->groupBy('state')
            ->pluck('total', 'state');
    }

    /**
     * Mean review duration in milliseconds, or null when nothing has been timed.
     */
    private function averageReviewDurationMs(RepositoryScope $scope): ?int
    {
        $average = $this->reviewsInScope($scope)
            ->whereNotNull('review_duration_ms')
            ->avg('review_duration_ms');

        return $average === null ? null : (int) $average;
    }

    /**
     * Open, unresolved critical and high findings — the queue that needs attention.
     *
     * Restricted to open pull requests: findings on merged or closed PRs are history,
     * not work in progress.
     */
    private function unresolvedHighRiskOnOpenPullRequests(RepositoryScope $scope): int
    {
        return $scope->applyTo(PullRequestReviewFinding::query())
            ->whereIn('severity', [FindingSeverity::Critical->value, FindingSeverity::High->value])
            ->whereNull('resolved_at')
            ->whereHas('pullRequest', fn (Builder $q) => $q->where('state', PullRequestState::Open->value))
            ->count();
    }

    /**
     * Reviews belonging to pull requests inside the scope.
     *
     * Reviews carry no repository column of their own, so the constraint is applied
     * through the pull request relation.
     *
     * @return Builder<PullRequestReview>
     */
    private function reviewsInScope(RepositoryScope $scope): Builder
    {
        return PullRequestReview::query()
            ->when($scope->isRestricted(), fn (Builder $q) => $q->whereHas(
                'pullRequest',
                fn (Builder $pr) => $scope->applyTo($pr),
            ));
    }

    /**
     * COUNT(*) grouped by a column, skipping null values.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, int>
     */
    private function countGrouped(Builder $query, string $column): array
    {
        return $query
            ->select([$column, DB::raw('COUNT(*) as total')])
            ->whereNotNull($column)
            ->groupBy($column)
            ->pluck('total', $column)
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }
}
