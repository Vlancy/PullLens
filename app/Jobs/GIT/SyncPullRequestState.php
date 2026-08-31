<?php

namespace App\Jobs\GIT;

use App\Models\GIT\GitProviderApp;
use App\Models\GIT\PullRequest;
use App\Services\Git\GitHubApiClient;
use App\Services\Git\PullRequestSynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncPullRequestState implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A single API call to re-read the pull request's state. */
    public int $timeout = 60;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * Inject the string this class delegates to.
     */
    public function __construct(public readonly string $pullRequestId) {}

    /**
     * Execute the sync pull request state job.
     */
    public function handle(GitHubApiClient $api, PullRequestSynchronizer $synchronizer): void
    {
        $pullRequest = PullRequest::with(['repository.account'])->findOrFail($this->pullRequestId);
        $repository = $pullRequest->repository;
        $account = $repository->account;
        [$owner, $name] = explode('/', $repository->full_name, 2);

        $caller = $account;
        $app = GitProviderApp::where('provider', 'github')->first();

        if ($app?->private_key && $repository->installation_id) {
            $token = $api->installationToken($app, (int) $repository->installation_id);
            if ($token !== '') {
                $caller = $token;
            }
        }

        try {
            $prPayload = $api->pullRequest($caller, $owner, $name, $pullRequest->number);
        } catch (\Throwable $e) {
            Log::warning('sync_pr_state.fetch_failed', [
                'pull_request_id' => $pullRequest->id,
                'pr' => $pullRequest->number,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (empty($prPayload)) {
            return;
        }

        $oldState = $pullRequest->state?->value ?? 'unknown';
        $synchronizer->syncFromPayload($repository, $prPayload, 'sync');
        $pullRequest->refresh();
        $newState = $pullRequest->state?->value ?? 'unknown';

        if ($oldState !== $newState) {
            Log::info('sync_pr_state.updated', [
                'pull_request_id' => $pullRequest->id,
                'pr' => $pullRequest->number,
                'old_state' => $oldState,
                'new_state' => $newState,
            ]);
        }
    }
}
