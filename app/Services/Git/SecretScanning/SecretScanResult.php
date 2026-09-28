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
     * @param  list<SecretHit>  $hits
     */
    public function __construct(
        public array $hits,
        public int $filesScanned,
        public int $filesSkipped,
    ) {}
}
