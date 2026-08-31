<?php

namespace App\Services\Git\Webhooks;

use App\Jobs\GIT\SyncPullRequestDetails;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Services\Git\GitHubApiClient;
use App\Services\Git\PullRequestSynchronizer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves the local PullRequest record for a webhook that references a PR by number.
 *
 * Comments can arrive for PRs opened before PullLens was installed, so a miss falls
 * back to fetching the PR from GitHub and syncing it on demand. A failed fetch is
 * logged and returns null — the delivery is still acknowledged, because retrying it
 * would not change the outcome.
 */
class PullRequestResolver
{
    /**
     * Inject the git hub api client and pull request synchronizer this class delegates to.
     */
    public function __construct(
        private readonly GitHubApiClient $api,
        private readonly PullRequestSynchronizer $synchronizer,
    ) {}

    /**
     * Return the stored PR, importing it from GitHub if it is not tracked yet.
     */
    public function resolve(GitRepository $repository, int $number): ?PullRequest
    {
        $pullRequest = PullRequest::query()
            ->where('git_repository_id', $repository->id)
            ->where('number', $number)
            ->first();

        return $pullRequest ?? $this->importFromProvider($repository, $number);
    }

    /**
     * Fetch the PR from the provider API and persist it.
     */
    private function importFromProvider(GitRepository $repository, int $number): ?PullRequest
    {
        try {
            $repository->loadMissing('account');

            [$owner, $name] = explode('/', $repository->full_name, 2);

            $payload = $this->api->pullRequest($repository->account, $owner, $name, $number);

            if (empty($payload)) {
                return null;
            }

            $pullRequest = $this->synchronizer->syncFromPayload($repository, $payload, 'opened');

            SyncPullRequestDetails::dispatch($pullRequest->id);

            Log::info('webhook.pull_request.synced_on_demand', [
                'repo' => $repository->full_name,
                'pr' => $number,
            ]);

            return $pullRequest;
        } catch (Throwable $e) {
            Log::warning('webhook.pull_request.on_demand_sync_failed', [
                'repo' => $repository->full_name,
                'pr' => $number,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
