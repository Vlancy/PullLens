<?php

namespace App\Jobs\GIT;

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitProviderApp;
use App\Models\GIT\GitRepository;
use App\Models\GIT\RepositoryCommit;
use App\Services\Git\GitHubApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records commits from a push event and backfills their line statistics.
 *
 * Push payloads carry the commit list but not per-commit additions/deletions, so
 * each newly seen SHA is enriched with a follow-up API call. Commits already known
 * from PR syncing are left untouched - their stats are authoritative.
 */
class RecordPushActivity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A push carries many commits, each needing its own API call for line stats. */
    public int $timeout = 120;

    /** Retried once: a transient provider error should not lose the push history. */
    public int $tries = 2;

    /**
     * Create the instance.
     *
     * @param  array<int, array<string, mixed>>  $commits  Commits from the GitHub push payload.
     */
    public function __construct(
        private readonly string $repositoryId,
        private readonly string $branch,
        private readonly array $commits,
    ) {}

    /**
     * Execute the record push activity job.
     */
    public function handle(GitHubApiClient $api): void
    {
        $repository = GitRepository::with('account')->find($this->repositoryId);

        if ($repository === null) {
            return;
        }

        $caller = $this->resolveApiCaller($api, $repository);
        [$owner, $name] = explode('/', $repository->full_name, 2);

        foreach ($this->commits as $payload) {
            $sha = (string) data_get($payload, 'id');

            if ($sha === '') {
                continue;
            }

            $commit = $this->recordSkeleton($sha, $payload);

            // Already tracked by a PR sync or an earlier push - its stats are already
            // correct, so re-fetching them would waste an API call.
            if ($commit === null) {
                continue;
            }

            $this->backfillStats($api, $caller, $commit, $repository, $owner, $name, $sha);
        }
    }

    /**
     * Insert the commit if this is the first time we have seen the SHA.
     *
     * Relies on the unique (git_repository_id, sha) index: `firstOrCreate` narrows the
     * race window and the index closes it, so concurrent deliveries cannot duplicate a
     * commit. Returns null when the row already existed.
     */
    private function recordSkeleton(string $sha, array $payload): ?RepositoryCommit
    {
        $commit = RepositoryCommit::firstOrCreate(
            [
                'git_repository_id' => $this->repositoryId,
                'sha' => $sha,
            ],
            [
                'branch' => $this->branch,
                'author_login' => data_get($payload, 'author.username'),
                'author_name' => data_get($payload, 'author.name'),
                'author_email' => data_get($payload, 'author.email'),
                'message' => data_get($payload, 'message'),
                'committed_at' => data_get($payload, 'timestamp'),
                'additions' => 0,
                'deletions' => 0,
                'changed_files_count' => $this->countChangedFiles($payload),
                'stats_synced' => false,
            ],
        );

        return $commit->wasRecentlyCreated ? $commit : null;
    }

    /**
     * Fetch and store per-commit line statistics.
     *
     * A failure here is logged and swallowed: the commit is already recorded, and
     * `stats_synced` stays false so a later backfill can retry it.
     */
    private function backfillStats(
        GitHubApiClient $api,
        mixed $caller,
        RepositoryCommit $commit,
        GitRepository $repository,
        string $owner,
        string $name,
        string $sha,
    ): void {
        try {
            $detail = $api->commit($caller, $owner, $name, $sha);

            $commit->forceFill([
                'additions' => (int) data_get($detail, 'stats.additions', 0),
                'deletions' => (int) data_get($detail, 'stats.deletions', 0),
                'changed_files_count' => count((array) data_get($detail, 'files', [])),
                'author_avatar_url' => data_get($detail, 'author.avatar_url'),
                'stats_synced' => true,
            ])->save();
        } catch (Throwable $e) {
            Log::warning('push_activity.stats_fetch_failed', [
                'repo' => $repository->full_name,
                'sha' => $sha,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Prefer a short-lived GitHub App installation token over the stored OAuth account:
     * it is scoped to this repository and is not tied to a person who may have left.
     */
    private function resolveApiCaller(GitHubApiClient $api, GitRepository $repository): mixed
    {
        $app = GitProviderApp::query()
            ->where('provider', GitProvider::Github->value)
            ->first();

        if ($app?->private_key === null || blank($repository->installation_id)) {
            return $repository->account;
        }

        $token = $api->installationToken($app, (int) $repository->installation_id);

        return $token !== '' ? $token : $repository->account;
    }

    /**
     * Number of distinct paths a push commit touched.
     *
     * @param  array<string, mixed>  $payload
     */
    private function countChangedFiles(array $payload): int
    {
        return count(array_merge(
            (array) data_get($payload, 'added', []),
            (array) data_get($payload, 'removed', []),
            (array) data_get($payload, 'modified', []),
        ));
    }
}
