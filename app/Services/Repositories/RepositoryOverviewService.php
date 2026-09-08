<?php

namespace App\Services\Repositories;

use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\PullRequestState;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestReviewFinding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the statistics, pull request list and recent findings for one repository.
 *
 * Extracted from the controller so the page's query logic can be exercised without
 * an HTTP request, and so the same numbers can be reused elsewhere later.
 */
class RepositoryOverviewService
{
    /** Pull requests listed on the page. */
    private const PULL_REQUEST_LIMIT = 50;

    /** Findings shown in the "recent" panel. */
    private const RECENT_FINDING_LIMIT = 15;

    /**
     * Headline counts for the repository.
     *
     * @return array<string, int>
     */
    public function statistics(GitRepository $repository): array
    {
        $states = $this->pullRequestCountsByState($repository);
        $severities = $this->openFindingCountsBySeverity($repository);

        return [
            'total_prs' => (int) $states->sum(),
            'open_prs' => (int) $states->get(PullRequestState::Open->value, 0),
            'merged_prs' => (int) $states->get(PullRequestState::Merged->value, 0),
            'draft_prs' => (int) $states->get(PullRequestState::Draft->value, 0),
            'closed_prs' => (int) $states->get(PullRequestState::Closed->value, 0),
            'total_reviews' => $this->reviewCount($repository),
            'total_findings' => (int) array_sum($severities),
            'critical_high_findings' => ($severities[FindingSeverity::Critical->value] ?? 0)
                + ($severities[FindingSeverity::High->value] ?? 0),
        ];
    }

    /**
     * Unresolved findings grouped by severity - the outstanding risk in this repository.
     *
     * @return array<string, int>
     */
    public function openFindingCountsBySeverity(GitRepository $repository): array
    {
        return PullRequestReviewFinding::query()
            ->where('git_repository_id', $repository->id)
            ->whereNotNull('severity')
            ->whereNull('resolved_at')
            ->select(['severity', DB::raw('COUNT(*) as total')])
            ->groupBy('severity')
            ->pluck('total', 'severity')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * The repository's pull requests, most actionable first, each with its open
     * finding count and latest review verdict.
     *
     * @return Collection<int, PullRequest>
     */
    public function pullRequests(GitRepository $repository): Collection
    {
        $openFindingsPerPr = PullRequestReviewFinding::query()
            ->where('git_repository_id', $repository->id)
            ->whereNull('resolved_at')
            ->select(['pull_request_id', DB::raw('COUNT(*) as total')])
            ->groupBy('pull_request_id')
            ->pluck('total', 'pull_request_id');

        return PullRequest::query()
            ->with('latestReview')
            ->where('git_repository_id', $repository->id)
            // Open work first, then drafts, then history - the order someone triaging wants.
            ->orderByRaw($this->stateRanking())
            ->orderByDesc('opened_at')
            ->limit(self::PULL_REQUEST_LIMIT)
            ->get()
            ->each(function (PullRequest $pullRequest) use ($openFindingsPerPr): void {
                // Attached rather than joined so the PR rows are not multiplied by findings.
                $pullRequest->setAttribute(
                    'open_findings_count',
                    (int) ($openFindingsPerPr[$pullRequest->id] ?? 0),
                );
            });
    }

    /**
     * The newest findings raised in this repository.
     *
     * @return Collection<int, PullRequestReviewFinding>
     */
    public function recentFindings(GitRepository $repository): Collection
    {
        return PullRequestReviewFinding::query()
            ->with('pullRequest')
            ->where('git_repository_id', $repository->id)
            ->whereNotNull('severity')
            ->latest()
            ->limit(self::RECENT_FINDING_LIMIT)
            ->get();
    }

    /**
     * Pull request counts keyed by state.
     *
     * @return Collection<string, int>
     */
    private function pullRequestCountsByState(GitRepository $repository): Collection
    {
        return PullRequest::query()
            ->where('git_repository_id', $repository->id)
            ->select(['state', DB::raw('COUNT(*) as total')])
            ->groupBy('state')
            ->pluck('total', 'state');
    }

    /**
     * How many reviews have been produced for this repository.
     */
    private function reviewCount(GitRepository $repository): int
    {
        return PullRequestReview::query()
            ->whereHas(
                'pullRequest',
                fn (Builder $q) => $q->where('git_repository_id', $repository->id),
            )
            ->count();
    }

    /**
     * Sort expression putting open PRs first, then drafts, then merged, then the rest.
     *
     * State values come from the enum, never from a request.
     */
    private function stateRanking(): string
    {
        $order = [PullRequestState::Open, PullRequestState::Draft, PullRequestState::Merged];

        $clauses = [];

        foreach ($order as $rank => $state) {
            $clauses[] = sprintf("WHEN state = '%s' THEN %d", $state->value, $rank);
        }

        return sprintf('CASE %s ELSE %d END', implode(' ', $clauses), count($order));
    }
}
