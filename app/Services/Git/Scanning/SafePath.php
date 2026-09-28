<?php

namespace App\Services\Git\Scanning;

/**
 * Decides whether a provider-supplied path may be written inside a scan workspace.
 */
final class SafePath
{
    /**
     * Whether $path is relative, has no NUL byte and never climbs out with "..".
     */
    public static function isSafe(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! in_array('..', explode('/', $path), true);
    }
}
