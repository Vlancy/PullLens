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
 *
 * The workspace holds raw added lines, so it is deleted when the scan ends, and
 * any a killed worker left behind is swept at the start of the next scan.
 */
class SecretScanner
{
    private const CONFIG_FILE = '.gitleaks.toml';

    private const IGNORE_FILE = '.gitleaksignore';

    /** Used when the target branch has no config: gitleaks's own rules. */
    private const DEFAULT_CONFIG = "[extend]\nuseDefault = true\n";

    /**
     * Suffix given to mirrored files gitleaks could read as its own config.
     *
     * gitleaks 8.28 auto-loads .gitleaksignore and .gitleaks.* only from the scan
     * root, and --config/--gitleaks-ignore-path are always passed; renaming them at
     * every depth still keeps a pull request's copy from ever being read as config.
     */
    private const MIRROR_SUFFIX = '.pulllens-mirror';

    /** Workspaces older than this belong to a scan that died; none runs this long. */
    private const STALE_WORKSPACE_SECONDS = 15 * 60;

    /** Largest run of blank lines written at once when padding a mirror. */
    private const PADDING_CHUNK = 65536;

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
        $this->sweepStaleWorkspaces();

        $files = $this->api->pullRequestFiles($caller, $owner, $repo, $number);

        $workspace = $this->root().'/'.Str::uuid();
        $source = $workspace.'/src';

        File::ensureDirectoryExists($source);

        try {
            $addedByFile = [];
            $originalPaths = [];
            $skipped = 0;
            $skippedPaths = [];

            foreach ($files as $file) {
                $path = (string) data_get($file, 'filename', '');
                $patch = data_get($file, 'patch');

                if (! $this->isSafePath($path) || data_get($file, 'status') === 'removed' || ! is_string($patch) || $patch === '') {
                    $skipped++;

                    // Unsafe paths are never echoed back, and a removed file was not
                    // unseen but deleted, so its findings may resolve.
                    if ($this->isSafePath($path) && data_get($file, 'status') !== 'removed') {
                        $skippedPaths[] = $path;
                    }

                    continue;
                }

                $added = $this->extractor->addedLines($patch);

                if ($added === []) {
                    continue;
                }

                $mirrorPath = $this->mirrorPath($path);
                $addedByFile[$path] = $added;
                $originalPaths[$mirrorPath] = $path;
                $this->writeMirror($source.'/'.$mirrorPath, $added);
            }

            if ($addedByFile === []) {
                return new SecretScanResult([], 0, $skipped, $skippedPaths);
            }

            $config = $this->copyFromBranch($caller, $owner, $repo, $configRef, self::CONFIG_FILE, $workspace, self::DEFAULT_CONFIG);
            $ignore = $this->copyFromBranch($caller, $owner, $repo, $configRef, self::IGNORE_FILE, $workspace, '');

            $hits = [];

            foreach ($this->runner->run($source, $config, $ignore) as $hit) {
                $path = $originalPaths[$hit->file] ?? null;
                $content = $path === null ? null : ($addedByFile[$path][$hit->line] ?? null);

                if ($content === null) {
                    continue;
                }

                $hits[] = new SecretHit(
                    ruleId: $hit->ruleId,
                    description: $hit->description,
                    file: $path,
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
     * Delete workspaces and reports a killed or crashed scan never cleaned up.
     */
    private function sweepStaleWorkspaces(): void
    {
        $cutoff = time() - self::STALE_WORKSPACE_SECONDS;

        foreach (glob($this->root().'/*') ?: [] as $entry) {
            $modified = @filemtime($entry);

            if ($modified === false || $modified >= $cutoff) {
                continue;
            }

            if (is_dir($entry) && ! is_link($entry)) {
                File::deleteDirectory($entry);
            } elseif (str_ends_with($entry, '.report.json')) {
                @unlink($entry);
            }
        }
    }

    /**
     * The directory every scan workspace is created in.
     */
    private function root(): string
    {
        return storage_path('app/secret-scans');
    }

    /**
     * Where a pull request file is mirrored: its own path, renamed when gitleaks could
     * read it as config.
     *
     * Names already ending in the suffix are suffixed again, so no two pull request
     * paths can land on the same mirror.
     */
    private function mirrorPath(string $path): string
    {
        $name = basename($path);

        if (preg_match('/^\.gitleaks(ignore)?(\..*)?$/i', $name) === 1 || str_ends_with($name, self::MIRROR_SUFFIX)) {
            return $path.self::MIRROR_SUFFIX;
        }

        return $path;
    }

    /**
     * Write a file whose line N holds added line N, blank everywhere else.
     *
     * Streamed, with the blank padding written in bounded chunks, so a line added far
     * down a huge file costs disk, not memory.
     *
     * @param  array<int, string>  $added
     */
    private function writeMirror(string $path, array $added): void
    {
        ksort($added);
        File::ensureDirectoryExists(dirname($path));

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new GitleaksFailed('could not write the scan workspace');
        }

        try {
            $line = 1;

            foreach ($added as $number => $content) {
                for ($gap = $number - $line; $gap > 0; $gap -= self::PADDING_CHUNK) {
                    fwrite($handle, str_repeat("\n", min($gap, self::PADDING_CHUNK)));
                }

                fwrite($handle, $content);
                $line = $number;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Copy a repository file from a branch into the workspace config directory,
     * or write $fallback there when the branch has none, and return its path.
     */
    private function copyFromBranch(GitAccount|string $caller, string $owner, string $repo, string $ref, string $name, string $workspace, string $fallback): string
    {
        $content = $this->api->fetchFileContent($caller, $owner, $repo, $name, $ref) ?? $fallback;

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
