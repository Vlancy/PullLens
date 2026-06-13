<?php

namespace App\Jobs\GIT;

use App\Models\GIT\GitProviderApp;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestEvent;
use App\Services\Git\GitHubApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MergePullRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

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

        // Prefer the GitHub App installation token so the merge appears as the
        // bot account, consistent with how reviews are posted. Fall back to the
        // connected OAuth account if no app or installation is configured.
        $poster = $account;
        $app = GitProviderApp::where('provider', 'github')->first();
        if ($app?->private_key && $repository->installation_id) {
            $token = $api->installationToken($app, (int) $repository->installation_id);
            if ($token !== '') {
                $poster = $token;
            } else {
                Log::warning('merge.installation_token_empty', [
                    'pull_request_id' => $pullRequest->id,
                    'installation_id' => $repository->installation_id,
                ]);
            }
        }

        $api->mergePullRequest($poster, $owner, $name, $pullRequest->number, $mergeMethod);

        PullRequestEvent::create([
            'pull_request_id' => $pullRequest->id,
            'git_repository_id' => $repository->id,
            'event_type' => 'pull_lens_auto_merged',
            'actor_type' => 'bot',
            'payload' => ['merge_method' => $mergeMethod],
            'occurred_at' => now(),
        ]);
    }
}
