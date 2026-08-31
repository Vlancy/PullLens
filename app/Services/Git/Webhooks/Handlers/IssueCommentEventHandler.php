<?php

namespace App\Services\Git\Webhooks\Handlers;

use App\Enums\GIT\GitHubWebhookEvent;
use App\Enums\GIT\PullRequestCommentType;

/**
 * Handles top-level conversation comments on a pull request.
 *
 * GitHub delivers `issue_comment` for both issues and pull requests; only the latter
 * are relevant, and the payload distinguishes them by the presence of
 * `issue.pull_request`.
 */
class IssueCommentEventHandler extends AbstractCommentEventHandler
{
    public function supports(): GitHubWebhookEvent
    {
        return GitHubWebhookEvent::IssueComment;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function pullRequestNumber(array $payload): ?int
    {
        if (blank(data_get($payload, 'issue.pull_request'))) {
            return null;
        }

        $number = (int) data_get($payload, 'issue.number', 0);

        return $number > 0 ? $number : null;
    }

    protected function commentType(): PullRequestCommentType
    {
        return PullRequestCommentType::IssueComment;
    }
}
