<?php

namespace App\Services\Reports;

use App\Enums\GIT\PullRequestState;
use App\Enums\GIT\ReviewVerdict;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestReviewFinding;
use App\Support\Database\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-repository activity and quality statistics.
 *
 * Reviews and resolved findings are gathered as separate keyed queries rather than
 * as further joins on the main aggregate: adding them as joins would multiply the
 * grouped rows and inflate the PR and finding counts.
 */
class RepositoryReportService
{
    /**
     * Execute the repository report service job.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(): array
    {
        $repositories = $this->repositoryTotals();

        if ($repositories->isEmpty()) {
            return [];
        }

        $topCategories = $this->topCategoryByRepository();
        $reviewStats = $this->reviewStatsByRepository();
        $resolved = $this->resolvedFindingsByRepository();

        return $repositories
            ->map(function (object $repo) use ($topCategories, $reviewStats, $resolved): array {
                $reviews = (int) ($reviewStats[$repo->id]->total_reviews ?? 0);
                $approvals = (int) ($reviewStats[$repo->id]->approve_count ?? 0);

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
                    'top_category' => $topCategories[$repo->id] ?? null,
                    'last_pr_at' => $repo->last_pr_at,
                    'total_reviews' => $reviews,
                    'approve_rate' => $reviews > 0 ? round(($approvals / $reviews) * 100, 1) : 0.0,
                    'resolved_findings' => (int) ($resolved[$repo->id]->resolved_count ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * PR and open-finding counts per review-enabled repository.
     *
     * COUNT(DISTINCT ...) is required because the two left joins produce a cartesian
     * product of pull requests and findings for the same repository.
     *
     * @return Collection<int, object>
     */
    private function repositoryTotals(): Collection
    {
        return GitRepository::query()
            ->from(Table::as(GitRepository::class, 'gr'))
            ->leftJoin(Table::as(PullRequest::class, 'pr'), 'pr.git_repository_id', '=', 'gr.id')
            ->leftJoin(Table::as(PullRequestReviewFinding::class, 'f'), 'f.git_repository_id', '=', 'gr.id')
            ->where('gr.reviews_enabled', true)
            ->select([
                'gr.id',
                'gr.name',
                'gr.full_name',
                'gr.owner_login',
                'gr.web_url',
                DB::raw('COUNT(DISTINCT pr.id) as total_prs'),
                DB::raw('COUNT(DISTINCT CASE WHEN pr.merged_at IS NOT NULL THEN pr.id END) as merged_prs'),
                DB::raw("COUNT(DISTINCT CASE WHEN pr.state = '".PullRequestState::Open->value."' THEN pr.id END) as open_prs"),
                DB::raw('COUNT(DISTINCT CASE WHEN f.resolved_at IS NULL THEN f.id END) as total_findings'),
                DB::raw('MAX(pr.opened_at) as last_pr_at'),
            ])
            ->groupBy('gr.id', 'gr.name', 'gr.full_name', 'gr.owner_login', 'gr.web_url')
            ->orderByDesc('total_prs')
            ->get();
    }

    /**
     * The most frequently reported finding category for each repository.
     *
     * @return array<string, string|null>
     */
    private function topCategoryByRepository(): array
    {
        $rows = PullRequestReviewFinding::query()
            ->whereNotNull('category')
            ->select(['git_repository_id', 'category', DB::raw('COUNT(*) as total')])
            ->groupBy('git_repository_id', 'category')
            ->orderByDesc('total')
            ->get();

        $top = [];

        // Ordered by count descending, so the first row seen for a repository wins.
        foreach ($rows as $row) {
            $top[$row->git_repository_id] ??= $row->category;
        }

        return $top;
    }

    /**
     * Review totals and approval counts per repository.
     *
     * @return Collection<string, object>
     */
    private function reviewStatsByRepository(): Collection
    {
        return PullRequestReview::query()
            ->from(Table::as(PullRequestReview::class, 'rev'))
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'rev.pull_request_id')
            ->select([
                'pr.git_repository_id',
                DB::raw('COUNT(rev.id) as total_reviews'),
                DB::raw("SUM(CASE WHEN rev.verdict = '".ReviewVerdict::Approve->value."' THEN 1 ELSE 0 END) as approve_count"),
            ])
            ->groupBy('pr.git_repository_id')
            ->get()
            ->keyBy('git_repository_id');
    }

    /**
     * Count of resolved findings per repository.
     *
     * @return Collection<string, object>
     */
    private function resolvedFindingsByRepository(): Collection
    {
        return PullRequestReviewFinding::query()
            ->whereNotNull('resolved_at')
            ->select(['git_repository_id', DB::raw('COUNT(*) as resolved_count')])
            ->groupBy('git_repository_id')
            ->get()
            ->keyBy('git_repository_id');
    }
}
