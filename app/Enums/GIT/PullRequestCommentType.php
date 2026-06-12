<?php

namespace App\Enums\GIT;

enum PullRequestCommentType: string
{
    case ReviewComment = 'review_comment';
    case IssueComment = 'issue_comment';

    public function label(): string
    {
        return match ($this) {
            self::ReviewComment => 'Inline review comment',
            self::IssueComment => 'PR conversation comment',
        };
    }
}
