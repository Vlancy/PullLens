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

/**
 * Polls GitHub for 👍 / 👎 reactions on PullLens inline finding comments and
 * records the net helpfulness signal as `is_helpful` on the finding record.
 *
 * Schedule daily or hourly via the application scheduler.
 * Only processes findings posted in the last 30 days that have no signal yet.
 */
class SyncFindingReactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(GitHubApiClient $api): void
    {
        $findings = PullRequestReviewFinding::query()
            ->where('is_posted', true)
            ->whereNotNull('provider_comment_id')
            ->whereNull('is_helpful')
            ->where('created_at', '>=', now()->subDays(30))
            ->with(['pullRequest.repository.account'])
            ->get();

        foreach ($findings as $finding) {
            $repository = $finding->pullRequest?->repository;
            $account = $repository?->account;

            if (! $account || ! $repository) {
                continue;
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
                    continue;
                }

                $finding->update(['is_helpful' => $thumbsUp >= $thumbsDown]);
            } catch (Throwable $e) {
                Log::warning('finding_reactions.sync_failed', [
                    'finding_id' => $finding->id,
                    'comment_id' => $finding->provider_comment_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
