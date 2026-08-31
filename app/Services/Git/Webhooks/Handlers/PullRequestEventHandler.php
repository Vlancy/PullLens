<?php

namespace App\Services\Git\Webhooks\Handlers;

use App\Enums\GIT\GitHubWebhookEvent;
use App\Enums\GIT\PullRequestWebhookAction;
use App\Jobs\GIT\CheckFindingResolutions;
use App\Jobs\GIT\ReviewPullRequest;
use App\Jobs\GIT\SyncPullRequestDetails;
use App\Models\GIT\GitRepository;
use App\Services\Git\PullRequestSynchronizer;
use App\Services\Git\Webhooks\Contracts\GitHubEventHandler;
use App\Services\Git\Webhooks\ReviewTriggerPolicy;

/**
 * Keeps the local pull request record in step with GitHub and queues AI reviews.
 */
class PullRequestEventHandler implements GitHubEventHandler
{
    /**
     * Inject the pull request synchronizer and review trigger policy this class delegates to.
     */
    public function __construct(
        private readonly PullRequestSynchronizer $synchronizer,
        private readonly ReviewTriggerPolicy $reviewPolicy,
    ) {}

    /**
     * The event this handler is responsible for.
     */
    public function supports(): GitHubWebhookEvent
    {
        return GitHubWebhookEvent::PullRequest;
    }

    /**
     * Execute the pull request event handler job.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(GitRepository $repository, array $payload): void
    {
        $action = PullRequestWebhookAction::tryFrom((string) data_get($payload, 'action', ''));

        // Actions we do not track (assignment, review requests, milestones) are ignored.
        if ($action === null) {
            return;
        }

        $prPayload = (array) data_get($payload, 'pull_request', []);

        if ($prPayload === []) {
            return;
        }

        $pullRequest = $this->synchronizer->syncFromPayload($repository, $prPayload, $action->value);

        // Files, commits and contributors are fetched asynchronously; the webhook body
        // carries only PR-level fields.
        SyncPullRequestDetails::dispatch($pullRequest->id);

        if ($action->changesHeadCommit()) {
            $headSha = (string) data_get($prPayload, 'head.sha', '');

            // Resolve findings whose files the new commits touched, before the next
            // review runs, so already-fixed issues are not reported twice.
            if ($headSha !== '') {
                CheckFindingResolutions::dispatch($pullRequest->id, $headSha);
            }
        }

        if ($this->reviewPolicy->shouldReview($repository, $action, $pullRequest->target_branch)) {
            ReviewPullRequest::dispatch($pullRequest->id);
        }
    }
}
