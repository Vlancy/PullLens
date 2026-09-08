<?php

namespace App\Jobs\GIT;

use App\Enums\GIT\ContributorRole;
use App\Enums\GIT\PullRequestFileStatus;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitProviderApp;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestCommit;
use App\Models\GIT\PullRequestContributor;
use App\Models\GIT\PullRequestFile;
use App\Models\GIT\RepositoryCommit;
use App\Services\Git\GitHubApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fetches commits and file-level changes for a pull request from the GitHub API
 * and persists them for reporting and AI review context.
 */
class SyncPullRequestDetails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Fetches files, commits and contributors - several API calls for a large PR. */
    public int $timeout = 120;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * Inject the string this class delegates to.
     */
    public function __construct(public readonly string $pullRequestId) {}

    /**
     * Execute the sync pull request details job.
     */
    public function handle(GitHubApiClient $api): void
    {
        $pullRequest = PullRequest::with(['repository.account'])->findOrFail($this->pullRequestId);
        $repository = $pullRequest->repository;
        $account = $repository->account;
        [$owner, $name] = explode('/', $repository->full_name, 2);

        $caller = $account;
        $app = GitProviderApp::where('provider', 'github')->first();

        if ($app?->private_key && $repository->installation_id) {
            $token = $api->installationToken($app, (int) $repository->installation_id);
            if ($token !== '') {
                $caller = $token;
            }
        }

        $this->syncCommits($pullRequest, $api->pullRequestCommits($caller, $owner, $name, $pullRequest->number), $api, $caller, $owner, $name);
        $this->syncFiles($pullRequest, $api->pullRequestFiles($caller, $owner, $name, $pullRequest->number));
    }

    /**
     * Persist commits and upsert contributor records for each commit author.
     * Fetches per-commit stats individually since the list endpoint omits them.
     *
     * @param  array<int, array<string, mixed>>  $commits
     */
    private function syncCommits(PullRequest $pullRequest, array $commits, GitHubApiClient $api, string|GitAccount $caller, string $owner, string $name): void
    {
        foreach ($commits as $commit) {
            $sha = (string) data_get($commit, 'sha');

            // The PR commits list endpoint omits stats - fetch the single commit for additions/deletions.
            $detail = $api->commit($caller, $owner, $name, $sha);

            $commitAttrs = [
                'short_sha' => substr($sha, 0, 7),
                'message' => (string) data_get($commit, 'commit.message'),
                'author_login' => data_get($commit, 'author.login'),
                'author_name' => data_get($commit, 'commit.author.name'),
                'author_email' => data_get($commit, 'commit.author.email'),
                'author_avatar_url' => data_get($commit, 'author.avatar_url'),
                'committed_at' => data_get($commit, 'commit.author.date'),
                'additions' => (int) data_get($detail, 'stats.additions', 0),
                'deletions' => (int) data_get($detail, 'stats.deletions', 0),
                'changed_files_count' => count((array) data_get($detail, 'files', [])),
                // Kept so a task can be attributed to the commit that carried its files
                // rather than to whoever opened the pull request.
                'files' => array_values(array_filter(array_map(
                    static fn (array $file): string => (string) data_get($file, 'filename', ''),
                    (array) data_get($detail, 'files', []),
                ))),
            ];

            PullRequestCommit::updateOrCreate(
                ['pull_request_id' => $pullRequest->id, 'sha' => $sha],
                $commitAttrs,
            );

            // Keep repository_commits in sync for activity reports - upsert so the canonical
            // record always has PR context and accurate stats regardless of insertion order.
            RepositoryCommit::updateOrCreate(
                ['git_repository_id' => $pullRequest->git_repository_id, 'sha' => $sha],
                [
                    'pull_request_id' => $pullRequest->id,
                    'branch' => $pullRequest->target_branch,
                    'author_login' => $commitAttrs['author_login'],
                    'author_name' => $commitAttrs['author_name'],
                    'author_email' => $commitAttrs['author_email'],
                    'author_avatar_url' => $commitAttrs['author_avatar_url'],
                    'message' => $commitAttrs['message'],
                    'additions' => $commitAttrs['additions'],
                    'deletions' => $commitAttrs['deletions'],
                    'changed_files_count' => $commitAttrs['changed_files_count'],
                    'committed_at' => $commitAttrs['committed_at'],
                    'stats_synced' => true,
                ],
            );

            $login = data_get($commit, 'author.login');

            if (filled($login)) {
                $contributor = PullRequestContributor::firstOrCreate(
                    ['pull_request_id' => $pullRequest->id, 'login' => $login, 'role' => ContributorRole::CoAuthor->value],
                    [
                        'name' => data_get($commit, 'commit.author.name'),
                        'email' => data_get($commit, 'commit.author.email'),
                        'avatar_url' => data_get($commit, 'author.avatar_url'),
                        'provider_user_id' => (string) data_get($commit, 'author.id'),
                        'commit_count' => 0,
                    ],
                );

                $contributor->increment('commit_count');
            }
        }

        // Ensure the PR author is represented as the primary author contributor.
        PullRequestContributor::firstOrCreate(
            ['pull_request_id' => $pullRequest->id, 'login' => $pullRequest->author_login, 'role' => ContributorRole::Author->value],
            [
                'avatar_url' => $pullRequest->author_avatar_url,
                'commit_count' => 0,
            ],
        );
    }

    /**
     * Persist file-level changes, detecting language from extension.
     *
     * @param  array<int, array<string, mixed>>  $files
     */
    private function syncFiles(PullRequest $pullRequest, array $files): void
    {
        foreach ($files as $file) {
            $filename = (string) data_get($file, 'filename');
            $status = PullRequestFileStatus::tryFrom((string) data_get($file, 'status', 'modified'))
                ?? PullRequestFileStatus::Modified;

            PullRequestFile::updateOrCreate(
                ['pull_request_id' => $pullRequest->id, 'filename' => $filename],
                [
                    'previous_filename' => data_get($file, 'previous_filename'),
                    'status' => $status->value,
                    'additions' => (int) data_get($file, 'additions', 0),
                    'deletions' => (int) data_get($file, 'deletions', 0),
                    'language' => $this->detectLanguage($filename),
                    'patch' => data_get($file, 'patch'),
                ],
            );
        }
    }

    /**
     * Best-effort language for a path, taken from its extension.
     */
    private function detectLanguage(string $path): ?string
    {
        static $map = [
            'php' => 'PHP', 'js' => 'JavaScript', 'ts' => 'TypeScript', 'tsx' => 'TypeScript',
            'jsx' => 'JavaScript', 'py' => 'Python', 'rb' => 'Ruby', 'go' => 'Go',
            'rs' => 'Rust', 'java' => 'Java', 'kt' => 'Kotlin', 'swift' => 'Swift',
            'cs' => 'C#', 'cpp' => 'C++', 'cc' => 'C++', 'c' => 'C', 'scala' => 'Scala',
            'ex' => 'Elixir', 'exs' => 'Elixir', 'dart' => 'Dart', 'lua' => 'Lua',
            'html' => 'HTML', 'css' => 'CSS', 'scss' => 'SCSS', 'sass' => 'Sass',
            'less' => 'Less', 'vue' => 'Vue', 'svelte' => 'Svelte', 'sql' => 'SQL',
            'sh' => 'Shell', 'bash' => 'Shell', 'yaml' => 'YAML', 'yml' => 'YAML',
            'json' => 'JSON', 'toml' => 'TOML', 'xml' => 'XML', 'tf' => 'Terraform',
            'graphql' => 'GraphQL', 'gql' => 'GraphQL', 'proto' => 'Protobuf',
        ];

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return $map[$ext] ?? null;
    }
}
