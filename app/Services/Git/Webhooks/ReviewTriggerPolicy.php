<?php

namespace App\Services\Git\Webhooks;

use App\Enums\GIT\PullRequestWebhookAction;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;

/**
 * Decides whether a webhook action should spend AI credit on a review.
 *
 * Isolated from the handler so the same rules can be reused by the hourly sync
 * command and the manual sync endpoint, which previously drifted from the webhook's
 * behaviour and reviewed branches the repository was not configured to track.
 */
class ReviewTriggerPolicy
{
    /**
     * Whether a review job should be queued for this action.
     */
    public function shouldReview(
        GitRepository $repository,
        PullRequestWebhookAction $action,
        ?string $targetBranch,
    ): bool {
        if (! $this->isBranchEligible($repository, $targetBranch)) {
            return false;
        }

        return match ($action) {
            // Opening a PR only triggers a review when the repository opts in.
            PullRequestWebhookAction::Opened,
            PullRequestWebhookAction::ReadyForReview => (bool) $repository->auto_review_on_open,

            // New commits always warrant a fresh review of the changed code.
            PullRequestWebhookAction::Synchronize => true,

            default => false,
        };
    }

    /**
     * Whether reviews are enabled for the repository and this branch is tracked.
     *
     * An empty tracked_branches list means "every branch".
     */
    public function isBranchEligible(GitRepository $repository, ?string $targetBranch): bool
    {
        if (! $repository->reviews_enabled) {
            return false;
        }

        $tracked = array_filter((array) ($repository->tracked_branches ?? []));

        return $tracked === [] || in_array($targetBranch, $tracked, true);
    }

    /**
     * Whether the pull request's current head commit already has a posted review.
     *
     * Keyed on the head SHA so a re-sync after new commits does trigger a fresh
     * review, while a re-sync with no new work does not spend AI credit twice.
     */
    public function hasReviewedCurrentHead(PullRequest $pullRequest): bool
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
