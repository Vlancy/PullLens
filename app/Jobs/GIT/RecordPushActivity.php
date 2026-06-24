<?php

namespace App\Jobs\GIT;

use App\Models\GIT\GitProviderApp;
use App\Models\GIT\GitRepository;
use App\Models\GIT\RepositoryCommit;
use App\Services\Git\GitHubApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RecordPushActivity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $commits  Commits from the GitHub push payload.
     */
    public function __construct(
        private readonly string $repositoryId,
        private readonly string $branch,
        private readonly array $commits,
    ) {}

    public function handle(GitHubApiClient $api): void
    {
        $repository = GitRepository::with('account')->find($this->repositoryId);

        if (! $repository) {
            return;
        }

        [$owner, $name] = explode('/', $repository->full_name, 2);

        $caller = $repository->account;
        $app = GitProviderApp::where('provider', 'github')->first();

        if ($app?->private_key && $repository->installation_id) {
            $token = $api->installationToken($app, (int) $repository->installation_id);
            if ($token !== '') {
                $caller = $token;
            }
        }

        foreach ($this->commits as $commit) {
            $sha = (string) data_get($commit, 'id');

            if (! $sha) {
                continue;
            }

            $filesChanged = count(array_merge(
                (array) data_get($commit, 'added', []),
                (array) data_get($commit, 'removed', []),
                (array) data_get($commit, 'modified', []),
            ));

            // Insert skeleton — ignore if this SHA is already tracked (e.g. via PR sync).
            $inserted = DB::table('repository_commits')->insertOrIgnore([[
                'id'                  => (string) Str::uuid(),
                'git_repository_id'   => $this->repositoryId,
                'sha'                 => $sha,
                'branch'              => $this->branch,
                'author_login'        => data_get($commit, 'author.username'),
                'author_name'         => data_get($commit, 'author.name'),
                'author_email'        => data_get($commit, 'author.email'),
                'message'             => data_get($commit, 'message'),
                'committed_at'        => data_get($commit, 'timestamp'),
                'additions'           => 0,
                'deletions'           => 0,
                'changed_files_count' => $filesChanged,
                'stats_synced'        => false,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]]);

            if (! $inserted) {
                // Already tracked (PR sync or earlier push) — leave existing stats untouched.
                continue;
            }

            // Fetch per-commit line stats from GitHub API.
            try {
                $detail    = $api->commit($caller, $owner, $name, $sha);
                $additions = (int) data_get($detail, 'stats.additions', 0);
                $deletions = (int) data_get($detail, 'stats.deletions', 0);
                $files     = count((array) data_get($detail, 'files', []));
                $avatarUrl = data_get($detail, 'author.avatar_url');

                DB::table('repository_commits')
                    ->where('git_repository_id', $this->repositoryId)
                    ->where('sha', $sha)
                    ->update([
                        'additions'           => $additions,
                        'deletions'           => $deletions,
                        'changed_files_count' => $files,
                        'author_avatar_url'   => $avatarUrl,
                        'stats_synced'        => true,
                        'updated_at'          => now(),
                    ]);
            } catch (\Throwable $e) {
                Log::warning('push_activity.stats_fetch_failed', [
                    'repo'  => $repository->full_name,
                    'sha'   => $sha,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
