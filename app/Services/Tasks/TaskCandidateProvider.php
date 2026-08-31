<?php

namespace App\Services\Tasks;

use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequestTask;

/**
 * Supplies the earlier tasks a review may link the current PR's work to.
 *
 * The model cannot relate a task to prior work it has never seen, so a compact list
 * of recent tasks from the same repository is handed to it as trusted context. The
 * list is capped and reduced to key plus title: enough to recognise a relationship,
 * small enough not to meaningfully change the cost of a review.
 */
class TaskCandidateProvider
{
    /** How many recent tasks the model is offered. */
    private const LIMIT = 40;

    /** How far back to look. Older work is rarely what a PR is fixing. */
    private const LOOKBACK_DAYS = 180;

    /**
     * Recent tasks in this repository, newest first.
     *
     * @return array<int, array{dedupe_key: string, title: string, type: string|null, delivered_at: string|null, pr: int|null}>
     */
    public function forRepository(GitRepository $repository, ?string $excludePullRequestId = null): array
    {
        return PullRequestTask::query()
            ->with('pullRequest:id,number')
            ->where('git_repository_id', $repository->id)
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            // The PR under review contributes its own tasks; a task must not link to
            // a sibling from the same PR, which would be describing one change twice.
            ->when($excludePullRequestId, fn ($q) => $q->where('pull_request_id', '!=', $excludePullRequestId))
            ->orderByDesc('delivered_at')
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(static fn (PullRequestTask $task): array => [
                'dedupe_key' => $task->dedupe_key,
                'title' => $task->title,
                'type' => $task->type?->value,
                'delivered_at' => $task->delivered_at?->toDateString(),
                'pr' => $task->pullRequest?->number,
            ])
            ->all();
    }
}
