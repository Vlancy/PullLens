<?php

namespace App\Support\Presenters\GIT;

use App\Models\GIT\PullRequestTask;
use App\Models\GIT\PullRequestTaskLink;
use App\Support\Queries\GIT\ReleasePaths;
use App\Support\Tasks\FeatureScope;
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
     * Serialize for the front end.
     *
     * Pass the task's release path (from {@see ReleasePaths}) so a merged pull request
     * shows where its work lives now, not only the branch it first merged into.
     *
     * @param  array{path: array<int, string>, promoted_by: array{number: int|null, url: string|null}|null}|null  $releasePath
     * @return array<string, mixed>
     */
    public static function toArray(PullRequestTask $task, ?array $releasePath = null): array
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
            // The scope a feature for this task must belong to, so the move dialog
            // only offers features the task can actually join.
            'scope_key' => $task->repository === null ? null : FeatureScope::forRepository($task->repository)->key(),
            'feature' => $task->relationLoaded('feature') && $task->feature !== null ? [
                'id' => $task->feature->id,
                'name' => $task->feature->name,
            ] : null,
            'feature_source' => $task->feature_source?->value,
            'pull_request' => $task->pullRequest === null ? null : [
                'number' => $task->pullRequest->number,
                'title' => $task->pullRequest->title,
                'web_url' => $task->pullRequest->web_url,
                'state' => $task->pullRequest->state?->value,
                'source_branch' => $task->pullRequest->source_branch,
                'target_branch' => $task->pullRequest->target_branch,
                'merged_at' => $task->pullRequest->merged_at?->toISOString(),
                'path' => $releasePath['path'] ?? [],
                'promoted_by' => $releasePath['promoted_by'] ?? null,
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
     * Serialize a set of records for the front end.
     *
     * @param  iterable<int, PullRequestTask>  $tasks
     * @return array<int, array<string, mixed>>
     */
    public static function collection(iterable $tasks): array
    {
        $tasks = Collection::make($tasks)->values();

        // Follow each merged pull request through later release merges in one query,
        // so a task's branch chip agrees with its feature's.
        $paths = ReleasePaths::for($tasks
            ->filter(static fn (PullRequestTask $task): bool => $task->pullRequest !== null)
            ->map(static fn (PullRequestTask $task): object => $task->pullRequest));

        return $tasks
            ->map(static fn (PullRequestTask $task, int $index): array => self::toArray($task, $paths[$index] ?? null))
            ->values()
            ->all();
    }

    /**
     * Serialize one side of a task relationship.
     *
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
