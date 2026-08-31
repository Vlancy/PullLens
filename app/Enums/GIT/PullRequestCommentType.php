<?php

namespace App\Enums\GIT;

enum PullRequestCommentType: string
{
    case ReviewComment = 'review_comment';
    case IssueComment = 'issue_comment';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::ReviewComment => 'Inline review comment',
            self::IssueComment => 'PR conversation comment',
        };
    }
}
