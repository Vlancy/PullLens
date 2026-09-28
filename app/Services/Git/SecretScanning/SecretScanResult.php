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
     * removed, binary), so their earlier findings are not mistaken for removed.
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
