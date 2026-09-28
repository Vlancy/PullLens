<?php

namespace App\Services\Git\Scanning;

/**
 * What one scanner run of a pull request head produced, in the shape the scan job records.
 */
final readonly class ScanOutcome
{
    /**
     * Create the outcome.
     *
     * $skippedPaths lists the files that could not be scanned, so their earlier
     * findings are not mistaken for fixed. $targets counts the files the scanner
     * wanted to look at, and $notes are extra lines for the check summary.
     * $limitedPaths lists the files left out because the scan hit its file limit;
     * like skipped ones, their earlier findings stay open.
     *
     * @param  list<ScanIssue>  $issues
     * @param  list<string>  $skippedPaths
     * @param  list<string>  $notes
     * @param  list<string>  $limitedPaths
     */
    public function __construct(
        public array $issues,
        public int $filesScanned,
        public int $filesSkipped,
        public array $skippedPaths,
        public int $targets,
        public array $notes = [],
        public array $limitedPaths = [],
    ) {}
}
