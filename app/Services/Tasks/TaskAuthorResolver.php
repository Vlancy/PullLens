<?php

namespace App\Services\Tasks;

use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestCommit;

/**
 * Decides which developer a task belongs to.
 *
 * The person who opened a pull request is not necessarily the person who wrote what
 * is in it: leads open pull requests on behalf of a teammate, releases are cut by
 * whoever is on duty, and a branch may carry work from several people. Attributing a
 * task to the opener would credit their work to the wrong developer in every report.
 *
 * Attribution therefore follows the commits, in descending order of confidence:
 *
 *   1. the commit author who touched the files the task claims;
 *   2. the pull request's main commit author, when nothing matches on files;
 *   3. the pull request's author, when no commit carries a login at all.
 *
 * The resolver is built once per pull request so the commit list is read a single
 * time no matter how many tasks that review recorded.
 */
class TaskAuthorResolver
{
    /**
     * Commit authors keyed by the path they touched.
     *
     * @var array<string, array<string, int>>
     */
    private array $authorsByFile = [];

    /**
     * Commit count per author login, highest first once sorted.
     *
     * @var array<string, int>
     */
    private array $commitCounts = [];

    /**
     * Identity of each commit author, keyed by login.
     *
     * @var array<string, array{author_login: string, author_name: string|null, author_avatar_url: string|null}>
     */
    private array $identities = [];

    /**
     * Read the pull request's commits once and index them for lookup.
     */
    public function __construct(private readonly PullRequest $pullRequest)
    {
        $commits = PullRequestCommit::query()
            ->where('pull_request_id', $pullRequest->id)
            ->whereNotNull('author_login')
            ->orderBy('committed_at')
            ->get(['author_login', 'author_name', 'author_avatar_url', 'files']);

        foreach ($commits as $commit) {
            $login = (string) $commit->author_login;

            if ($login === '') {
                continue;
            }

            $this->commitCounts[$login] = ($this->commitCounts[$login] ?? 0) + 1;

            // First commit wins the identity: later commits from the same person carry
            // the same login, and the earliest is the closest to when they did the work.
            $this->identities[$login] ??= [
                'author_login' => $login,
                'author_name' => $commit->author_name,
                'author_avatar_url' => $commit->author_avatar_url,
            ];

            foreach ((array) $commit->files as $file) {
                $path = $this->normalize((string) $file);

                if ($path === '') {
                    continue;
                }

                $this->authorsByFile[$path][$login] = ($this->authorsByFile[$path][$login] ?? 0) + 1;
            }
        }

        arsort($this->commitCounts);
    }

    /**
     * The author to stamp on a task that touched the given paths.
     *
     * @param  array<int, string>  $files
     * @return array{author_login: string|null, author_name: string|null, author_avatar_url: string|null}
     */
    public function forFiles(array $files): array
    {
        $scores = [];

        foreach ($files as $file) {
            $path = $this->normalize((string) $file);

            foreach ($this->authorsByFile[$path] ?? [] as $login => $commits) {
                $scores[$login] = ($scores[$login] ?? 0) + $commits;
            }
        }

        if ($scores !== []) {
            // A tie between two people on the same files goes to whoever committed more
            // often across the pull request, which is the closest thing to ownership.
            uksort($scores, fn (string $a, string $b): int => ($this->commitCounts[$b] ?? 0) <=> ($this->commitCounts[$a] ?? 0));
            arsort($scores);

            return $this->identities[array_key_first($scores)];
        }

        return $this->principal();
    }

    /**
     * The developer behind the pull request as a whole.
     *
     * Used for tasks the model reported without files, and for pull requests whose
     * commits predate file tracking.
     *
     * @return array{author_login: string|null, author_name: string|null, author_avatar_url: string|null}
     */
    public function principal(): array
    {
        $login = array_key_first($this->commitCounts);

        if ($login !== null) {
            return $this->identities[$login];
        }

        return [
            'author_login' => $this->pullRequest->author_login,
            'author_name' => $this->pullRequest->author_name,
            'author_avatar_url' => $this->pullRequest->author_avatar_url,
        ];
    }

    /**
     * Compare paths the way a provider reports them: repository-relative, no leading
     * slash, case preserved because most of the hosts PullLens talks to are case
     * sensitive.
     */
    private function normalize(string $path): string
    {
        return ltrim(trim($path), '/');
    }
}
