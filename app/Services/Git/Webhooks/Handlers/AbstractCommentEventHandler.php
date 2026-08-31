<?php

namespace App\Services\Git\Webhooks\Handlers;

use App\Enums\GIT\PullRequestCommentType;
use App\Jobs\GIT\ReplyToPullRequestComment;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequestComment;
use App\Services\Git\Webhooks\Contracts\GitHubEventHandler;
use App\Services\Git\Webhooks\PullRequestResolver;
use App\Services\Git\Webhooks\WebhookCommentRecorder;
use Illuminate\Support\Facades\Log;

/**
 * Shared pipeline for the two comment events GitHub delivers on a pull request.
 *
 * Both follow the same sequence — resolve the PR, persist the comment, then decide
 * whether the assistant should reply — and differ only in where the PR number lives
 * in the payload and in how a stored comment is routed. Those two decisions are the
 * abstract/overridable steps; everything else is defined once here (Template Method).
 */
abstract class AbstractCommentEventHandler implements GitHubEventHandler
{
    /** GitHub sends `created`, `edited` and `deleted`; only new comments are actionable. */
    private const ACTIONABLE_ACTION = 'created';

    public function __construct(
        protected readonly PullRequestResolver $pullRequests,
        protected readonly WebhookCommentRecorder $comments,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(GitRepository $repository, array $payload): void
    {
        if ((string) data_get($payload, 'action', '') !== self::ACTIONABLE_ACTION) {
            return;
        }

        $number = $this->pullRequestNumber($payload);
        $commentPayload = (array) data_get($payload, 'comment', []);

        if ($number === null || $commentPayload === []) {
            return;
        }

        $pullRequest = $this->pullRequests->resolve($repository, $number);

        if ($pullRequest === null) {
            $this->skip($repository, $number, 'pull request not found and on-demand sync failed');

            return;
        }

        $comment = $this->comments->record($pullRequest, $commentPayload, $this->commentType());

        // Our own comments must never be answered, or two bots talk to each other forever.
        if ($comment->is_pull_lens) {
            return;
        }

        if (! $repository->allow_comment_replies) {
            $this->skip($repository, $number, 'allow_comment_replies is disabled for this repository');

            return;
        }

        $this->route($repository, $comment, $number);
    }

    /**
     * Queue the follow-up work for a stored, human-authored comment.
     *
     * The default is a conversational reply; subclasses override to add routing.
     */
    protected function route(GitRepository $repository, PullRequestComment $comment, int $number): void
    {
        ReplyToPullRequestComment::dispatch($comment->id);
    }

    /**
     * Extract the pull request number this event refers to, or null when the payload
     * does not describe a pull request at all.
     *
     * @param  array<string, mixed>  $payload
     */
    abstract protected function pullRequestNumber(array $payload): ?int;

    /**
     * The comment type to persist for this event.
     */
    abstract protected function commentType(): PullRequestCommentType;

    /**
     * Record why a delivery produced no action. Deliveries are still acknowledged with
     * 200 so GitHub does not retry something that will fail identically.
     */
    protected function skip(GitRepository $repository, int $number, string $reason): void
    {
        Log::info('webhook.comment.skipped', [
            'event' => $this->supports()->value,
            'repo' => $repository->full_name,
            'pr' => $number,
            'reason' => $reason,
        ]);
    }
}
