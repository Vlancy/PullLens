<?php

namespace App\Support\Presenters\GIT;

use App\Models\GIT\PullRequestTask;
use App\Models\GIT\PullRequestTaskLink;
use Illuminate\Support\Collection;

/**
 * Serializes tasks, with their history, for the tasks board.
 *
 * Links are rendered from both directions: what this task did to earlier work, and
 * what later work did to it. The second is the one that answers "did this come back".
 */
final class TaskPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(PullRequestTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'type' => $task->type?->value,
            'type_label' => $task->type?->label(),
            'status' => $task->status?->value,
            'status_label' => $task->status?->label(),
            'first_time_right' => $task->isFirstTimeRight(),
            'estimated_hours' => $task->estimated_hours,
            'rework_count' => $task->rework_count,
            'revision_count' => $task->revision_count,
            'files' => $task->files ?? [],
            'delivered_at' => $task->delivered_at?->toISOString(),
            'first_delivered_at' => $task->first_delivered_at?->toISOString(),
            'reverted_at' => $task->reverted_at?->toISOString(),
            'author' => [
                'login' => $task->author_login,
                'name' => $task->author_name,
                'avatar_url' => $task->author_avatar_url,
            ],
            'external' => $task->external_key === null ? null : [
                'provider' => $task->external_provider?->value,
                'provider_label' => $task->external_provider?->label(),
                'key' => $task->external_key,
                'url' => $task->external_url,
            ],
            'repository' => $task->repository === null ? null : [
                'id' => $task->repository->id,
                'full_name' => $task->repository->full_name,
            ],
            'pull_request' => $task->pullRequest === null ? null : [
                'number' => $task->pullRequest->number,
                'title' => $task->pullRequest->title,
                'web_url' => $task->pullRequest->web_url,
                'state' => $task->pullRequest->state?->value,
            ],
            // What this task did to earlier work.
            'links' => $task->relationLoaded('links')
                ? self::links($task->links, outbound: true)
                : [],
            // What later work did to this task.
            'inbound_links' => $task->relationLoaded('inboundLinks')
                ? self::links($task->inboundLinks, outbound: false)
                : [],
        ];
    }

    /**
     * @param  iterable<int, PullRequestTask>  $tasks
     * @return array<int, array<string, mixed>>
     */
    public static function collection(iterable $tasks): array
    {
        return Collection::make($tasks)
            ->map(static fn (PullRequestTask $task): array => self::toArray($task))
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int, PullRequestTaskLink>  $links
     * @return array<int, array<string, mixed>>
     */
    private static function links(iterable $links, bool $outbound): array
    {
        return Collection::make($links)
            ->map(static function (PullRequestTaskLink $link) use ($outbound): ?array {
                $other = $outbound ? $link->relatedTask : $link->task;

                if ($other === null) {
                    return null;
                }

                return [
                    'id' => $other->id,
                    'title' => $other->title,
                    'type' => $other->type?->value,
                    'status' => $other->status?->value,
                    // Outbound reads "fixes"; inbound reads "fixed by".
                    'relation' => $link->relation?->value,
                    'relation_label' => $outbound
                        ? $link->relation?->label()
                        : $link->relation?->inverseLabel(),
                    'reason' => $link->reason,
                    'confidence' => $link->confidence,
                    'source' => $link->source,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
