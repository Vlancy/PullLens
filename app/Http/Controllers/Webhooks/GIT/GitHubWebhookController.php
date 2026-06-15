<?php

namespace App\Http\Controllers\Webhooks\GIT;

use App\Enums\GIT\PullRequestCommentType;
use App\Http\Controllers\Controller;
use App\Jobs\GIT\CheckFindingResolutions;
use App\Jobs\GIT\DisputePullRequestFinding;
use App\Jobs\GIT\ReplyToPullRequestComment;
use App\Jobs\GIT\ReviewPullRequest;
use App\Jobs\GIT\SyncPullRequestDetails;
use App\Models\GIT\GitProviderApp;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestComment;
use App\Models\GIT\PullRequestEvent;
use App\Repositories\Contracts\GIT\GitRepositoryRepositoryInterface;
use App\Services\Git\GitHubApiClient;
use App\Services\Git\GitHubWebhookVerifier;
use App\Services\Git\PullRequestSynchronizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GitHubWebhookController extends Controller
{
    /**
     * Events this controller actively processes.
     */
    private const HANDLED_EVENTS = ['pull_request', 'pull_request_review_comment', 'issue_comment', 'ping'];

    /**
     * PR actions that trigger a data sync + possible review.
     */
    private const PR_SYNC_ACTIONS = [
        'opened', 'synchronize', 'closed', 'reopened',
        'ready_for_review', 'converted_to_draft', 'edited', 'labeled', 'unlabeled',
    ];

    public function __invoke(
        Request $request,
        GitHubWebhookVerifier $verifier,
        PullRequestSynchronizer $synchronizer,
        GitRepositoryRepositoryInterface $repositories,
        GitHubApiClient $api,
    ): JsonResponse {
        $event = (string) $request->header('X-GitHub-Event', '');

        if ($event === 'ping') {
            return response()->json(['ok' => true]);
        }

        if (! in_array($event, self::HANDLED_EVENTS, true)) {
            return response()->json(['ok' => true]);
        }

        // Verify HMAC signature using the GitHub App's webhook secret when configured.
        $app = GitProviderApp::where('provider', 'github')->first();

        if ($app?->webhook_secret) {
            $rawBody = $request->getContent();
            $signature = (string) $request->header('X-Hub-Signature-256', '');

            if (! $verifier->verify($rawBody, $signature, $app->webhook_secret)) {
                Log::warning('webhook.signature_mismatch', [
                    'event' => $event,
                    'body_bytes' => strlen($rawBody),
                    'sig_empty' => $signature === '',
                    'sig_prefix' => substr($signature, 0, 20),
                ]);

                abort(403, 'Invalid webhook signature.');
            }
        }

        $payload = (array) $request->json()->all();
        $action = (string) ($payload['action'] ?? '');
        $providerRepoId = (int) data_get($payload, 'repository.id');

        $repository = $repositories->findByProviderRepoId('github', $providerRepoId);

        // Return 200 for untracked repositories to prevent GitHub retries.
        if (! $repository) {
            return response()->json(['ok' => true]);
        }

        match ($event) {
            'pull_request' => $this->handlePullRequest($repository, $payload, $action, $synchronizer),
            'pull_request_review_comment' => $this->handleReviewComment($repository, $payload, $action, $api, $synchronizer),
            'issue_comment' => $this->handleIssueComment($repository, $payload, $action, $api, $synchronizer),
            default => null,
        };

        return response()->json(['ok' => true]);
    }

    /**
     * Sync the PR record and dispatch background jobs for data enrichment and AI review.
     */
    private function handlePullRequest(
        GitRepository $repository,
        array $payload,
        string $action,
        PullRequestSynchronizer $synchronizer,
    ): void {
        if (! in_array($action, self::PR_SYNC_ACTIONS, true)) {
            return;
        }

        $prPayload = (array) ($payload['pull_request'] ?? []);

        if (empty($prPayload)) {
            return;
        }

        $pullRequest = $synchronizer->syncFromPayload($repository, $prPayload, $action);

        SyncPullRequestDetails::dispatch($pullRequest->id);

        // On new commits, resolve findings whose files were changed before the new review runs.
        if ($action === 'synchronize') {
            $headSha = (string) data_get($prPayload, 'head.sha', '');

            if ($headSha !== '') {
                CheckFindingResolutions::dispatch($pullRequest->id, $headSha);
            }
        }

        if ($this->shouldTriggerReview($repository, $action, $pullRequest->target_branch)) {
            ReviewPullRequest::dispatch($pullRequest->id);
        }
    }

    /**
     * Handle inline review comments on specific PR lines.
     */
    private function handleReviewComment(
        GitRepository $repository,
        array $payload,
        string $action,
        GitHubApiClient $api,
        PullRequestSynchronizer $synchronizer,
    ): void {
        if ($action !== 'created') {
            return;
        }

        $prPayload = (array) ($payload['pull_request'] ?? []);
        $commentPayload = (array) ($payload['comment'] ?? []);

        if (empty($commentPayload) || empty($prPayload)) {
            Log::warning('webhook.review_comment.skipped', [
                'repo' => $repository->full_name,
                'reason' => 'empty comment or pull_request payload',
            ]);

            return;
        }

        $prNumber = (int) data_get($prPayload, 'number');

        $pullRequest = PullRequest::where('git_repository_id', $repository->id)
            ->where('number', $prNumber)
            ->first();

        if (! $pullRequest) {
            $pullRequest = $this->syncPullRequestOnDemand($repository, $prNumber, $api, $synchronizer);
        }

        if (! $pullRequest) {
            Log::warning('webhook.review_comment.skipped', [
                'repo' => $repository->full_name,
                'reason' => 'pull_request not found and on-demand sync failed',
                'pr' => $prNumber,
            ]);

            return;
        }

        $comment = $this->persistComment($pullRequest, $commentPayload, PullRequestCommentType::ReviewComment);

        PullRequestEvent::create([
            'pull_request_id' => $pullRequest->id,
            'git_repository_id' => $repository->id,
            'event_type' => 'comment_added',
            'actor_login' => data_get($commentPayload, 'user.login'),
            'actor_type' => strtolower((string) data_get($commentPayload, 'user.type', '')) === 'bot' ? 'bot' : 'user',
            'occurred_at' => now(),
        ]);

        if ($comment->is_pull_lens) {
            Log::info('webhook.review_comment.skipped', [
                'repo' => $repository->full_name,
                'reason' => 'comment is from pull_lens bot — skipping to avoid reply loop',
                'pr' => $prNumber,
            ]);

            return;
        }

        if (! $repository->allow_comment_replies) {
            Log::info('webhook.review_comment.skipped', [
                'repo' => $repository->full_name,
                'reason' => 'allow_comment_replies is disabled for this repository',
                'pr' => $prNumber,
            ]);

            return;
        }

        // Route to dispute evaluator when the comment targets a specific finding;
        // otherwise fall through to the general reply agent.
        if ($comment->pull_request_review_finding_id !== null) {
            Log::info('webhook.review_comment.dispatching_dispute', [
                'repo' => $repository->full_name,
                'pr' => $prNumber,
                'comment_id' => $comment->id,
                'finding_id' => $comment->pull_request_review_finding_id,
                'author' => data_get($commentPayload, 'user.login'),
            ]);

            DisputePullRequestFinding::dispatch($comment->id);

            return;
        }

        Log::info('webhook.review_comment.dispatching_reply', [
            'repo' => $repository->full_name,
            'pr' => $prNumber,
            'comment_id' => $comment->id,
            'author' => data_get($commentPayload, 'user.login'),
        ]);

        ReplyToPullRequestComment::dispatch($comment->id);
    }

    /**
     * Handle general issue comments — only process those on pull requests.
     */
    private function handleIssueComment(
        GitRepository $repository,
        array $payload,
        string $action,
        GitHubApiClient $api,
        PullRequestSynchronizer $synchronizer,
    ): void {
        if ($action !== 'created') {
            return;
        }

        // issue_comment fires for both issues and PRs — skip pure issues.
        if (! data_get($payload, 'issue.pull_request')) {
            return;
        }

        $prNumber = (int) data_get($payload, 'issue.number');
        $commentPayload = (array) ($payload['comment'] ?? []);

        if (empty($commentPayload) || $prNumber === 0) {
            Log::warning('webhook.issue_comment.skipped', [
                'repo' => $repository->full_name,
                'reason' => 'empty comment payload or pr_number=0',
                'pr' => $prNumber,
            ]);

            return;
        }

        $pullRequest = PullRequest::where('git_repository_id', $repository->id)
            ->where('number', $prNumber)
            ->first();

        if (! $pullRequest) {
            $pullRequest = $this->syncPullRequestOnDemand($repository, $prNumber, $api, $synchronizer);
        }

        if (! $pullRequest) {
            Log::warning('webhook.issue_comment.skipped', [
                'repo' => $repository->full_name,
                'reason' => 'pull_request not found and on-demand sync failed',
                'pr' => $prNumber,
            ]);

            return;
        }

        $comment = $this->persistComment($pullRequest, $commentPayload, PullRequestCommentType::IssueComment);

        PullRequestEvent::create([
            'pull_request_id' => $pullRequest->id,
            'git_repository_id' => $repository->id,
            'event_type' => 'comment_added',
            'actor_login' => data_get($commentPayload, 'user.login'),
            'actor_type' => strtolower((string) data_get($commentPayload, 'user.type', '')) === 'bot' ? 'bot' : 'user',
            'occurred_at' => now(),
        ]);

        if ($comment->is_pull_lens) {
            Log::info('webhook.issue_comment.skipped', [
                'repo' => $repository->full_name,
                'reason' => 'comment is from pull_lens bot — skipping to avoid reply loop',
                'pr' => $prNumber,
            ]);

            return;
        }

        if (! $repository->allow_comment_replies) {
            Log::info('webhook.issue_comment.skipped', [
                'repo' => $repository->full_name,
                'reason' => 'allow_comment_replies is disabled for this repository',
                'pr' => $prNumber,
            ]);

            return;
        }

        Log::info('webhook.issue_comment.dispatching_reply', [
            'repo' => $repository->full_name,
            'pr' => $prNumber,
            'comment_id' => $comment->id,
            'author' => data_get($commentPayload, 'user.login'),
        ]);

        ReplyToPullRequestComment::dispatch($comment->id);
    }

    /**
     * Upsert a comment record, linking it to a review finding when possible.
     */
    private function persistComment(PullRequest $pullRequest, array $commentPayload, PullRequestCommentType $commentType): PullRequestComment
    {
        $authorLogin = (string) data_get($commentPayload, 'user.login', '');
        $authorType = strtolower((string) data_get($commentPayload, 'user.type', 'user'));

        // Detect our own comments to prevent reply loops.
        // Primary check: invisible marker appended to every PullLens-posted comment body.
        // Fallback: bot account with "lens" in the login (GitHub App installation token).
        $body = (string) data_get($commentPayload, 'body', '');
        $isPullLens = str_contains($body, '<!-- pullens -->')
            || ($authorType === 'bot' && str_contains(strtolower($authorLogin), 'lens'));

        // If this is a reply, try to link it to the finding whose comment was the thread root.
        $inReplyToId = data_get($commentPayload, 'in_reply_to_id');
        $findingId = null;

        if ($inReplyToId) {
            $findingId = $pullRequest->reviews()
                ->with('findings')
                ->get()
                ->flatMap(fn ($r) => $r->findings)
                ->firstWhere('provider_comment_id', $inReplyToId)
                ?->id;
        }

        $searchKey = [
            'pull_request_id' => $pullRequest->id,
            'provider_comment_id' => (int) data_get($commentPayload, 'id'),
        ];

        try {
            return PullRequestComment::updateOrCreate(
                $searchKey,
                [
                    'pull_request_review_finding_id' => $findingId,
                    'provider_in_reply_to_id' => $inReplyToId,
                    'comment_type' => $commentType,
                    'author_login' => $authorLogin,
                    'author_type' => $authorType === 'bot' ? 'bot' : 'user',
                    'body' => (string) data_get($commentPayload, 'body', ''),
                    'is_pull_lens' => $isPullLens,
                    'provider_created_at' => data_get($commentPayload, 'created_at', now()),
                    'provider_updated_at' => data_get($commentPayload, 'updated_at'),
                ],
            );
        } catch (\Illuminate\Database\QueryException $e) {
            // GitHub delivers the same webhook from two IPs simultaneously. Both requests
            // can race through the SELECT in updateOrCreate and both attempt an INSERT,
            // hitting the unique(pull_request_id, provider_comment_id) constraint. Fetch
            // the record the other request already committed instead of surfacing a 500.
            if (($e->errorInfo[0] ?? null) === '23505') {
                Log::info('webhook.comment.duplicate_delivery_race', [
                    'pull_request_id' => $pullRequest->id,
                    'provider_comment_id' => $searchKey['provider_comment_id'],
                ]);

                return PullRequestComment::where($searchKey)->firstOrFail();
            }
            throw $e;
        }
    }

    /**
     * Fetch a PR from GitHub and sync it into the database when we receive a
     * comment for a PR that was opened before PullLens was installed.
     */
    private function syncPullRequestOnDemand(
        GitRepository $repository,
        int $prNumber,
        GitHubApiClient $api,
        PullRequestSynchronizer $synchronizer,
    ): ?PullRequest {
        try {
            $repository->loadMissing('account');

            [$owner, $name] = explode('/', $repository->full_name, 2);

            $prPayload = $api->pullRequest($repository->account, $owner, $name, $prNumber);

            if (empty($prPayload)) {
                return null;
            }

            $pullRequest = $synchronizer->syncFromPayload($repository, $prPayload, 'opened');

            SyncPullRequestDetails::dispatch($pullRequest->id);

            Log::info('webhook.pull_request.synced_on_demand', [
                'repo' => $repository->full_name,
                'pr' => $prNumber,
            ]);

            return $pullRequest;
        } catch (\Throwable $e) {
            Log::warning('webhook.pull_request.on_demand_sync_failed', [
                'repo' => $repository->full_name,
                'pr' => $prNumber,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Determine whether this PR action should trigger an AI review job.
     *
     * Respects tracked_branches: if the list is non-empty, only PRs targeting
     * one of those branches are reviewed.
     */
    private function shouldTriggerReview(GitRepository $repository, string $action, ?string $targetBranch): bool
    {
        if (! $repository->reviews_enabled) {
            return false;
        }

        $tracked = (array) ($repository->tracked_branches ?? []);

        if (! empty($tracked) && ! in_array($targetBranch, $tracked, true)) {
            return false;
        }

        return match ($action) {
            'opened', 'ready_for_review' => $repository->auto_review_on_open,
            'synchronize' => true,
            default => false,
        };
    }
}
