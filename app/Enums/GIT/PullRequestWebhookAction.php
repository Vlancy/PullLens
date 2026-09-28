<?php

namespace App\Enums\GIT;

/**
 * `pull_request` webhook actions that change data we track.
 *
 * Actions outside this set (assignments, review requests, milestones) carry no
 * information PullLens stores, so they are skipped without a database round trip.
 */
enum PullRequestWebhookAction: string
{
    case Opened = 'opened';
    case Synchronize = 'synchronize';
    case Closed = 'closed';
    case Reopened = 'reopened';
    case ReadyForReview = 'ready_for_review';
    case ConvertedToDraft = 'converted_to_draft';
    case Edited = 'edited';
    case Labeled = 'labeled';
    case Unlabeled = 'unlabeled';

    /**
     * Whether this action means the PR's head commit changed and previously reported
     * findings should be re-checked for resolution.
     */
    public function changesHeadCommit(): bool
    {
        return $this === self::Synchronize;
    }

    /**
     * Whether this action puts new code in front of us: a new pull request, a push
     * to it, or one reopened with whatever it now carries.
     */
    public function introducesCode(): bool
    {
        return in_array($this, [self::Opened, self::Synchronize, self::Reopened], true);
    }

    /**
     * All backing values, for validation rules and "in" comparisons.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
