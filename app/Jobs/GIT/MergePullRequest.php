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

class MergePullRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Inject the string this class delegates to.
     */
    public function __construct(
        public readonly string $pullRequestId,
    ) {}

    /**
     * Execute the merge pull request job.
     */
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

        // Prefer the installation token (contents:write in the app manifest) so
        // the merge appears as the bot. Fall back to the connected OAuth account
        // for installations that pre-date the contents:write permission update.
        $actor = $account;
        $app = GitProviderApp::where('provider', 'github')->first();
        if ($app?->private_key && $repository->installation_id) {
            $token = $api->installationToken($app, (int) $repository->installation_id);
            if ($token !== '') {
                $actor = $token;
            }
        }

        $api->mergePullRequest($actor, $owner, $name, $pullRequest->number, $mergeMethod);

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
