<?php

namespace App\Services\Git\Webhooks;

use App\Enums\GIT\PullRequestCommentType;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestComment;
use App\Models\GIT\PullRequestEvent;
use App\Models\GIT\PullRequestReviewFinding;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Persists inbound PR comments and the activity event that accompanies them.
 *
 * Shared by the review-comment and issue-comment handlers so the de-duplication,
 * bot detection and finding-linking rules exist in exactly one place.
 */
class WebhookCommentRecorder
{
    /** Marker PullLens appends to every comment it posts, used to detect our own output. */
    private const SELF_MARKER = '<!-- pullens -->';

    /** PostgreSQL SQLSTATE for a unique-constraint violation. */
    private const UNIQUE_VIOLATION = '23505';

    /**
     * Store (or refresh) the comment and record a `comment_added` activity event.
     *
     * @param  array<string, mixed>  $payload  The `comment` object from the webhook body.
     */
    public function record(
        PullRequest $pullRequest,
        array $payload,
        PullRequestCommentType $type,
    ): PullRequestComment {
        $comment = $this->persist($pullRequest, $payload, $type);

        PullRequestEvent::create([
            'pull_request_id' => $pullRequest->id,
            'git_repository_id' => $pullRequest->git_repository_id,
            'event_type' => 'comment_added',
            'actor_login' => data_get($payload, 'user.login'),
            'actor_type' => $this->actorType($payload),
            'occurred_at' => now(),
        ]);

        return $comment;
    }

    /**
     * Upsert the comment row, linking it to the finding it replies to when possible.
     *
     * @param  array<string, mixed>  $payload
     */
    private function persist(
        PullRequest $pullRequest,
        array $payload,
        PullRequestCommentType $type,
    ): PullRequestComment {
        $key = [
            'pull_request_id' => $pullRequest->id,
            'provider_comment_id' => (int) data_get($payload, 'id'),
        ];

        $inReplyToId = data_get($payload, 'in_reply_to_id');

        $attributes = [
            'pull_request_review_finding_id' => $this->resolveFindingId($pullRequest, $inReplyToId),
            'provider_in_reply_to_id' => $inReplyToId,
            'comment_type' => $type,
            'author_login' => (string) data_get($payload, 'user.login', ''),
            'author_type' => $this->actorType($payload),
            'body' => (string) data_get($payload, 'body', ''),
            'is_pull_lens' => $this->isOwnComment($payload),
            'provider_created_at' => data_get($payload, 'created_at', now()),
            'provider_updated_at' => data_get($payload, 'updated_at'),
        ];

        try {
            return PullRequestComment::updateOrCreate($key, $attributes);
        } catch (QueryException $e) {
            // GitHub delivers the same webhook from two hosts simultaneously. Both can
            // pass the SELECT inside updateOrCreate and then race on the INSERT, hitting
            // unique(pull_request_id, provider_comment_id). Return the row the other
            // delivery committed rather than surfacing a 500 and triggering a retry.
            if (($e->errorInfo[0] ?? null) !== self::UNIQUE_VIOLATION) {
                throw $e;
            }

            Log::info('webhook.comment.duplicate_delivery_race', $key);

            return PullRequestComment::where($key)->firstOrFail();
        }
    }

    /**
     * Find the finding whose posted comment started the thread this reply belongs to.
     *
     * Resolved with a single indexed lookup scoped to the PR, rather than by loading
     * every review and finding for the PR into memory.
     */
    private function resolveFindingId(PullRequest $pullRequest, int|string|null $inReplyToId): ?string
    {
        if (blank($inReplyToId)) {
            return null;
        }

        return PullRequestReviewFinding::query()
            ->where('pull_request_id', $pullRequest->id)
            ->where('provider_comment_id', (int) $inReplyToId)
            ->value('id');
    }

    /**
     * Whether the comment was posted by PullLens itself.
     *
     * Primary signal is the invisible marker we append to every comment we post. The
     * bot-login fallback covers comments posted before the marker was introduced.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isOwnComment(array $payload): bool
    {
        $body = (string) data_get($payload, 'body', '');

        if (str_contains($body, self::SELF_MARKER)) {
            return true;
        }

        $login = strtolower((string) data_get($payload, 'user.login', ''));

        return $this->actorType($payload) === 'bot' && str_contains($login, 'lens');
    }

    /**
     * Normalize GitHub's user type to the two values the schema stores.
     *
     * @param  array<string, mixed>  $payload
     */
    private function actorType(array $payload): string
    {
        return strtolower((string) data_get($payload, 'user.type', 'user')) === 'bot' ? 'bot' : 'user';
    }
}
