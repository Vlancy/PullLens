<?php

namespace App\Services\Git\SecretScanning;

/**
 * A redacted gitleaks hit placed in the pull request, with a stable identity.
 */
final readonly class SecretHit
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
        public string $contentHash,
    ) {}

    /**
     * Identity that survives the line moving: rule, file, and a keyed hash of the line.
     */
    public function dedupeKey(): string
    {
        return "gitleaks:{$this->ruleId}:{$this->file}:{$this->contentHash}";
    }
}
