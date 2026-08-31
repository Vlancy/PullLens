<?php

namespace App\Services\Git\Webhooks;

use App\Enums\GIT\PullRequestWebhookAction;
use App\Models\GIT\GitRepository;

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
}
