<?php

namespace App\Services\Git\Scanning;

use App\Enums\GIT\FindingSeverity;

/**
 * One problem a scanner found, already shaped as a PullLens finding.
 *
 * The dedupe key is the problem's identity across pushes; it must not contain a line
 * number, so a problem that merely moves keeps its thread.
 */
final readonly class ScanIssue
{
    /**
     * Create the issue.
     *
     * @param  array<string, string|null>  $metadata
     */
    public function __construct(
        public string $dedupeKey,
        public string $title,
        public FindingSeverity $severity,
        public string $file,
        public ?int $line,
        public string $explanation,
        public string $suggestedFix,
        public array $metadata,
    ) {}
}
