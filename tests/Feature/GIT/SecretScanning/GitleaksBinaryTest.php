<?php

use App\Services\Git\SecretScanning\GitleaksRunner;
use App\Services\Git\SecretScanning\SecretScanner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    if (! app(GitleaksRunner::class)->isAvailable()) {
        $this->markTestSkipped('gitleaks is not installed.');
    }
});

// A well-known documentation key split so this file itself does not trip scanners.
function binaryTestSecret(): string
{
    return 'AKIA'.'IOSFODNN7'.'REALKEY';
}

it('finds and redacts a real AWS key with the installed gitleaks', function () {
    $dir = storage_path('app/secret-scans/binary-test-'.uniqid());
    File::ensureDirectoryExists($dir.'/src/config');
    File::ensureDirectoryExists($dir.'/config');
    file_put_contents($dir.'/src/config/aws.php', "\n\n\$key = '".binaryTestSecret()."';\n");
    file_put_contents($dir.'/config/gitleaks.toml', "[extend]\nuseDefault = true\n");
    file_put_contents($dir.'/config/gitleaksignore', '');

    try {
        $hits = app(GitleaksRunner::class)->run($dir.'/src', $dir.'/config/gitleaks.toml', $dir.'/config/gitleaksignore');
    } finally {
        File::deleteDirectory($dir);
    }

    expect($hits)->not->toBeEmpty()
        ->and($hits[0]->file)->toBe('config/aws.php')
        ->and($hits[0]->line)->toBe(3)
        ->and($hits[0]->match)->not->toContain('IOSFODNN7');
});

it('does not let a pull request allowlist its own secret with .gitleaksignore or .gitleaks.toml', function () {
    $fingerprint = 'config/aws.php:aws-access-token:3';
    $permissive = "[[allowlists]]\ndescription = \"everything\"\npaths = ['''.*''']\nregexes = ['''.*''']";

    // Control: gitleaks really does honour a .gitleaksignore sitting in the scan root,
    // even with --config and --gitleaks-ignore-path given, so the fixture below is live.
    $dir = storage_path('app/secret-scans/binary-test-'.uniqid());
    File::ensureDirectoryExists($dir.'/src/config');
    File::ensureDirectoryExists($dir.'/config');
    file_put_contents($dir.'/src/config/aws.php', "\n\n\$key = '".binaryTestSecret()."';");
    file_put_contents($dir.'/src/.gitleaksignore', $fingerprint);
    file_put_contents($dir.'/config/gitleaks.toml', "[extend]\nuseDefault = true\n");
    file_put_contents($dir.'/config/gitleaksignore', '');

    try {
        $unprotected = app(GitleaksRunner::class)->run($dir.'/src', $dir.'/config/gitleaks.toml', $dir.'/config/gitleaksignore');
    } finally {
        File::deleteDirectory($dir);
    }

    expect(collect($unprotected)->where('file', 'config/aws.php'))->toBeEmpty();

    // The same pull request, scanned the way PullLens scans it.
    $patch = fn (array $lines) => '@@ -0,0 +1,'.count($lines)." @@\n+".implode("\n+", $lines);

    Http::fake([
        'api.github.com/repos/octocat/app/pulls/7/files*' => Http::response([
            ['filename' => 'config/aws.php', 'status' => 'added', 'patch' => $patch(['', '', "\$key = '".binaryTestSecret()."';"])],
            ['filename' => '.gitleaksignore', 'status' => 'added', 'patch' => $patch([$fingerprint])],
            ['filename' => '.gitleaks.toml', 'status' => 'added', 'patch' => $patch(explode("\n", $permissive))],
        ]),
        'api.github.com/repos/octocat/app/contents/*' => Http::response([], 404),
    ]);

    $result = app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');

    $leak = collect($result->hits)->firstWhere('file', 'config/aws.php');

    expect($leak)->not->toBeNull()
        ->and($leak->ruleId)->toBe('aws-access-token')
        ->and($leak->line)->toBe(3)
        ->and($leak->match)->not->toContain('IOSFODNN7');
});

it('ignores an inline gitleaks:allow comment the pull request added on its own secret line', function () {
    $dir = storage_path('app/secret-scans/binary-test-'.uniqid());
    File::ensureDirectoryExists($dir.'/src/config');
    File::ensureDirectoryExists($dir.'/config');
    file_put_contents($dir.'/src/config/aws.php', "\n\n\$key = '".binaryTestSecret()."'; // gitleaks:allow\n");
    file_put_contents($dir.'/config/gitleaks.toml', "[extend]\nuseDefault = true\n");
    file_put_contents($dir.'/config/gitleaksignore', '');

    $reportPath = $dir.'/control.report.json';
    $binary = (string) config('pulllens.secret_scanning.binary', 'gitleaks');

    try {
        // Control: gitleaks really does honour an inline `gitleaks:allow` comment by
        // default, even with --config and --gitleaks-ignore-path given, so the PR's own
        // comment would hide its own secret unless the runner passes the ignore flag.
        $control = Process::path($dir.'/src')->run([
            $binary, 'dir', '.',
            '--redact',
            '--no-banner',
            '--no-color',
            '--log-level', 'error',
            '--exit-code', '0',
            '--report-format', 'json',
            '--report-path', $reportPath,
            '--config', $dir.'/config/gitleaks.toml',
            '--gitleaks-ignore-path', $dir.'/config/gitleaksignore',
        ]);

        expect($control->successful())->toBeTrue();
        expect(json_decode((string) file_get_contents($reportPath), true))->toBe([]);

        $hits = app(GitleaksRunner::class)->run($dir.'/src', $dir.'/config/gitleaks.toml', $dir.'/config/gitleaksignore');
    } finally {
        File::deleteDirectory($dir);
    }

    expect($hits)->not->toBeEmpty()
        ->and($hits[0]->file)->toBe('config/aws.php')
        ->and($hits[0]->line)->toBe(3)
        ->and($hits[0]->match)->not->toContain('IOSFODNN7');
});
