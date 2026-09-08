<?php

namespace App\Services\Tasks;

use App\Enums\GIT\TaskRelation;
use App\Models\GIT\PullRequestTask;
use App\Models\GIT\PullRequestTaskLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Draws and maintains the relationships between tasks.
 *
 * The reviewer proposes links ("this fixes the caching task from PR #118"); this
 * service resolves those proposals against real tasks, records them, and recomputes
 * the lifecycle of whatever they point at.
 *
 * Two rules keep the graph trustworthy:
 *
 *   1. A link a person created or corrected is never replaced by an AI proposal.
 *      Reviews run repeatedly; human judgement must not be undone by the next one.
 *   2. A task can never link to itself or to a task in another repository, no matter
 *      what the model returns.
 */
class TaskLinker
{
    /** Ceiling on links one task may declare, so a malformed response cannot fan out. */
    private const MAX_LINKS_PER_TASK = 10;

    /**
     * Apply the links a review proposed for one task.
     *
     * @param  array<int, array<string, mixed>>  $links  The `links` array for this task.
     * @return int Number of links recorded.
     */
    public function apply(PullRequestTask $task, array $links): int
    {
        $resolved = $this->resolve($task, $links);

        // Which targets previously had a link from this task; their lifecycle changes
        // if a link is retracted, so they must be refreshed either way.
        $previousTargets = PullRequestTaskLink::query()
            ->where('task_id', $task->id)
            ->pluck('related_task_id')
            ->all();

        if ($resolved->isEmpty()) {
            // Nothing proposed: drop this task's stale AI links so a corrected review
            // can retract a relationship it previously claimed.
            $this->forgetAiLinks($task, []);

            // Still refresh: the task's own status was just overwritten by the
            // recorder, and anything it used to point at may now be clean again.
            $this->refreshStatuses([...$previousTargets, $task->id]);

            return 0;
        }

        DB::transaction(function () use ($task, $resolved): void {
            foreach ($resolved as $link) {
                $key = [
                    'task_id' => $task->id,
                    'related_task_id' => $link['related_task_id'],
                    'relation' => $link['relation']->value,
                ];

                $existing = PullRequestTaskLink::query()->where($key)->first();

                // A person already ruled on this pair. Leave it exactly as they set it.
                if ($existing?->source === PullRequestTaskLink::SOURCE_MANUAL) {
                    continue;
                }

                PullRequestTaskLink::updateOrCreate($key, [
                    'reason' => $link['reason'],
                    'confidence' => $link['confidence'],
                    'source' => PullRequestTaskLink::SOURCE_AI,
                ]);
            }

            $this->forgetAiLinks($task, $resolved->pluck('related_task_id')->all());
        });

        // Both ends can change: the target's lifecycle, and this task's own if
        // something already pointed at it.
        $this->refreshStatuses(
            $resolved->pluck('related_task_id')
                ->merge($previousTargets)
                ->push($task->id)
                ->unique()
                ->all(),
        );

        return $resolved->count();
    }

    /**
     * Recompute the cached lifecycle and counters for the given tasks.
     *
     * The link table is the source of truth; these columns are a cache that exists so
     * the dashboard is one indexed scan rather than a subquery per row.
     *
     * @param  array<int, string>  $taskIds
     */
    public function refreshStatuses(array $taskIds): void
    {
        if ($taskIds === []) {
            return;
        }

        PullRequestTask::query()
            ->whereIn('id', $taskIds)
            ->with('inboundLinks')
            ->get()
            ->each(function (PullRequestTask $task): void {
                $rework = 0;
                $revisions = 0;
                $revertedAt = null;

                foreach ($task->inboundLinks as $link) {
                    $relation = $link->relation;

                    if ($relation === null) {
                        continue;
                    }

                    if ($relation->indicatesRework()) {
                        $rework++;
                    }

                    if ($relation === TaskRelation::Extends) {
                        $revisions++;
                    }

                    if ($relation === TaskRelation::Reverts) {
                        $revertedAt ??= $link->created_at;
                    }
                }

                $task->forceFill([
                    'status' => $task->deriveStatus()->value,
                    'rework_count' => $rework,
                    'revision_count' => $revisions,
                    'reverted_at' => $revertedAt,
                ])->save();
            });
    }

    /**
     * Turn the model's proposals into rows that are safe to persist.
     *
     * @param  array<int, array<string, mixed>>  $links
     * @return Collection<int, array<string, mixed>>
     */
    private function resolve(PullRequestTask $task, array $links): Collection
    {
        $proposals = Collection::make(array_slice($links, 0, self::MAX_LINKS_PER_TASK))
            ->map(fn (mixed $link): ?array => $this->normalize($link))
            ->filter()
            ->values();

        if ($proposals->isEmpty()) {
            return $proposals;
        }

        // Only tasks in the same repository are candidates. A key that matches
        // something elsewhere is a coincidence, not a relationship.
        $targets = PullRequestTask::query()
            ->where('git_repository_id', $task->git_repository_id)
            ->whereIn('dedupe_key', $proposals->pluck('target_key')->unique()->all())
            ->whereKeyNot($task->getKey())
            ->get()
            ->keyBy('dedupe_key');

        return $proposals
            ->filter(fn (array $proposal): bool => $targets->has($proposal['target_key']))
            ->map(fn (array $proposal): array => [
                'related_task_id' => $targets[$proposal['target_key']]->id,
                'relation' => $proposal['relation'],
                'reason' => $proposal['reason'],
                'confidence' => $proposal['confidence'],
            ])
            // The model sometimes proposes the same pair twice with different wording.
            ->unique(fn (array $link): string => $link['related_task_id'].'|'.$link['relation']->value)
            ->values();
    }

    /**
     * Validate one proposal, or discard it.
     *
     * @return array<string, mixed>|null
     */
    private function normalize(mixed $link): ?array
    {
        if (! is_array($link)) {
            return null;
        }

        $targetKey = trim((string) data_get($link, 'target_dedupe_key', ''));
        $relation = TaskRelation::tryFrom((string) data_get($link, 'relation', ''));

        if ($targetKey === '' || $relation === null) {
            return null;
        }

        $confidence = data_get($link, 'confidence');

        return [
            'target_key' => $targetKey,
            'relation' => $relation,
            'reason' => trim((string) data_get($link, 'reason', '')) ?: null,
            'confidence' => is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : null,
        ];
    }

    /**
     * Remove AI links from this task that the latest review no longer proposes.
     *
     * Manual links are left alone - a person's correction outranks the model.
     *
     * @param  array<int, string>  $keepTaskIds
     */
    private function forgetAiLinks(PullRequestTask $task, array $keepTaskIds): void
    {
        PullRequestTaskLink::query()
            ->where('task_id', $task->id)
            ->where('source', PullRequestTaskLink::SOURCE_AI)
            ->when($keepTaskIds !== [], fn ($q) => $q->whereNotIn('related_task_id', $keepTaskIds))
            ->delete();
    }
}
