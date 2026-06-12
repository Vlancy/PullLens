<?php

namespace App\Jobs\GIT;

use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestEvent;
use App\Services\Git\GitHubApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class MergePullRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public readonly string $pullRequestId,
    ) {}

    public function handle(GitHubApiClient $api): void
    {
        $pullRequest = PullRequest::with('repository.account')->findOrFail($this->pullRequestId);
        $repository = $pullRequest->repository;
        $account = $repository->account;

        if (! $repository->auto_merge) {
            return;
        }

        [$owner, $name] = explode('/', $repository->full_name, 2);

        $mergeMethod = $repository->auto_merge_method?->value ?? 'merge';

        try {
            $api->mergePullRequest($account, $owner, $name, $pullRequest->number, $mergeMethod);

            PullRequestEvent::create([
                'pull_request_id' => $pullRequest->id,
                'git_repository_id' => $repository->id,
                'event_type' => 'pull_lens_auto_merged',
                'actor_type' => 'bot',
                'payload' => ['merge_method' => $mergeMethod],
                'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('merge.failed', [
                'pull_request_id' => $pullRequest->id,
                'merge_method' => $mergeMethod,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
