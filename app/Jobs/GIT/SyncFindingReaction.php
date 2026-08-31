<?php

namespace App\Jobs\GIT;

use App\Models\GIT\PullRequestReviewFinding;
use App\Services\Git\GitHubApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncFindingReaction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    /**
     * Inject the string this class delegates to.
     */
    public function __construct(public readonly string $findingId) {}

    /**
     * Execute the sync finding reaction job.
     */
    public function handle(GitHubApiClient $api): void
    {
        $finding = PullRequestReviewFinding::with(['pullRequest.repository.account'])
            ->find($this->findingId);

        if (! $finding) {
            return;
        }

        $repository = $finding->pullRequest?->repository;
        $account = $repository?->account;

        if (! $account || ! $repository) {
            return;
        }

        [$owner, $name] = explode('/', $repository->full_name, 2);

        try {
            $reactions = $api->getReviewCommentReactions(
                $account,
                $owner,
                $name,
                (int) $finding->provider_comment_id,
            );

            $thumbsUp = collect($reactions)->where('content', '+1')->count();
            $thumbsDown = collect($reactions)->where('content', '-1')->count();

            if ($thumbsUp === 0 && $thumbsDown === 0) {
                return;
            }

            $finding->update(['is_helpful' => $thumbsUp >= $thumbsDown]);
        } catch (Throwable $e) {
            Log::warning('finding_reactions.sync_failed', [
                'finding_id' => $finding->id,
                'comment_id' => $finding->provider_comment_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
