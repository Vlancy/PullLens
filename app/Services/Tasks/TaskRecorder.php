<?php

namespace App\Services\Tasks;

use App\Enums\GIT\TaskStatus;
use App\Enums\GIT\TaskType;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists the units of work a review identified in a pull request.
 *
 * A pull request has exactly one current set of tasks. Re-reviewing after new
 * commits replaces that set rather than appending to it, otherwise the monthly
 * report would count the same work once per review the PR happened to receive.
 * Tasks whose dedupe_key survives are updated in place so their id - and anything
 * that may later reference it - stays stable.
 */
class TaskRecorder
{
    /** Guards against a malformed response inventing hundreds of tasks. */
    private const MAX_TASKS_PER_PULL_REQUEST = 20;

    /**
     * Inject the task linker and external reference detector this class delegates to.
     */
    public function __construct(
        private readonly TaskLinker $linker,
        private readonly ExternalReferenceDetector $references,
    ) {}

    /**
     * Replace the pull request's tasks with the ones in this review's output.
     *
     * @param  array<int, array<string, mixed>>  $tasks  The `tasks` array from the model response.
     * @return int Number of tasks recorded.
     */
    public function record(PullRequest $pullRequest, PullRequestReview $review, array $tasks): int
    {
        $normalized = $this->normalize($pullRequest, $review, $tasks);

        $saved = DB::transaction(function () use ($pullRequest, $normalized): array {
            $tasks = [];

            foreach ($normalized as $attributes) {
                $links = $attributes['links'];
                unset($attributes['links']);

                $task = PullRequestTask::updateOrCreate(
                    [
                        'pull_request_id' => $pullRequest->id,
                        'dedupe_key' => $attributes['dedupe_key'],
                    ],
                    $attributes,
                );

                $tasks[] = [$task, $links];
            }

            // Anything the latest review no longer claims was merged away, renamed or
            // never really separate; it must not linger in next month's report.
            PullRequestTask::query()
                ->where('pull_request_id', $pullRequest->id)
                ->whereNotIn('dedupe_key', array_column($normalized, 'dedupe_key'))
                ->delete();

            return $tasks;
        });

        // Linking runs after the tasks are committed: a link can point at a sibling
        // recorded moments ago, and resolving it needs every row to exist first.
        foreach ($saved as [$task, $links]) {
            $this->linker->apply($task, $links);
        }

        return count($saved);
    }

    /**
     * Re-stamp a pull request's tasks when it merges.
     *
     * `delivered_at` mirrors the PR's merge time so the report can ask "what shipped
     * in August" without joining back to pull_requests for every row. A PR is usually
     * reviewed before it merges, so the value is filled in on the merge event.
     */
    public function markDelivered(PullRequest $pullRequest): void
    {
        PullRequestTask::query()
            ->where('pull_request_id', $pullRequest->id)
            ->update([
                'delivered_at' => $pullRequest->merged_at,
                // Only set on the first delivery, so a later revision cannot rewrite
                // when the work originally shipped.
                'first_delivered_at' => DB::raw('COALESCE(first_delivered_at, '.$this->quotedTimestamp($pullRequest->merged_at).')'),
                'updated_at' => now(),
            ]);

        // Delivery changes the lifecycle, and inbound links may already exist.
        $this->linker->refreshStatuses(
            PullRequestTask::query()
                ->where('pull_request_id', $pullRequest->id)
                ->pluck('id')
                ->all(),
        );
    }

    /**
     * A SQL literal for a nullable timestamp, for use inside COALESCE.
     *
     * The value comes from the provider payload rather than a request, and is
     * re-formatted from a parsed date object, so it cannot carry SQL.
     */
    private function quotedTimestamp(?\DateTimeInterface $value): string
    {
        return $value === null ? 'NULL' : "'".$value->format('Y-m-d H:i:s')."'";
    }

    /**
     * Validate and shape the model output into persistable rows.
     *
     * Anything the model returns is treated as untrusted: types are coerced, unknown
     * task types fall back to chore, and rows without a usable title are dropped
     * rather than stored as blanks that would pollute the report.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<int, array<string, mixed>>
     */
    private function normalize(PullRequest $pullRequest, PullRequestReview $review, array $tasks): array
    {
        $rows = [];
        $seen = [];

        // Detected once per pull request, not once per task: every task a PR delivers
        // shares the same tracker reference.
        $reference = $this->references->detect($pullRequest);

        foreach (array_slice($tasks, 0, self::MAX_TASKS_PER_PULL_REQUEST) as $task) {
            $title = trim((string) data_get($task, 'title', ''));

            if ($title === '') {
                continue;
            }

            $key = $this->dedupeKey($task, $title);

            // The model occasionally repeats a key; first occurrence wins.
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $rows[] = [
                'pull_request_review_id' => $review->id,
                'pull_request_id' => $pullRequest->id,
                'git_repository_id' => $pullRequest->git_repository_id,
                'dedupe_key' => $key,
                'title' => Str::limit($title, 250, ''),
                'type' => $this->type($task)->value,
                'description' => trim((string) data_get($task, 'description', '')) ?: null,
                'estimated_hours' => $this->hours($task),
                'files' => array_values(array_filter(
                    array_map(
                        static fn (mixed $file): string => (string) $file,
                        (array) data_get($task, 'files', []),
                    ),
                )),
                // Point-in-time attribution: the report must not change if the PR is
                // later edited or transferred to another author.
                'author_login' => $pullRequest->author_login,
                'author_name' => $pullRequest->author_name,
                'author_avatar_url' => $pullRequest->author_avatar_url,
                'delivered_at' => $pullRequest->merged_at,
                'first_delivered_at' => $pullRequest->merged_at,
                'status' => ($pullRequest->merged_at !== null ? TaskStatus::Delivered : TaskStatus::InProgress)->value,
                'external_provider' => $reference === null ? null : $reference['provider']->value,
                'external_key' => $reference['key'] ?? null,
                'external_url' => $reference['url'] ?? null,
                // Carried through normalisation and stripped before the row is written.
                'links' => (array) data_get($task, 'links', []),
            ];
        }

        return $rows;
    }

    /**
     * The model's slug when it supplied a usable one, otherwise a stable slug derived
     * from the title so re-reviews still match the same row.
     *
     * @param  array<string, mixed>  $task
     */
    private function dedupeKey(array $task, string $title): string
    {
        $key = Str::slug((string) data_get($task, 'dedupe_key', ''));

        return $key !== '' ? Str::limit($key, 120, '') : Str::limit(Str::slug($title), 120, '');
    }

    /**
     * Coerce the task type the model returned, falling back to chore.
     *
     * @param  array<string, mixed>  $task
     */
    private function type(array $task): TaskType
    {
        return TaskType::tryFrom((string) data_get($task, 'type', '')) ?? TaskType::Chore;
    }

    /**
     * Coerce and clamp the estimated hours the model returned.
     *
     * @param  array<string, mixed>  $task
     */
    private function hours(array $task): ?float
    {
        $hours = data_get($task, 'estimated_hours');

        if (! is_numeric($hours)) {
            return null;
        }

        // Clamp: an implausible estimate would distort every total it lands in.
        return max(0.0, min(200.0, round((float) $hours, 2)));
    }
}
