<?php

namespace App\Services\Repositories;

use App\Enums\GIT\PullRequestState;
use App\Enums\GIT\ReviewTrigger;
use App\Jobs\GIT\ReviewPullRequest;
use App\Jobs\GIT\SyncPullRequestState;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Services\Git\Webhooks\ReviewTriggerPolicy;

/**
 * Re-syncs a repository's live pull requests and queues reviews for the ones that need one.
 *
 * Shares ReviewTriggerPolicy with the webhook handler so a manual sync honours exactly
 * the same `reviews_enabled` and `tracked_branches` rules - these two paths previously
 * diverged and the manual button reviewed branches the repository did not track.
 */
class PullRequestReviewQueuer
{
    /**
     * Inject the review trigger policy this class delegates to.
     */
    public function __construct(private readonly ReviewTriggerPolicy $policy) {}

    /**
     * Re-sync a repository's open pull requests and queue any missing reviews.
     *
     * @return int Number of reviews queued.
     */
    public function queueFor(GitRepository $repository): int
    {
        $pullRequests = PullRequest::query()
            ->where('git_repository_id', $repository->id)
            ->whereIn('state', [PullRequestState::Open->value, PullRequestState::Draft->value])
            ->get(['id', 'head_sha', 'target_branch']);

        $queued = 0;

        foreach ($pullRequests as $pullRequest) {
            // Always re-sync state first: a PR merged or closed while we were not
            // listening would otherwise stay "open" forever.
            SyncPullRequestState::dispatch($pullRequest->id);

            if (! $this->policy->isBranchEligible($repository, $pullRequest->target_branch)) {
                continue;
            }

            if ($this->alreadyReviewed($pullRequest)) {
                continue;
            }

            ReviewPullRequest::dispatch($pullRequest->id, ReviewTrigger::Manual);
            $queued++;
        }

        return $queued;
    }

    /**
     * Whether the PR's current head commit has already been reviewed and posted.
     *
     * Keyed on the head SHA so a re-sync after new commits does trigger a fresh review,
     * while a re-sync with no new work does not spend AI credit twice.
     */
    private function alreadyReviewed(PullRequest $pullRequest): bool
    {
        $headSha = (string) ($pullRequest->head_sha ?? '');

        if ($headSha === '') {
            return false;
        }

        return PullRequestReview::query()
            ->where('pull_request_id', $pullRequest->id)
            ->where('head_sha', $headSha)
            ->where('posted_to_provider', true)
            ->exists();
    }
}
