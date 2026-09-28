<?php

namespace App\Services\Git\Scanning;

/**
 * The command-line tool a security scan runs, as far as the scan job needs to know it.
 */
interface ScannerBinary
{
    /**
     * Whether the configured binary can be executed.
     */
    public function isAvailable(): bool;

    /**
     * The tool's version string, or null when the binary cannot run.
     */
    public function version(): ?string;
}
