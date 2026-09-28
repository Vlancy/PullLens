<?php

use App\Services\Git\SecretScanning\SecretScanner;
use App\Services\Git\SecretScanning\SecretScanWorkspaceSweeper;
use Illuminate\Http\Client\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Fake gitleaks. Each scan records the mirrored files it was pointed at, then
 * reports $hits.
 */
function fakeGitleaksScan(array $hits, ?array &$seen = null): void
{
    Process::fake(function (PendingProcess $process) use ($hits, &$seen) {
        $command = (array) $process->command;

        if (in_array('version', $command, true)) {
            return Process::result('8.28.0');
        }

        $seen = ['path' => $process->path, 'command' => $command, 'files' => []];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($process->path, FilesystemIterator::SKIP_DOTS)) as $file) {
            $seen['files'][substr($file->getPathname(), strlen($process->path) + 1)] = file_get_contents($file->getPathname());
        }

        foreach (['--config', '--gitleaks-ignore-path'] as $flag) {
            $i = array_search($flag, $command, true);
            $seen[$flag] = $i === false ? null : file_get_contents($command[$i + 1]);
            $seen[$flag.'-path'] = $i === false ? null : $command[$i + 1];
        }

        file_put_contents($command[array_search('--report-path', $command, true) + 1], json_encode($hits));

        return Process::result('');
    });
}

function fakePullFiles(array $files, array $contents = []): void
{
    // Http::fake() appends to the existing stub list rather than replacing it, so the
    // first-registered stub for a URL always wins. Tests here call fakePullFiles more
    // than once per test (e.g. to rerun a scan with a different patch), so the facade's
    // resolved instance is cleared first to start from an empty stub list each time.
    app()->forgetInstance(Factory::class);
    Http::clearResolvedInstance(Factory::class);

    $fakes = ['api.github.com/repos/octocat/app/pulls/7/files*' => Http::response($files)];

    foreach ($contents as $path => $body) {
        $fakes["api.github.com/repos/octocat/app/contents/{$path}*"] = Http::response(['content' => base64_encode($body)]);
    }

    Http::fake($fakes + ['api.github.com/repos/octocat/app/contents/*' => Http::response([], 404)]);
}

function awsHit(string $file = 'config/app.php', int $line = 2): array
{
    return ['RuleID' => 'aws-access-token', 'Description' => 'AWS Access Key', 'File' => $file, 'StartLine' => $line, 'Match' => 'AWS_KEY=REDACTED'];
}

it('mirrors added lines at their real line numbers and maps hits back', function () {
    fakePullFiles([[
        'filename' => 'config/app.php', 'status' => 'modified',
        'patch' => "@@ -1,2 +1,3 @@\n <?php\n+AWS_KEY=AKIAABCDEFGHIJKLMNOP\n return [];",
    ]]);
    fakeGitleaksScan([awsHit()], $seen);

    $result = app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');

    expect($seen['files'])->toBe(['config/app.php' => "\nAWS_KEY=AKIAABCDEFGHIJKLMNOP"])
        ->and($result->filesScanned)->toBe(1)
        ->and($result->hits)->toHaveCount(1)
        ->and($result->hits[0]->file)->toBe('config/app.php')
        ->and($result->hits[0]->line)->toBe(2)
        ->and($result->hits[0]->contentHash)->toHaveLength(16)
        ->and($result->hits[0]->dedupeKey())->toStartWith('gitleaks:aws-access-token:config/app.php:');
});

it('keeps the same dedupe key when the secret moves to another line', function () {
    $scan = function (string $patch, int $line) {
        fakePullFiles([['filename' => 'config/app.php', 'status' => 'modified', 'patch' => $patch]]);
        fakeGitleaksScan([awsHit(line: $line)]);

        return app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main')->hits[0]->dedupeKey();
    };

    $first = $scan("@@ -1 +1,2 @@\n <?php\n+AWS_KEY=AKIAABCDEFGHIJKLMNOP", 2);
    $moved = $scan("@@ -1 +1,4 @@\n <?php\n+// a\n+// b\n+AWS_KEY=AKIAABCDEFGHIJKLMNOP", 4);

    expect($moved)->toBe($first);
});

it('reads config and ignore files from the target branch, not the pull request', function () {
    fakePullFiles(
        [['filename' => 'a.env', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+X=1"]],
        ['.gitleaks.toml' => "[extend]\nuseDefault = true", '.gitleaksignore' => 'a.env:generic:1'],
    );
    fakeGitleaksScan([], $seen);

    app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');

    expect($seen['--config'])->toBe("[extend]\nuseDefault = true")
        ->and($seen['--gitleaks-ignore-path'])->toBe('a.env:generic:1');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'contents/.gitleaks.toml') && str_contains($r->url(), 'ref=main'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'contents/') && ! str_contains($r->url(), 'ref=main'));
});

it('skips files without a patch, removed files and unsafe paths', function () {
    fakePullFiles([
        ['filename' => 'logo.png', 'status' => 'added'],
        ['filename' => 'old.php', 'status' => 'removed', 'patch' => "@@ -1 +0,0 @@\n-x"],
        ['filename' => '../../etc/evil', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+x"],
        ['filename' => 'ok.php', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+x"],
    ]);
    fakeGitleaksScan([], $seen);

    $result = app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');

    expect($result->filesScanned)->toBe(1)
        ->and($result->filesSkipped)->toBe(3)
        // A removed file is gone, not unseen, so its earlier findings may resolve.
        ->and($result->skippedPaths)->toBe(['logo.png'])
        ->and(array_keys($seen['files']))->toBe(['ok.php']);
});

it('does not run gitleaks when nothing was added', function () {
    fakePullFiles([['filename' => 'a.php', 'status' => 'modified', 'patch' => "@@ -1 +0,0 @@\n-x"]]);
    Process::fake();

    $result = app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');

    expect($result->hits)->toBe([]);
    Process::assertNothingRan();
});

it('removes the workspace afterwards', function () {
    fakePullFiles([['filename' => 'a.php', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+x"]]);
    fakeGitleaksScan([], $seen);

    app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');

    expect(is_dir($seen['path']))->toBeFalse();
});

it('always passes a config and ignore file from outside the scan root', function () {
    fakePullFiles([['filename' => 'a.env', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+X=1"]]);
    fakeGitleaksScan([], $seen);

    app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');

    expect($seen['--config'])->toBe("[extend]\nuseDefault = true\n")
        ->and($seen['--gitleaks-ignore-path'])->toBe('')
        ->and($seen['--config-path'])->not->toStartWith($seen['path'].'/')
        ->and($seen['--gitleaks-ignore-path-path'])->not->toStartWith($seen['path'].'/');
});

it('mirrors files gitleaks would load as config under a neutral name and maps hits back', function () {
    $secret = "@@ -0,0 +1 @@\n+AWS_KEY=AKIAABCDEFGHIJKLMNOP";

    fakePullFiles([
        ['filename' => '.gitleaksignore', 'status' => 'added', 'patch' => $secret],
        ['filename' => '.gitleaks.toml', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+[allowlist]"],
        ['filename' => 'nested/.GITLEAKS.yaml', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+x: 1"],
        ['filename' => '.gitleaksignore.pulllens-mirror', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+y"],
    ]);
    fakeGitleaksScan([awsHit('.gitleaksignore.pulllens-mirror', 1)], $seen);

    $result = app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');

    expect(array_keys($seen['files']))->toEqualCanonicalizing([
        '.gitleaksignore.pulllens-mirror',
        '.gitleaks.toml.pulllens-mirror',
        'nested/.GITLEAKS.yaml.pulllens-mirror',
        '.gitleaksignore.pulllens-mirror.pulllens-mirror',
    ])
        ->and($seen['files']['.gitleaksignore.pulllens-mirror'])->toBe('AWS_KEY=AKIAABCDEFGHIJKLMNOP')
        ->and($result->hits)->toHaveCount(1)
        ->and($result->hits[0]->file)->toBe('.gitleaksignore')
        ->and($result->hits[0]->dedupeKey())->toStartWith('gitleaks:aws-access-token:.gitleaksignore:');
});

it('writes a far-down added line without holding the whole file in memory', function () {
    fakePullFiles([[
        'filename' => 'huge.sql', 'status' => 'modified',
        'patch' => "@@ -4999999,1 +4999999,2 @@\n last\n+AWS_KEY=AKIAABCDEFGHIJKLMNOP",
    ]]);

    $mirror = null;
    Process::fake(function (PendingProcess $process) use (&$mirror) {
        $command = (array) $process->command;

        if (in_array('version', $command, true)) {
            return Process::result('8.28.0');
        }

        $path = $process->path.'/huge.sql';
        $handle = fopen($path, 'rb');
        fseek($handle, -64, SEEK_END);
        $mirror = ['size' => filesize($path), 'tail' => fread($handle, 64)];
        fclose($handle);

        file_put_contents($command[array_search('--report-path', $command, true) + 1], '[]');

        return Process::result('');
    });

    memory_reset_peak_usage();
    $before = memory_get_usage();

    app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');

    $content = 'AWS_KEY=AKIAABCDEFGHIJKLMNOP';

    expect(memory_get_peak_usage() - $before)->toBeLessThan(20 * 1024 * 1024)
        // Line 5,000,000 is preceded by 4,999,999 line breaks and has none after it.
        ->and($mirror['size'])->toBe(4_999_999 + strlen($content))
        ->and(substr($mirror['tail'], -strlen($content) - 1))->toBe("\n".$content);
});

it('sweeps stale workspaces through the sweeper at the start of a scan', function () {
    $sweeper = Mockery::mock(SecretScanWorkspaceSweeper::class);
    $sweeper->shouldReceive('sweep')->once()->andReturn(0);
    app()->instance(SecretScanWorkspaceSweeper::class, $sweeper);

    fakePullFiles([['filename' => 'a.php', 'status' => 'modified', 'patch' => "@@ -1 +0,0 @@\n-x"]]);
    Process::fake();

    app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');
});
