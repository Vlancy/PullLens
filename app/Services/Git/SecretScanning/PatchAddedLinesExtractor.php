<?php

namespace App\Services\Git\SecretScanning;

/**
 * Reads the lines a unified-diff patch adds, keyed by their line number in the new file.
 *
 * Only added lines are scanned for secrets: context lines were already in the base
 * branch, and removed lines are not part of what the pull request introduces.
 */
class PatchAddedLinesExtractor
{
    /**
     * Map each added line's new-file line number to its content.
     *
     * @return array<int, string>
     */
    public function addedLines(string $patch): array
    {
        $added = [];
        $newLine = 0;
        $inHunk = false;

        foreach (explode("\n", $patch) as $row) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $row, $match) === 1) {
                $newLine = (int) $match[1];
                $inHunk = true;

                continue;
            }

            if (! $inHunk || $row === '') {
                continue;
            }

            $marker = $row[0];
            $content = rtrim(substr($row, 1), "\r");

            if ($marker === '+') {
                $added[$newLine] = $content;
                $newLine++;
            } elseif ($marker === ' ') {
                $newLine++;
            }
            // '-' (removed) and '\' (no-newline marker) do not exist in the new file.
        }

        return $added;
    }
}
