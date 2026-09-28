<?php

namespace App\Services\Git\SecretScanning;

use App\Services\Git\Scanning\ScanWorkspaceSweeper;

/**
 * Sweeps secret-scan workspaces, which hold a pull request's raw added lines.
 */
class SecretScanWorkspaceSweeper extends ScanWorkspaceSweeper
{
    /**
     * The directory every secret-scan workspace is created in.
     */
    public function root(): string
    {
        return storage_path('app/secret-scans');
    }
}
