<?php

namespace App\Services\Git\Webhooks\Handlers;

use App\Enums\GIT\GitHubWebhookEvent;
use App\Enums\GIT\PullRequestCommentType;
use App\Jobs\GIT\DisputePullRequestFinding;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequestComment;

/**
 * Handles inline comments left on specific lines of a pull request diff.
 *
 * These are the comments PullLens itself posts findings as, so a reply here is
 * usually a developer pushing back on a finding.
 */
class ReviewCommentEventHandler extends AbstractCommentEventHandler
{
    public function supports(): GitHubWebhookEvent
    {
        return GitHubWebhookEvent::ReviewComment;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function pullRequestNumber(array $payload): ?int
    {
        $number = (int) data_get($payload, 'pull_request.number', 0);

        return $number > 0 ? $number : null;
    }

    protected function commentType(): PullRequestCommentType
    {
        return PullRequestCommentType::ReviewComment;
    }

    /**
     * A comment threaded under a finding is a dispute about that finding and gets the
     * evaluator, which can mark it a false positive. Everything else gets a reply.
     */
    protected function route(GitRepository $repository, PullRequestComment $comment, int $number): void
    {
        if ($comment->pull_request_review_finding_id !== null) {
            DisputePullRequestFinding::dispatch($comment->id);

            return;
        }

        parent::route($repository, $comment, $number);
    }
}
