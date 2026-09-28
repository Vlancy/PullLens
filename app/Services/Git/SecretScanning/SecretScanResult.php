<?php

namespace App\Services\Git\SecretScanning;

/**
 * Everything one scan of a pull request diff found.
 */
final readonly class SecretScanResult
{
    /**
     * Create the result.
     *
     * $skippedPaths lists the safe paths that could not be scanned (no patch,
     * binary, too large), so their earlier findings are not mistaken for removed.
     * Deleted files are counted in $filesSkipped but not listed: they are gone.
     *
     * @param  list<SecretHit>  $hits
     * @param  list<string>  $skippedPaths
     */
    public function __construct(
        public array $hits,
        public int $filesScanned,
        public int $filesSkipped,
        public array $skippedPaths = [],
    ) {}
}
