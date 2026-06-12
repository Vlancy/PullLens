<?php

namespace App\Http\Controllers\Repositories;

use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\PullRequestState;
use App\Http\Controllers\Controller;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestReviewFinding;
use Inertia\Inertia;
use Inertia\Response;

class RepositoryShowController extends Controller
{
    public function __invoke(GitRepository $gitRepository): Response
    {
        $prStateCounts = PullRequest::selectRaw('state, count(*) as count')
            ->where('git_repository_id', $gitRepository->id)
            ->groupBy('state')
            ->pluck('count', 'state');

        $openPRs = (int) $prStateCounts->get(PullRequestState::Open->value, 0);
        $mergedPRs = (int) $prStateCounts->get(PullRequestState::Merged->value, 0);
        $draftPRs = (int) $prStateCounts->get(PullRequestState::Draft->value, 0);
        $closedPRs = (int) $prStateCounts->get(PullRequestState::Closed->value, 0);
        $totalPRs = (int) $prStateCounts->sum();

        $totalReviews = PullRequestReview::whereHas(
            'pullRequest',
            fn ($q) => $q->where('git_repository_id', $gitRepository->id)
        )->count();

        $findingsBySeverity = PullRequestReviewFinding::selectRaw('severity, count(*) as count')
            ->where('git_repository_id', $gitRepository->id)
            ->whereNotNull('severity')
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        $totalFindings = (int) array_sum($findingsBySeverity);
        $criticalHighFindings = (int) (
            ($findingsBySeverity[FindingSeverity::Critical->value] ?? 0) +
            ($findingsBySeverity[FindingSeverity::High->value] ?? 0)
        );

        $findingsPerPr = PullRequestReviewFinding::selectRaw('pull_request_id, count(*) as count')
            ->where('git_repository_id', $gitRepository->id)
            ->groupBy('pull_request_id')
            ->pluck('count', 'pull_request_id');

        $pullRequests = PullRequest::with(['latestReview'])
            ->where('git_repository_id', $gitRepository->id)
            ->orderByRaw("CASE WHEN state = 'open' THEN 0 WHEN state = 'draft' THEN 1 WHEN state = 'merged' THEN 2 ELSE 3 END")
            ->orderByDesc('opened_at')
            ->limit(50)
            ->get()
            ->map(fn ($pr) => [
                'id' => $pr->id,
                'number' => $pr->number,
                'title' => $pr->title,
                'state' => $pr->state?->value,
                'is_draft' => $pr->is_draft,
                'author_login' => $pr->author_login,
                'author_avatar_url' => $pr->author_avatar_url,
                'source_branch' => $pr->source_branch,
                'target_branch' => $pr->target_branch,
                'web_url' => $pr->web_url,
                'additions' => $pr->additions,
                'deletions' => $pr->deletions,
                'changed_files_count' => $pr->changed_files_count,
                'labels' => $pr->labels ?? [],
                'opened_at' => $pr->opened_at?->toISOString(),
                'merged_at' => $pr->merged_at?->toISOString(),
                'closed_at' => $pr->closed_at?->toISOString(),
                'findings_count' => (int) ($findingsPerPr->get($pr->id, 0)),
                'latest_review' => $pr->latestReview ? [
                    'verdict' => $pr->latestReview->verdict?->value,
                    'verdict_label' => $pr->latestReview->verdict?->label(),
                    'reviewed_at' => $pr->latestReview->reviewed_at?->toISOString(),
                ] : null,
            ]);

        $recentFindings = PullRequestReviewFinding::with('pullRequest')
            ->where('git_repository_id', $gitRepository->id)
            ->whereNotNull('severity')
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn ($finding) => [
                'id' => $finding->id,
                'title' => $finding->title,
                'severity' => $finding->severity?->value,
                'category' => $finding->category?->value,
                'file' => $finding->file,
                'line' => $finding->line,
                'is_resolved' => (bool) $finding->resolved_at,
                'pull_request' => $finding->pullRequest ? [
                    'number' => $finding->pullRequest->number,
                    'title' => $finding->pullRequest->title,
                    'web_url' => $finding->pullRequest->web_url,
                ] : null,
            ]);

        return Inertia::render('repositories/show', [
            'repository' => [
                'id' => $gitRepository->id,
                'full_name' => $gitRepository->full_name,
                'name' => $gitRepository->name,
                'owner_login' => $gitRepository->owner_login,
                'provider' => $gitRepository->provider?->value,
                'is_private' => $gitRepository->is_private,
                'web_url' => $gitRepository->web_url,
                'default_branch' => $gitRepository->default_branch,
                'reviews_enabled' => $gitRepository->reviews_enabled,
            ],
            'stats' => [
                'total_prs' => $totalPRs,
                'open_prs' => $openPRs,
                'merged_prs' => $mergedPRs,
                'draft_prs' => $draftPRs,
                'closed_prs' => $closedPRs,
                'total_reviews' => $totalReviews,
                'total_findings' => $totalFindings,
                'critical_high_findings' => $criticalHighFindings,
            ],
            'findings_by_severity' => $findingsBySeverity,
            'pull_requests' => $pullRequests,
            'recent_findings' => $recentFindings,
        ]);
    }
}
