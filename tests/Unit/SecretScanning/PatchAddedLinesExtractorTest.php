<?php

use App\Services\Git\SecretScanning\PatchAddedLinesExtractor;

function added(string $patch): array
{
    return (new PatchAddedLinesExtractor)->addedLines($patch);
}

it('keeps added lines at their new-file line numbers', function () {
    $patch = "@@ -1,3 +1,4 @@\n line one\n-old two\n+new two\n+new three\n line four";

    expect(added($patch))->toBe([2 => 'new two', 3 => 'new three']);
});

it('follows multiple hunks', function () {
    $patch = "@@ -1,2 +1,2 @@\n a\n+b\n@@ -10,2 +10,3 @@\n x\n+y\n+z";

    expect(added($patch))->toBe([2 => 'b', 11 => 'y', 12 => 'z']);
});

it('reads a brand new file', function () {
    expect(added("@@ -0,0 +1,2 @@\n+KEY=abc\n+OTHER=def"))->toBe([1 => 'KEY=abc', 2 => 'OTHER=def']);
});

it('ignores the no-newline marker and strips carriage returns', function () {
    $patch = "@@ -1 +1 @@\n-a\r\n+b\r\n\\ No newline at end of file";

    expect(added($patch))->toBe([1 => 'b']);
});

it('returns nothing for a deletion-only patch', function () {
    expect(added("@@ -1,2 +0,0 @@\n-a\n-b"))->toBe([]);
});

it('keeps an added blank line', function () {
    expect(added("@@ -1 +1,2 @@\n a\n+"))->toBe([2 => '']);
});
