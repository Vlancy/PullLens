<?php

namespace App\Jobs\GIT;

use App\Ai\Agents\PullRequestCommentReplyAgent;
use App\Enums\GIT\PullRequestCommentType;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitProviderApp;
use App\Models\GIT\PullRequestComment;
use App\Models\GIT\PullRequestEvent;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestReviewReply;
use App\Services\AI\AiProviderConfigResolver;
use App\Services\Git\GitHubApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates an AI reply to a PR comment using the stored review context,
 * persists the reply, and posts it back to GitHub.
 */
class ReplyToPullRequestComment implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(public readonly string $commentId) {}

    /**
     * Unique key prevents duplicate jobs for the same comment from queuing.
     */
    public function uniqueId(): string
    {
        return $this->commentId;
    }

    public function handle(
        GitHubApiClient $api,
        AiProviderConfigResolver $configResolver,
    ): void {
        $comment = PullRequestComment::with([
            'pullRequest.repository.account',
            'pullRequest.repository.aiProvider',
        ])->findOrFail($this->commentId);

        // Never reply to our own comments or replies.
        if ($comment->is_pull_lens) {
            return;
        }

        // Idempotency guard — a reply was already posted for this comment (e.g. retried webhook).
        if (PullRequestReviewReply::where('pull_request_comment_id', $comment->id)->exists()) {
            return;
        }

        $pullRequest = $comment->pullRequest;
        $repository = $pullRequest->repository;

        // Require a stored review to provide context to the reply agent.
        $review = PullRequestReview::with('findings')
            ->where('pull_request_id', $pullRequest->id)
            ->latest('reviewed_at')
            ->first();

        if (! $review) {
            // PR has not been reviewed yet. Trigger a review and retry this
            // job after 90 seconds — enough time for the review to complete.
            // Only do this on the first attempt to avoid an infinite chain.
            if ($this->attempts() === 1) {
                ReviewPullRequest::dispatch($pullRequest->id);
                $this->release(90);
            }

            return;
        }

        $reviewContext = $this->buildReviewContext($review);

        $thread = $this->buildCommentThread($comment);

        $metadata = [
            'repository'       => $repository->full_name,
            'target_branch'    => $pullRequest->target_branch,
            'detected_stack'   => $review->detected_stack,
            'review_language'  => $repository->review_language,
        ];

        $resolved = $configResolver->forRepository($repository);
        $agent = new PullRequestCommentReplyAgent;

        $result = $agent->prompt(
            $agent->buildPrompt($reviewContext, $thread, $metadata),
            provider: $resolved->configName,
            model: $resolved->model,
        );

        $reply = PullRequestReviewReply::create([
            'pull_request_comment_id' => $comment->id,
            'pull_request_review_id'  => $review->id,
            'ai_provider_id'          => $resolved->provider->id,
            'ai_model'                => $resolved->model,
            'schema_version'          => data_get($result, 'schema_version', 'pull_lens.comment_reply.v1'),
            'reply'                   => (string) data_get($result, 'reply', ''),
            'reply_type'              => (string) data_get($result, 'reply_type', 'clarification'),
            'addressed_finding_key'   => data_get($result, 'addressed_finding_key'),
            'confidence'              => (float) data_get($result, 'confidence', 0.5),
            'requires_author_action'  => (bool) data_get($result, 'requires_author_action', false),
            'suggested_resolution'    => (string) data_get($result, 'suggested_resolution', 'keep_open'),
            'replied_at'              => now(),
        ]);

        $app    = GitProviderApp::where('provider', 'github')->first();
        $poster = $repository->account;

        if ($app?->private_key && $repository->installation_id) {
            $token = $api->installationToken($app, (int) $repository->installation_id);
            if ($token !== '') {
                $poster = $token;
            } else {
                Log::warning('reply.installation_token_empty', [
                    'comment_id'      => $this->commentId,
                    'installation_id' => $repository->installation_id,
                ]);
            }
        }

        Log::info('reply.posting', [
            'reply_id'    => $reply->id,
            'comment_id'  => $this->commentId,
            'repo'        => $repository->full_name,
            'poster_type' => is_string($poster) ? 'installation_token' : 'oauth',
        ]);

        $this->maybePostToGitHub($pullRequest, $comment, $reply, $api, $poster, $repository);

        // Optionally update the finding resolution when a fix is confirmed.
        if (data_get($result, 'reply_type') === 'fix_confirmed' && data_get($result, 'addressed_finding_key')) {
            $review->findings()
                ->where('dedupe_key', data_get($result, 'addressed_finding_key'))
                ->whereNull('resolved_at')
                ->update([
                    'resolved_at'     => now(),
                    'resolution_type' => 'fix_confirmed',
                ]);
        }

        PullRequestEvent::create([
            'pull_request_id'   => $pullRequest->id,
            'git_repository_id' => $repository->id,
            'event_type'        => 'pull_lens_reply_posted',
            'actor_type'        => 'bot',
            'payload'           => [
                'reply_id'             => $reply->id,
                'reply_type'           => $reply->reply_type,
                'addressed_finding'    => $reply->addressed_finding_key,
                'requires_author_action' => $reply->requires_author_action,
            ],
            'occurred_at' => now(),
        ]);
    }

    /**
     * Build the structured review context object passed to the reply agent.
     *
     * @return array<string, mixed>
     */
    private function buildReviewContext(PullRequestReview $review): array
    {
        return [
            'walkthrough' => $review->walkthrough,
            'risk_level'  => $review->risk_level,
            'verdict'     => $review->verdict,
            'summary'     => $review->summary,
            'findings'    => $review->findings->map(fn ($f) => [
                'dedupe_key'    => $f->dedupe_key,
                'title'         => $f->title,
                'severity'      => $f->severity,
                'category'      => $f->category,
                'file'          => $f->file,
                'explanation'   => $f->explanation,
                'suggested_fix' => $f->suggested_fix,
            ])->values()->all(),
        ];
    }

    /**
     * Collect the comment thread (parent + all replies) as a string array for the agent.
     *
     * @return string[]
     */
    private function buildCommentThread(PullRequestComment $triggeringComment): array
    {
        $thread = [];

        // Include the parent comment if this is a reply.
        if ($triggeringComment->provider_in_reply_to_id) {
            $parent = PullRequestComment::where(
                'provider_comment_id',
                $triggeringComment->provider_in_reply_to_id,
            )->first();

            if ($parent) {
                $thread[] = "[{$parent->author_login}]: {$parent->body}";
            }
        }

        $thread[] = "[{$triggeringComment->author_login}]: {$triggeringComment->body}";

        return $thread;
    }

    /**
     * Compose the final GitHub comment body: quoted original + @mention + AI reply.
     */
    private function buildReplyBody(PullRequestComment $comment, string $aiReply): string
    {
        $author = (string) $comment->author_login;

        // Strip the pullens marker from the original body before quoting.
        $originalBody = trim(str_replace('<!-- pullens -->', '', (string) $comment->body));

        // Prefix every line of the original comment with "> " for GitHub quote syntax.
        $quoted = implode("\n", array_map(
            fn (string $line) => '> '.$line,
            explode("\n", $originalBody),
        ));

        return implode("\n\n", array_filter([
            $quoted,
            "@{$author} {$aiReply}",
            '<!-- pullens -->',
        ]));
    }

    /**
     * Post the AI reply to GitHub and record the provider comment ID.
     */
    private function maybePostToGitHub(
        $pullRequest,
        PullRequestComment $comment,
        PullRequestReviewReply $reply,
        GitHubApiClient $api,
        GitAccount|string $poster,
        $repository,
    ): void {
        if (empty($reply->reply)) {
            Log::warning('reply.skipped.empty_body', [
                'reply_id'   => $reply->id,
                'comment_id' => $this->commentId,
                'repo'       => $repository->full_name,
            ]);

            return;
        }

        [$owner, $name] = explode('/', $repository->full_name, 2);

        $body = $this->buildReplyBody($comment, (string) $reply->reply);

        try {
            // Inline file comments must be replied in their own thread.
            // General PR conversation comments use the issue comment endpoint.
            if ($comment->comment_type === PullRequestCommentType::ReviewComment && $comment->provider_comment_id) {
                $posted = $api->replyToReviewComment(
                    $poster,
                    $owner,
                    $name,
                    $pullRequest->number,
                    (int) $comment->provider_comment_id,
                    $body,
                );
            } else {
                $posted = $api->postIssueComment(
                    $poster,
                    $owner,
                    $name,
                    $pullRequest->number,
                    $body,
                );
            }

            $postedId = data_get($posted, 'id');

            $reply->update([
                'posted_to_provider'  => true,
                'provider_comment_id' => $postedId,
            ]);

            // Record the posted PullLens reply as a comment row so we
            // never re-reply to our own comment on the next webhook call.
            if ($postedId) {
                PullRequestComment::updateOrCreate(
                    [
                        'pull_request_id'     => $pullRequest->id,
                        'provider_comment_id' => $postedId,
                    ],
                    [
                        'pull_request_review_finding_id' => null,
                        'provider_in_reply_to_id'        => null,
                        'comment_type'                   => $comment->comment_type->value,
                        'author_login'                   => 'pull-lens[bot]',
                        'author_type'                    => 'bot',
                        'body'                           => $body,
                        'is_pull_lens'                   => true,
                        'provider_created_at'            => now(),
                    ],
                );
            }
        } catch (Throwable $e) {
            Log::warning('reply.post_failed', [
                'reply_id'    => $reply->id,
                'comment_id'  => $this->commentId,
                'repo'        => $repository->full_name,
                'poster_type' => $poster instanceof GitAccount ? 'oauth' : 'installation_token',
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
