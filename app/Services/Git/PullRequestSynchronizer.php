<?php

namespace App\Services\Git;

use App\Enums\GIT\PullRequestState;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestEvent;
use App\Services\Tasks\TaskRecorder;
use Illuminate\Support\Carbon;

class PullRequestSynchronizer
{
    /**
     * Inject the task recorder this class delegates to.
     */
    public function __construct(private readonly TaskRecorder $tasks) {}

    /**
     * Upsert a pull request record from a webhook payload and record the event.
     *
     * The payload comes from the GitHub webhook's `pull_request` key.
     * We never trust state derived from external inputs for decisions - we only persist it.
     *
     * @param  array<string, mixed>  $prPayload  The `pull_request` object from the GitHub webhook.
     */
    public function syncFromPayload(GitRepository $repository, array $prPayload, string $action): PullRequest
    {
        $state = $this->stateFromPayload($prPayload);
        $authorType = strtolower((string) data_get($prPayload, 'user.type', 'user'));

        $attributes = [
            'title' => (string) data_get($prPayload, 'title'),
            'description' => data_get($prPayload, 'body'),
            'state' => $state->value,
            'is_draft' => (bool) data_get($prPayload, 'draft', false),
            'author_login' => (string) data_get($prPayload, 'user.login'),
            'author_name' => data_get($prPayload, 'user.name'),
            'author_avatar_url' => data_get($prPayload, 'user.avatar_url'),
            'source_branch' => (string) data_get($prPayload, 'head.ref'),
            'target_branch' => (string) data_get($prPayload, 'base.ref'),
            'web_url' => data_get($prPayload, 'html_url'),
            'additions' => (int) data_get($prPayload, 'additions', 0),
            'deletions' => (int) data_get($prPayload, 'deletions', 0),
            'changed_files_count' => (int) data_get($prPayload, 'changed_files', 0),
            'commits_count' => (int) data_get($prPayload, 'commits', 0),
            'labels' => $this->labelsFromPayload($prPayload),
            'opened_at' => Carbon::parse((string) data_get($prPayload, 'created_at')),
            'closed_at' => filled(data_get($prPayload, 'closed_at'))
                ? Carbon::parse((string) data_get($prPayload, 'closed_at'))
                : null,
            'merged_at' => filled(data_get($prPayload, 'merged_at'))
                ? Carbon::parse((string) data_get($prPayload, 'merged_at'))
                : null,
            'merged_by_login' => data_get($prPayload, 'merged_by.login'),
            'merge_commit_sha' => data_get($prPayload, 'merge_commit_sha'),
            'head_sha' => data_get($prPayload, 'head.sha'),
            'provider_updated_at' => Carbon::parse((string) data_get($prPayload, 'updated_at')),
            'last_synced_at' => now(),
        ];

        $pullRequest = PullRequest::updateOrCreate(
            [
                'git_repository_id' => $repository->id,
                'provider_pr_id' => (int) data_get($prPayload, 'id'),
            ],
            $attributes + [
                'number' => (int) data_get($prPayload, 'number'),
            ],
        );

        PullRequestEvent::create([
            'pull_request_id' => $pullRequest->id,
            'git_repository_id' => $repository->id,
            'event_type' => $action,
            'actor_login' => data_get($prPayload, 'user.login'),
            'actor_type' => $authorType,
            'occurred_at' => now(),
        ]);

        // Tasks are usually extracted while the PR is still open, so their delivery
        // date is only known once it merges. Stamping it here covers every sync path.
        if ($pullRequest->wasChanged('merged_at') && $pullRequest->merged_at !== null) {
            $this->tasks->markDelivered($pullRequest);
        }

        return $pullRequest;
    }

    /**
     * Derive our PullRequestState from the GitHub payload fields.
     */
    private function stateFromPayload(array $pr): PullRequestState
    {
        if ((bool) data_get($pr, 'draft', false)) {
            return PullRequestState::Draft;
        }

        if (data_get($pr, 'state') === 'closed') {
            return filled(data_get($pr, 'merged_at')) || (bool) data_get($pr, 'merged', false)
                ? PullRequestState::Merged
                : PullRequestState::Closed;
        }

        return PullRequestState::Open;
    }

    /**
     * Extract label names from the GitHub PR payload.
     *
     * @return array<int, string>
     */
    private function labelsFromPayload(array $pr): array
    {
        return collect(data_get($pr, 'labels', []))
            ->pluck('name')
            ->values()
            ->all();
    }
}
