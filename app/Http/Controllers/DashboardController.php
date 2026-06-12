<?php

namespace App\Http\Controllers;

use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\PullRequestState;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestReviewFinding;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $totalRepositories = GitRepository::count();

        $prStateCounts = PullRequest::selectRaw('state, count(*) as count')
            ->groupBy('state')
            ->pluck('count', 'state');

        $totalPRs  = (int) $prStateCounts->sum();
        $openPRs   = (int) $prStateCounts->get(PullRequestState::Open->value, 0);
        $mergedPRs = (int) $prStateCounts->get(PullRequestState::Merged->value, 0);
        $draftPRs  = (int) $prStateCounts->get(PullRequestState::Draft->value, 0);
        $closedPRs = (int) $prStateCounts->get(PullRequestState::Closed->value, 0);

        $totalReviews        = PullRequestReview::count();
        $avgReviewDurationMs = PullRequestReview::whereNotNull('review_duration_ms')->avg('review_duration_ms');

        $findingsBySeverity = PullRequestReviewFinding::selectRaw('severity, count(*) as count')
            ->whereNotNull('severity')
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        $findingsByCategory = PullRequestReviewFinding::selectRaw('category, count(*) as count')
            ->whereNotNull('category')
            ->groupBy('category')
            ->pluck('count', 'category')
            ->toArray();

        $totalFindings       = (int) array_sum($findingsBySeverity);
        $criticalHighFindings = (int) (
            ($findingsBySeverity[FindingSeverity::Critical->value] ?? 0) +
            ($findingsBySeverity[FindingSeverity::High->value] ?? 0)
        );

        $verdictDistribution = PullRequestReview::selectRaw('verdict, count(*) as count')
            ->whereNotNull('verdict')
            ->groupBy('verdict')
            ->pluck('count', 'verdict')
            ->toArray();

        $recentReviews = PullRequestReview::with(['pullRequest.repository'])
            ->withCount('findings')
            ->whereNotNull('reviewed_at')
            ->latest('reviewed_at')
            ->limit(10)
            ->get()
            ->map(fn ($review) => [
                'id'                 => $review->id,
                'verdict'            => $review->verdict?->value,
                'verdict_label'      => $review->verdict?->label(),
                'risk_level'         => $review->risk_level,
                'ai_model'           => $review->ai_model,
                'review_duration_ms' => $review->review_duration_ms,
                'reviewed_at'        => $review->reviewed_at?->toISOString(),
                'findings_count'     => $review->findings_count,
                'pull_request'       => $review->pullRequest ? [
                    'number'           => $review->pullRequest->number,
                    'title'            => $review->pullRequest->title,
                    'author_login'     => $review->pullRequest->author_login,
                    'author_avatar_url' => $review->pullRequest->author_avatar_url,
                    'web_url'          => $review->pullRequest->web_url,
                    'state'            => $review->pullRequest->state?->value,
                    'repository'       => $review->pullRequest->repository
                        ? ['full_name' => $review->pullRequest->repository->full_name]
                        : null,
                ] : null,
            ]);

        $findingsPerRepo = PullRequestReviewFinding::selectRaw('git_repository_id, count(*) as findings_count')
            ->groupBy('git_repository_id')
            ->pluck('findings_count', 'git_repository_id');

        $topRepositories = GitRepository::withCount('pullRequests')
            ->orderByDesc('pull_requests_count')
            ->limit(5)
            ->get()
            ->map(fn ($repo) => [
                'id'                  => $repo->id,
                'full_name'           => $repo->full_name,
                'web_url'             => $repo->web_url,
                'provider'            => $repo->provider?->value,
                'reviews_enabled'     => $repo->reviews_enabled,
                'pull_requests_count' => $repo->pull_requests_count,
                'findings_count'      => (int) ($findingsPerRepo->get($repo->id, 0)),
            ]);

        return Inertia::render('dashboard', [
            'stats' => [
                'total_repositories'     => $totalRepositories,
                'total_prs'              => $totalPRs,
                'open_prs'               => $openPRs,
                'merged_prs'             => $mergedPRs,
                'draft_prs'              => $draftPRs,
                'closed_prs'             => $closedPRs,
                'total_reviews'          => $totalReviews,
                'avg_review_duration_ms' => $avgReviewDurationMs ? (int) $avgReviewDurationMs : null,
                'total_findings'         => $totalFindings,
                'critical_high_findings' => $criticalHighFindings,
            ],
            'findings_by_severity'  => $findingsBySeverity,
            'findings_by_category'  => $findingsByCategory,
            'verdict_distribution'  => $verdictDistribution,
            'recent_reviews'        => $recentReviews,
            'top_repositories'      => $topRepositories,
        ]);
    }
}
