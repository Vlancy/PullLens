<?php

namespace App\Jobs\GIT;

use App\Ai\Agents\FindingDisputeAgent;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitProviderApp;
use App\Models\GIT\PullRequestComment;
use App\Models\GIT\PullRequestEvent;
use App\Models\GIT\PullRequestReviewFinding;
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
 * Evaluates a developer's comment disputing a PullLens finding.
 * If the argument is convincing the finding is marked as resolved and,
 * when no critical/high blockers remain, the GitHub review is dismissed.
 */
class DisputePullRequestFinding implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(public readonly string $commentId) {}

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
            'finding.review',
        ])->findOrFail($this->commentId);

        if ($comment->is_pull_lens) {
            return;
        }

        $finding = $comment->finding;

        if (! $finding || $finding->resolved_at !== null) {
            return;
        }

        $pullRequest = $comment->pullRequest;
        $repository = $pullRequest->repository;

        $resolved = $configResolver->forRepository($repository);
        $agent = new FindingDisputeAgent;

        $findingData = [
            'dedupe_key' => $finding->dedupe_key,
            'title' => $finding->title,
            'severity' => $finding->severity?->value ?? $finding->severity,
            'category' => $finding->category?->value ?? $finding->category,
            'file' => $finding->file,
            'line' => $finding->line,
            'explanation' => $finding->explanation,
            'suggested_fix' => $finding->suggested_fix,
        ];

        $thread = $this->buildThread($comment);

        $result = $agent->prompt(
            $agent->buildPrompt($findingData, $thread),
            provider: $resolved->configName,
            model: $resolved->model,
        );

        $accepted = (bool) data_get($result, 'accepted', false);
        $reply = (string) data_get($result, 'reply', '');
        $resolutionType = (string) data_get($result, 'resolution_type', 'false_positive');

        $app = GitProviderApp::where('provider', 'github')->first();
        $poster = $repository->account;

        if ($app?->private_key && $repository->installation_id) {
            $token = $api->installationToken($app, (int) $repository->installation_id);
            if ($token !== '') {
                $poster = $token;
            }
        }

        [$owner, $name] = explode('/', $repository->full_name, 2);

        if ($accepted) {
            $finding->update([
                'resolved_at' => now(),
                'resolution_type' => $resolutionType,
            ]);

            Log::info('dispute.finding_resolved', [
                'finding_id' => $finding->id,
                'resolution_type' => $resolutionType,
                'comment_id' => $this->commentId,
            ]);

            $remainingBlockers = PullRequestReviewFinding::where('pull_request_id', $pullRequest->id)
                ->whereIn('severity', ['critical', 'high'])
                ->whereNull('resolved_at')
                ->count();

            if ($remainingBlockers === 0) {
                $this->maybeDismissReview($finding, $api, $poster, $owner, $name, $pullRequest->number);
            }
        }

        $this->postReply($comment, $reply, $api, $poster, $owner, $name, $pullRequest->number);

        PullRequestEvent::create([
            'pull_request_id' => $pullRequest->id,
            'git_repository_id' => $repository->id,
            'event_type' => 'pull_lens_dispute_evaluated',
            'actor_type' => 'bot',
            'payload' => [
                'finding_id' => $finding->id,
                'accepted' => $accepted,
                'resolution_type' => $accepted ? $resolutionType : null,
                'comment_id' => $this->commentId,
            ],
            'occurred_at' => now(),
        ]);
    }

    /**
     * Dismiss the GitHub review when no blockers remain, allowing the PR to be merged.
     */
    private function maybeDismissReview(
        PullRequestReviewFinding $finding,
        GitHubApiClient $api,
        GitAccount|string $poster,
        string $owner,
        string $name,
        int $prNumber,
    ): void {
        $review = $finding->review;

        if (! $review?->provider_review_id) {
            return;
        }

        try {
            $api->dismissReview(
                $poster,
                $owner,
                $name,
                $prNumber,
                (int) $review->provider_review_id,
                'All blocking findings have been resolved or accepted — PullLens review dismissed.',
            );

            Log::info('dispute.review_dismissed', [
                'review_id' => $review->id,
                'provider_review_id' => $review->provider_review_id,
            ]);
        } catch (Throwable $e) {
            Log::warning('dispute.review_dismiss_failed', [
                'review_id' => $review->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Post the AI's decision as a reply in the finding's comment thread.
     */
    private function postReply(
        PullRequestComment $comment,
        string $reply,
        GitHubApiClient $api,
        GitAccount|string $poster,
        string $owner,
        string $name,
        int $prNumber,
    ): void {
        if ($reply === '' || ! $comment->provider_comment_id) {
            return;
        }

        $body = implode("\n\n", array_filter([
            "@{$comment->author_login} {$reply}",
            '<!-- pullens -->',
        ]));

        try {
            $api->replyToReviewComment(
                $poster,
                $owner,
                $name,
                $prNumber,
                (int) $comment->provider_comment_id,
                $body,
            );
        } catch (Throwable $e) {
            Log::warning('dispute.reply_failed', [
                'comment_id' => $this->commentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the comment thread for the agent: original finding comment + the developer's argument.
     *
     * @return string[]
     */
    private function buildThread(PullRequestComment $comment): array
    {
        $thread = [];

        if ($comment->provider_in_reply_to_id) {
            $parent = PullRequestComment::where('provider_comment_id', $comment->provider_in_reply_to_id)->first();
            if ($parent) {
                $thread[] = "[{$parent->author_login}]: ".trim(str_replace('<!-- pullens -->', '', (string) $parent->body));
            }
        }

        $thread[] = "[{$comment->author_login}]: {$comment->body}";

        return $thread;
    }
}
