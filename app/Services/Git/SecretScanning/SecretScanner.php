<?php

namespace App\Services\Git\SecretScanning;

use App\Models\GIT\GitAccount;
use App\Services\Git\GitHubApiClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Scans what a pull request adds for leaked credentials.
 *
 * Nothing is cloned. Each changed file is rebuilt as a "mirror" holding only its
 * added lines, each at its real line number with every other line left blank, so
 * gitleaks reports positions that map straight back onto the pull request.
 */
class SecretScanner
{
    private const CONFIG_FILE = '.gitleaks.toml';

    private const IGNORE_FILE = '.gitleaksignore';

    /**
     * Inject the collaborators this class delegates to.
     */
    public function __construct(
        private readonly GitHubApiClient $api,
        private readonly PatchAddedLinesExtractor $extractor,
        private readonly GitleaksRunner $runner,
    ) {}

    /**
     * Scan a pull request's added lines, reading gitleaks config from $configRef.
     *
     * $configRef must be the target branch: reading config from the pull request
     * itself would let it allowlist the very secret it adds.
     */
    public function scan(GitAccount|string $caller, string $owner, string $repo, int $number, string $configRef): SecretScanResult
    {
        $files = $this->api->pullRequestFiles($caller, $owner, $repo, $number);

        $workspace = storage_path('app/secret-scans/'.Str::uuid());
        $source = $workspace.'/src';

        File::ensureDirectoryExists($source);

        try {
            $addedByFile = [];
            $skipped = 0;
            $skippedPaths = [];

            foreach ($files as $file) {
                $path = (string) data_get($file, 'filename', '');
                $patch = data_get($file, 'patch');

                if (! $this->isSafePath($path) || data_get($file, 'status') === 'removed' || ! is_string($patch) || $patch === '') {
                    $skipped++;

                    // Unsafe paths are never echoed back.
                    if ($this->isSafePath($path)) {
                        $skippedPaths[] = $path;
                    }

                    continue;
                }

                $added = $this->extractor->addedLines($patch);

                if ($added === []) {
                    continue;
                }

                $addedByFile[$path] = $added;
                $this->writeMirror($source.'/'.$path, $added);
            }

            if ($addedByFile === []) {
                return new SecretScanResult([], 0, $skipped, $skippedPaths);
            }

            $config = $this->copyFromBranch($caller, $owner, $repo, $configRef, self::CONFIG_FILE, $workspace);
            $ignore = $this->copyFromBranch($caller, $owner, $repo, $configRef, self::IGNORE_FILE, $workspace);

            $hits = [];

            foreach ($this->runner->run($source, $config, $ignore) as $hit) {
                $content = $addedByFile[$hit->file][$hit->line] ?? null;

                if ($content === null) {
                    continue;
                }

                $hits[] = new SecretHit(
                    ruleId: $hit->ruleId,
                    description: $hit->description,
                    file: $hit->file,
                    line: $hit->line,
                    match: $hit->match,
                    contentHash: substr(hash_hmac('sha256', trim($content), (string) config('app.key')), 0, 16),
                );
            }

            return new SecretScanResult($hits, count($addedByFile), $skipped, $skippedPaths);
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    /**
     * Write a file whose line N holds added line N, blank everywhere else.
     *
     * @param  array<int, string>  $added
     */
    private function writeMirror(string $path, array $added): void
    {
        $lines = array_fill(1, max(array_keys($added)), '');

        foreach ($added as $number => $content) {
            $lines[$number] = $content;
        }

        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, implode("\n", $lines));
    }

    /**
     * Copy a repository file from a branch into the workspace, returning its path.
     */
    private function copyFromBranch(GitAccount|string $caller, string $owner, string $repo, string $ref, string $name, string $workspace): ?string
    {
        $content = $this->api->fetchFileContent($caller, $owner, $repo, $name, $ref);

        if ($content === null) {
            return null;
        }

        $path = $workspace.'/config/'.$name;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Whether a provider-supplied path stays inside the workspace.
     */
    private function isSafePath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! in_array('..', explode('/', $path), true);
    }
}
