<?php

namespace App\Enums\GIT;

/**
 * The GitHub webhook events PullLens subscribes to.
 *
 * Anything not listed here is acknowledged with 200 and ignored, so GitHub does not
 * queue retries for events we deliberately do not process.
 */
enum GitHubWebhookEvent: string implements \JsonSerializable
{
    case Ping = 'ping';
    case Push = 'push';
    case PullRequest = 'pull_request';
    case ReviewComment = 'pull_request_review_comment';
    case IssueComment = 'issue_comment';

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
