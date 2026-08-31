<?php

namespace App\Services\Git\Webhooks\Handlers;

use App\Enums\GIT\GitHubWebhookEvent;
use App\Jobs\GIT\RecordPushActivity;
use App\Models\GIT\GitRepository;
use App\Services\Git\Webhooks\Contracts\GitHubEventHandler;

/**
 * Records commits pushed outside of a pull request.
 *
 * Only runs for repositories with `record_all_activity` enabled — otherwise commit
 * history is tracked through PRs alone and this data would be noise.
 */
class PushEventHandler implements GitHubEventHandler
{
    /** Length of the "refs/heads/" prefix GitHub puts on branch refs. */
    private const REF_PREFIX = 'refs/heads/';

    /**
     * The event this handler is responsible for.
     */
    public function supports(): GitHubWebhookEvent
    {
        return GitHubWebhookEvent::Push;
    }

    /**
     * Execute the push event handler job.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(GitRepository $repository, array $payload): void
    {
        if (! $repository->record_all_activity) {
            return;
        }

        $commits = (array) data_get($payload, 'commits', []);

        if ($commits === []) {
            return;
        }

        RecordPushActivity::dispatch(
            $repository->id,
            $this->branchFromRef((string) data_get($payload, 'ref', '')),
            $commits,
        );
    }

    /**
     * Strip the ref prefix to get a plain branch name. Tag and other refs pass through
     * unchanged so they remain distinguishable downstream.
     */
    private function branchFromRef(string $ref): string
    {
        return str_starts_with($ref, self::REF_PREFIX)
            ? substr($ref, strlen(self::REF_PREFIX))
            : $ref;
    }
}
