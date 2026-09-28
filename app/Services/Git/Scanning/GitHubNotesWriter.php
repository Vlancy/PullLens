<?php

namespace App\Services\Git\Scanning;

use App\Models\GIT\GitAccount;
use App\Services\Git\GitHubApiClient;
use Illuminate\Http\Client\RequestException;

/**
 * Attaches a git note to a commit through the Git Data API, without a clone.
 *
 * A notes ref is an ordinary commit whose tree maps annotated commit shas to note
 * blobs. Writing one is: blob, tree on top of the previous notes tree, commit
 * parented on the previous notes commit, then a fast-forward of the ref.
 * Read the notes with, for example:
 *   git fetch origin refs/notes/gitleaks:refs/notes/gitleaks && git log --notes=gitleaks
 */
class GitHubNotesWriter
{
    public const DEFAULT_REF = 'notes/gitleaks';

    /**
     * Inject the API client this class delegates to.
     */
    public function __construct(private readonly GitHubApiClient $api) {}

    /**
     * Set the note for $commitSha under $ref (without "refs/") and return the new notes commit sha.
     *
     * A concurrent writer makes the fast-forward fail with 422; the write is rebuilt
     * once on top of their notes commit.
     */
    public function write(GitAccount|string $caller, string $owner, string $repo, string $commitSha, string $note, string $ref = self::DEFAULT_REF): string
    {
        try {
            return $this->attempt($caller, $owner, $repo, $commitSha, $note, $ref);
        } catch (RequestException $e) {
            if ($e->response->status() !== 422) {
                throw $e;
            }

            return $this->attempt($caller, $owner, $repo, $commitSha, $note, $ref);
        }
    }

    /**
     * One blob → tree → commit → ref pass.
     */
    private function attempt(GitAccount|string $caller, string $owner, string $repo, string $commitSha, string $note, string $ref): string
    {
        $parent = data_get($this->api->gitRef($caller, $owner, $repo, $ref), 'object.sha');
        $baseTree = $parent === null ? null : data_get($this->api->gitCommit($caller, $owner, $repo, $parent), 'tree.sha');

        $blob = $this->api->createGitBlob($caller, $owner, $repo, $note);
        $tree = $this->api->createGitTree($caller, $owner, $repo, $baseTree, [
            ['path' => $commitSha, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob],
        ]);
        $commit = $this->api->createGitCommit(
            $caller, $owner, $repo,
            str($ref)->afterLast('/').": notes for {$commitSha}",
            $tree,
            $parent === null ? [] : [$parent],
        );

        if ($parent === null) {
            $this->api->createGitRef($caller, $owner, $repo, 'refs/'.$ref, $commit);
        } else {
            $this->api->updateGitRef($caller, $owner, $repo, $ref, $commit);
        }

        return $commit;
    }
}
