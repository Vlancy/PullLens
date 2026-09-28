<?php

namespace App\Services\Git\SecretScanning;

/**
 * One redacted gitleaks result, with its path relative to the scanned directory.
 */
final readonly class GitleaksHit
{
    /**
     * Create the hit.
     */
    public function __construct(
        public string $ruleId,
        public string $description,
        public string $file,
        public int $line,
        public string $match,
    ) {}
}
