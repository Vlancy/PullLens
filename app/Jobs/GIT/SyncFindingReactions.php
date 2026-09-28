<?php

namespace App\Jobs\GIT;

use App\Enums\GIT\FindingSource;
use App\Models\GIT\PullRequestReviewFinding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatcher: fans out one SyncFindingReaction job per eligible finding so
 * each API call runs in its own isolated job with its own timeout, instead of
 * all calls blocking a single long-running job.
 */
class SyncFindingReactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * Execute the sync finding reactions job.
     */
    public function handle(): void
    {
        // Helpfulness measures the AI review; scanner findings are facts, not opinions.
        PullRequestReviewFinding::query()
            ->where('source', FindingSource::Ai->value)
            ->where('is_posted', true)
            ->whereNotNull('provider_comment_id')
            ->whereNull('is_helpful')
            ->where('created_at', '>=', now()->subDays(30))
            ->select('id')
            ->chunkById(100, function ($findings): void {
                foreach ($findings as $finding) {
                    SyncFindingReaction::dispatch($finding->id);
                }
            });
    }
}
