<?php

namespace App\Console\Commands\Tasks;

use App\Models\GIT\PullRequest;
use App\Services\Tasks\TaskAuthorResolver;
use Illuminate\Console\Command;

/**
 * Re-stamps recorded tasks with the developer who actually wrote them.
 *
 * Tasks used to inherit the pull request's author, which credited the opener of a
 * pull request with work they may not have written. This walks the existing rows and
 * re-runs them through the same resolver a new review would use, so the reports built
 * on historical data agree with the ones built from now on.
 */
class ReattributeTaskAuthors extends Command
{
    /** @var string */
    protected $signature = 'tasks:reattribute-authors
        {--pull-request= : Limit the run to a single pull request id}
        {--dry-run : Report what would change without writing anything}';

    /** @var string */
    protected $description = 'Re-attribute recorded tasks from the pull request author to the commit author who did the work';

    /**
     * Walk every pull request that has tasks and correct their attribution.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $scanned = 0;

        PullRequest::query()
            ->when($this->option('pull-request'), fn ($query, $id) => $query->whereKey($id))
            ->whereHas('tasks')
            ->with('tasks')
            ->chunkById(100, function ($pullRequests) use (&$changed, &$scanned, $dryRun): void {
                foreach ($pullRequests as $pullRequest) {
                    $authors = new TaskAuthorResolver($pullRequest);

                    foreach ($pullRequest->tasks as $task) {
                        $scanned++;

                        $files = (array) ($task->files ?? []);
                        $author = $files === [] ? $authors->principal() : $authors->forFiles($files);

                        if ($author['author_login'] === $task->author_login) {
                            continue;
                        }

                        $this->line(sprintf(
                            'PR #%d %s: %s -> %s',
                            $pullRequest->number,
                            $task->dedupe_key,
                            $task->author_login ?? '(none)',
                            $author['author_login'] ?? '(none)',
                        ));

                        $changed++;

                        if (! $dryRun) {
                            $task->forceFill($author)->save();
                        }
                    }
                }
            });

        $this->info(sprintf(
            '%s %d of %d task(s).',
            $dryRun ? 'Would re-attribute' : 'Re-attributed',
            $changed,
            $scanned,
        ));

        return self::SUCCESS;
    }
}
