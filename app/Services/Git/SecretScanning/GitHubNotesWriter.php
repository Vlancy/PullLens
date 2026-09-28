<?php

namespace App\Services\Git\SecretScanning;

use App\Models\GIT\GitAccount;
use App\Services\Git\GitHubApiClient;
use Illuminate\Http\Client\RequestException;

/**
 * Attaches a git note to a commit through the Git Data API, without a clone.
 *
 * A notes ref is an ordinary commit whose tree maps annotated commit shas to note
 * blobs. Writing one is: blob, tree on top of the previous notes tree, commit
 * parented on the previous notes commit, then a fast-forward of the ref.
 * Read the notes with:
 *   git fetch origin refs/notes/gitleaks:refs/notes/gitleaks && git log --notes=gitleaks
 */
class GitHubNotesWriter
{
    public const REF = 'notes/gitleaks';

    /**
     * Inject the API client this class delegates to.
     */
    public function __construct(private readonly GitHubApiClient $api) {}

    /**
     * Set the note for $commitSha and return the new notes commit sha.
     *
     * A concurrent writer makes the fast-forward fail with 422; the write is rebuilt
     * once on top of their notes commit.
     */
    public function write(GitAccount|string $caller, string $owner, string $repo, string $commitSha, string $note): string
    {
        try {
            return $this->attempt($caller, $owner, $repo, $commitSha, $note);
        } catch (RequestException $e) {
            if ($e->response->status() !== 422) {
                throw $e;
            }

            return $this->attempt($caller, $owner, $repo, $commitSha, $note);
        }
    }

    /**
     * One blob → tree → commit → ref pass.
     */
    private function attempt(GitAccount|string $caller, string $owner, string $repo, string $commitSha, string $note): string
    {
        $parent = data_get($this->api->gitRef($caller, $owner, $repo, self::REF), 'object.sha');
        $baseTree = $parent === null ? null : data_get($this->api->gitCommit($caller, $owner, $repo, $parent), 'tree.sha');

        $blob = $this->api->createGitBlob($caller, $owner, $repo, $note);
        $tree = $this->api->createGitTree($caller, $owner, $repo, $baseTree, [
            ['path' => $commitSha, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob],
        ]);
        $commit = $this->api->createGitCommit(
            $caller, $owner, $repo,
            "gitleaks: notes for {$commitSha}",
            $tree,
            $parent === null ? [] : [$parent],
        );

        if ($parent === null) {
            $this->api->createGitRef($caller, $owner, $repo, 'refs/'.self::REF, $commit);
        } else {
            $this->api->updateGitRef($caller, $owner, $repo, self::REF, $commit);
        }

        return $commit;
    }
}
