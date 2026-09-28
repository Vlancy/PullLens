<?php

namespace App\Services\Git\SecretScanning;

use Illuminate\Support\Facades\File;

/**
 * Deletes secret-scan workspaces a killed or crashed scan left behind.
 *
 * Each workspace holds a pull request's raw added lines, which may be real, unredacted
 * secrets, so leftovers must not wait for the next pull request to be scanned before
 * they are cleared. A scan runs this at its own start, and it is also run on its own
 * hourly schedule so a quiet install with no traffic still gets swept.
 */
class SecretScanWorkspaceSweeper
{
    /** Workspaces older than this belong to a scan that died; none runs this long. */
    private const STALE_WORKSPACE_SECONDS = 15 * 60;

    /**
     * Delete every stale workspace directory and orphaned report file, and return
     * how many entries were removed.
     */
    public function sweep(): int
    {
        $cutoff = time() - self::STALE_WORKSPACE_SECONDS;
        $removed = 0;

        foreach (glob($this->root().'/*') ?: [] as $entry) {
            $modified = @filemtime($entry);

            if ($modified === false || $modified >= $cutoff) {
                continue;
            }

            if (is_dir($entry) && ! is_link($entry)) {
                File::deleteDirectory($entry);
                $removed++;
            } elseif (str_ends_with($entry, '.report.json')) {
                @unlink($entry);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * The directory every scan workspace is created in.
     */
    private function root(): string
    {
        return storage_path('app/secret-scans');
    }
}
