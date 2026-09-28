<?php

use App\Services\Git\SecretScanning\GitleaksFailed;
use App\Services\Git\SecretScanning\GitleaksHit;
use App\Services\Git\SecretScanning\GitleaksRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * Fake the gitleaks binary: `version` prints a version, and a scan writes $report
 * to the path passed after --report-path, the way the real binary does.
 */
function fakeGitleaksReport(?string $report, int $exitCode = 0): void
{
    Process::fake(function (PendingProcess $process) use ($report, $exitCode) {
        $command = (array) $process->command;

        if (in_array('version', $command, true)) {
            return Process::result('8.28.0');
        }

        $path = $command[array_search('--report-path', $command, true) + 1];

        if ($report !== null) {
            file_put_contents($path, $report);
        }

        return Process::result('', 'boom', $exitCode);
    });
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/gitleaks-runner-'.uniqid();
    mkdir($this->dir);
});

afterEach(fn () => @rmdir($this->dir));

it('runs gitleaks redacted over the directory and parses its report', function () {
    fakeGitleaksReport(json_encode([[
        'RuleID' => 'aws-access-token',
        'Description' => 'AWS Access Key',
        'File' => './config/app.php',
        'StartLine' => 3,
        'Match' => 'AWS_KEY=REDACTED',
        'Secret' => 'REDACTED',
    ]]));

    $hits = app(GitleaksRunner::class)->run($this->dir, '/tmp/x/gitleaks.toml', '/tmp/x/gitleaksignore');

    expect($hits)->toEqual([new GitleaksHit('aws-access-token', 'AWS Access Key', 'config/app.php', 3, 'AWS_KEY=REDACTED')]);

    Process::assertRan(function (PendingProcess $process) {
        $command = (array) $process->command;

        return $command[0] === 'gitleaks'
            && $command[1] === 'dir'
            && $command[2] === '.'
            && in_array('--redact', $command, true)
            && $process->path === $this->dir;
    });
});

it('always passes the config and ignore file it is given', function () {
    fakeGitleaksReport('[]');

    app(GitleaksRunner::class)->run($this->dir, '/tmp/x/.gitleaks.toml', '/tmp/x/.gitleaksignore');

    Process::assertRan(function (PendingProcess $process) {
        $command = implode(' ', (array) $process->command);

        return str_contains($command, '--config /tmp/x/.gitleaks.toml')
            && str_contains($command, '--gitleaks-ignore-path /tmp/x/.gitleaksignore');
    });
});

it('refuses a config or ignore file inside the scanned directory', function (bool $configInside) {
    fakeGitleaksReport('[]');

    $inside = $this->dir.'/sub/.gitleaks.toml';

    app(GitleaksRunner::class)->run($this->dir, $configInside ? $inside : '/tmp/x/c', $configInside ? '/tmp/x/i' : $inside);
})->with([
    'config' => [true],
    'ignore' => [false],
])->throws(GitleaksFailed::class, 'inside the scanned directory');

it('never lets the scan outlive the job, whatever the configured timeout', function (int $configured, int $effective) {
    config()->set('pulllens.secret_scanning.timeout', $configured);
    fakeGitleaksReport('[]');

    app(GitleaksRunner::class)->run($this->dir, '/tmp/x/c', '/tmp/x/i');

    Process::assertRan(fn (PendingProcess $process) => ! in_array('version', (array) $process->command, true)
        && $process->timeout === $effective);
})->with([
    'too long' => [900, 150],
    'in range' => [60, 60],
    'too short' => [1, 5],
]);

it('returns no hits for an empty report', function () {
    fakeGitleaksReport('[]');

    expect(app(GitleaksRunner::class)->run($this->dir, '/tmp/x/c', '/tmp/x/i'))->toBe([]);
});

it('fails when gitleaks exits non-zero', function () {
    fakeGitleaksReport(null, 2);

    app(GitleaksRunner::class)->run($this->dir, '/tmp/x/c', '/tmp/x/i');
})->throws(GitleaksFailed::class, 'gitleaks exited with code 2');

it('fails when the report is not json', function () {
    fakeGitleaksReport('not json');

    app(GitleaksRunner::class)->run($this->dir, '/tmp/x/c', '/tmp/x/i');
})->throws(GitleaksFailed::class, 'not valid JSON');

it('reports the binary as missing when it cannot run', function () {
    Process::fake(fn () => Process::result('', 'not found', 127));

    expect(app(GitleaksRunner::class)->isAvailable())->toBeFalse();
});

it('reads the gitleaks version', function () {
    fakeGitleaksReport('[]');

    expect(app(GitleaksRunner::class)->version())->toBe('8.28.0');
});
