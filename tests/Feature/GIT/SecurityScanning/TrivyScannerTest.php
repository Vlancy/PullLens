<?php

use App\Services\Git\VulnerabilityScanning\TrivyFailed;
use App\Services\Git\VulnerabilityScanning\TrivyScanner;
use Illuminate\Http\Client\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Fake trivy: record what each run saw, and answer head and base runs with fixtures.
 */
function fakeTrivyScans(string $headFixture, ?string $baseFixture = null, ?array &$seen = null): void
{
    $seen = [];

    Process::fake(function (PendingProcess $process) use ($headFixture, $baseFixture, &$seen) {
        $command = (array) $process->command;

        if (in_array('--version', $command, true)) {
            return Process::result('Version: 0.74.0');
        }

        $side = basename($process->path);
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($process->path, FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[substr($file->getPathname(), strlen($process->path) + 1)] = strlen(file_get_contents($file->getPathname()));
        }

        $seen[$side] = ['path' => $process->path, 'files' => $files];
        $fixture = $side === 'base' ? ($baseFixture ?? 'empty.json') : $headFixture;
        file_put_contents($command[array_search('--output', $command, true) + 1], file_get_contents(base_path("tests/Fixtures/Trivy/{$fixture}")));

        return Process::result('');
    });
}

/**
 * Fake the PR file list and raw contents at the head (head-sha-1) and base (main); extra fakes win over both.
 */
function fakeTrivyGitHub(array $files, array $headContents, array $baseContents = [], array $extra = []): void
{
    app()->forgetInstance(Factory::class);
    Http::clearResolvedInstance(Factory::class);

    $fakes = $extra + ['api.github.com/repos/octocat/app/pulls/7/files*' => Http::response($files)];

    foreach ($headContents as $path => $body) {
        $fakes["api.github.com/repos/octocat/app/contents/{$path}?ref=head-sha-1"] = Http::response($body);
    }

    foreach ($baseContents as $path => $body) {
        $fakes["api.github.com/repos/octocat/app/contents/{$path}?ref=main"] = Http::response($body);
    }

    Http::fake($fakes + ['api.github.com/repos/octocat/app/contents/*' => Http::response(['message' => 'Not Found'], 404)]);
}

it('scans the head and the base copies and keeps what the pull request introduces', function () {
    fakeTrivyGitHub([['filename' => 'composer.lock', 'status' => 'modified']], ['composer.lock' => '{"packages":[]}'], ['composer.lock' => '{"packages":[]}']);
    fakeTrivyScans('composer-head.json', 'composer-base.json', $seen);

    $result = app(TrivyScanner::class)->scan('token', 'octocat', 'app', 7, 'head-sha-1', 'main');

    expect(array_keys($seen))->toBe(['head', 'base'])
        ->and($seen['head']['files'])->toHaveKey('composer.lock')
        ->and($seen['base']['files'])->toHaveKey('composer.lock')
        ->and(array_map(fn ($i) => $i->identity(), $result->issues))->toBe(['vuln:composer.lock:guzzlehttp/psr7:1.8.3:CVE-2022-24775'])
        ->and($result->filesScanned)->toBe(1)
        ->and($result->targets)->toBe(1);
});

it('hands trivy the whole of a lockfile over one megabyte', function () {
    $big = str_repeat('x', 1_500_000);
    fakeTrivyGitHub([['filename' => 'package-lock.json', 'status' => 'added']], ['package-lock.json' => $big]);
    fakeTrivyScans('npm-no-location.json', null, $seen);

    app(TrivyScanner::class)->scan('token', 'octocat', 'app', 7, 'head-sha-1', 'main');

    expect($seen['head']['files']['package-lock.json'])->toBe(1_500_000)
        ->and($seen)->not->toHaveKey('base');
});

it('reads a renamed file base copy from its previous name, at its new path', function () {
    fakeTrivyGitHub(
        [['filename' => 'api/composer.lock', 'previous_filename' => 'composer.lock', 'status' => 'renamed']],
        ['api/composer.lock' => '{}'],
        ['composer.lock' => '{}'],
    );
    fakeTrivyScans('empty.json', 'empty.json', $seen);

    app(TrivyScanner::class)->scan('token', 'octocat', 'app', 7, 'head-sha-1', 'main');

    expect($seen['base']['files'])->toHaveKey('api/composer.lock');
});

it('counts a file it could not read as skipped', function () {
    fakeTrivyGitHub([['filename' => 'composer.lock', 'status' => 'modified'], ['filename' => 'Dockerfile', 'status' => 'added']], ['Dockerfile' => 'FROM alpine']);
    fakeTrivyScans('dockerfile.json');

    $result = app(TrivyScanner::class)->scan('token', 'octocat', 'app', 7, 'head-sha-1', 'main');

    expect($result->skippedPaths)->toBe(['composer.lock'])
        ->and($result->filesSkipped)->toBe(1)
        ->and($result->filesScanned)->toBe(1);
});

it('skips a file whose base copy could not be fetched instead of treating it as added', function () {
    fakeTrivyGitHub(
        [['filename' => 'composer.lock', 'status' => 'modified'], ['filename' => 'Dockerfile', 'status' => 'added']],
        ['composer.lock' => '{"packages":[]}', 'Dockerfile' => 'FROM alpine'],
        extra: ['api.github.com/repos/octocat/app/contents/composer.lock?ref=main' => Http::response(['message' => 'Server Error'], 500)],
    );
    fakeTrivyScans('dockerfile.json', null, $seen);

    $result = app(TrivyScanner::class)->scan('token', 'octocat', 'app', 7, 'head-sha-1', 'main');

    expect($result->skippedPaths)->toBe(['composer.lock'])
        ->and($result->filesSkipped)->toBe(1)
        ->and($result->filesScanned)->toBe(1)
        ->and($seen['head']['files'])->not->toHaveKey('composer.lock')
        ->and($seen)->not->toHaveKey('base')
        ->and(array_filter($result->issues, fn ($i) => str_contains($i->identity(), 'composer.lock')))->toBe([]);
});

it('skips a file whose head copy could not be fetched', function () {
    fakeTrivyGitHub(
        [['filename' => 'composer.lock', 'status' => 'modified']],
        [],
        ['composer.lock' => '{"packages":[]}'],
        ['api.github.com/repos/octocat/app/contents/composer.lock?ref=head-sha-1' => Http::response(['message' => 'Server Error'], 502)],
    );
    Process::fake();

    $result = app(TrivyScanner::class)->scan('token', 'octocat', 'app', 7, 'head-sha-1', 'main');

    expect($result->skippedPaths)->toBe(['composer.lock'])
        ->and($result->filesScanned)->toBe(0)
        ->and($result->issues)->toBe([]);
    Process::assertNothingRan();
});

it('treats a file missing at the base as added and reports all of its head issues', function () {
    fakeTrivyGitHub([['filename' => 'composer.lock', 'status' => 'modified']], ['composer.lock' => '{"packages":[]}']);
    fakeTrivyScans('composer-head.json', 'composer-base.json', $seen);

    $result = app(TrivyScanner::class)->scan('token', 'octocat', 'app', 7, 'head-sha-1', 'main');

    expect($seen)->not->toHaveKey('base')
        ->and($result->skippedPaths)->toBe([])
        ->and($result->filesScanned)->toBe(1)
        ->and(array_map(fn ($i) => $i->vulnerabilityId, $result->issues))->toBe(['CVE-2022-24775', 'CVE-2022-24894']);
});

it('does not run trivy when no scannable file changed', function () {
    fakeTrivyGitHub([['filename' => 'app/Models/User.php', 'status' => 'modified']], []);
    Process::fake();

    $result = app(TrivyScanner::class)->scan('token', 'octocat', 'app', 7, 'head-sha-1', 'main');

    expect($result->issues)->toBe([])->and($result->targets)->toBe(0);
    Process::assertNothingRan();
});

it('removes its workspace afterwards', function () {
    fakeTrivyGitHub([['filename' => 'Dockerfile', 'status' => 'added']], ['Dockerfile' => 'FROM alpine']);
    fakeTrivyScans('dockerfile.json', null, $seen);

    app(TrivyScanner::class)->scan('token', 'octocat', 'app', 7, 'head-sha-1', 'main');

    expect(is_dir(dirname($seen['head']['path'])))->toBeFalse();
});

it('logs a file it could not fetch, without its content', function () {
    Log::spy();
    fakeTrivyGitHub(
        [['filename' => 'composer.lock', 'status' => 'modified']],
        [],
        [],
        ['api.github.com/repos/octocat/app/contents/composer.lock?ref=head-sha-1' => Http::response(['message' => 'Server Error'], 502)],
    );
    Process::fake();

    app(TrivyScanner::class)->scan('token', 'octocat', 'app', 7, 'head-sha-1', 'main');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'vulnerability_scan.target_fetch_failed'
        && $context['path'] === 'composer.lock' && str_contains($context['error'], '502') && array_keys($context) === ['path', 'error']);
});

it('removes its workspace when trivy fails', function () {
    fakeTrivyGitHub([['filename' => 'Dockerfile', 'status' => 'added']], ['Dockerfile' => 'FROM alpine']);
    $paths = [];
    Process::fake(function (PendingProcess $process) use (&$paths) {
        $paths[] = $process->path;

        return Process::result('', 'db locked', 1);
    });

    expect(fn () => app(TrivyScanner::class)->scan('token', 'octocat', 'app', 7, 'head-sha-1', 'main'))->toThrow(TrivyFailed::class);

    expect($paths)->not->toBeEmpty()->and(is_dir(dirname($paths[0])))->toBeFalse();
});

it('resolves the base ref only when there is a file to scan', function (array $files, int $calls) {
    fakeTrivyGitHub($files, ['Dockerfile' => 'FROM alpine']);
    fakeTrivyScans('dockerfile.json');
    $resolved = 0;

    app(TrivyScanner::class)->scan('token', 'octocat', 'app', 7, 'head-sha-1', function () use (&$resolved) {
        $resolved++;

        return 'main';
    });

    expect($resolved)->toBe($calls);
})->with([
    'no target' => [[['filename' => 'README.md', 'status' => 'modified']], 0],
    'one target' => [[['filename' => 'Dockerfile', 'status' => 'modified']], 1],
]);

it('scans at most the file limit and leaves the rest alone', function () {
    $limit = TrivyScanner::MAX_TARGETS;
    $files = array_map(fn (int $i) => ['filename' => "svc{$i}/Dockerfile", 'status' => 'added'], range(1, $limit + 2));
    fakeTrivyGitHub($files, [], [], ['api.github.com/repos/octocat/app/contents/*' => Http::response('FROM alpine')]);
    fakeTrivyScans('empty.json', null, $seen);

    $result = app(TrivyScanner::class)->scan('token', 'octocat', 'app', 7, 'head-sha-1', 'main');

    expect($result->filesScanned)->toBe($limit)
        ->and($result->filesSkipped)->toBe(0)
        ->and($result->skippedPaths)->toBe([])
        ->and($result->limitedPaths)->toBe(['svc'.($limit + 1).'/Dockerfile', 'svc'.($limit + 2).'/Dockerfile'])
        ->and($result->targets)->toBe($limit + 2)
        ->and(count($seen['head']['files']))->toBe($limit);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'svc'.($limit + 1).'/'));
});
