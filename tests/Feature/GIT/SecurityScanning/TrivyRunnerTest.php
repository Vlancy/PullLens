<?php

use App\Services\Git\VulnerabilityScanning\TrivyFailed;
use App\Services\Git\VulnerabilityScanning\TrivyRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * Fake the trivy binary: --version prints a version, and a scan writes $fixture
 * (a tests/Fixtures/Trivy file, or literal text) to the path after --output.
 */
function fakeTrivyReport(?string $fixture, int $exitCode = 0): void
{
    Process::fake(function (PendingProcess $process) use ($fixture, $exitCode) {
        $command = (array) $process->command;

        if (in_array('--version', $command, true)) {
            return Process::result("Version: 0.74.0\nVulnerability DB:\n  Version: 2\n");
        }

        if ($fixture !== null) {
            // A name ending in .json is a fixture file; anything else is written verbatim.
            file_put_contents($command[array_search('--output', $command, true) + 1],
                str_ends_with($fixture, '.json') ? file_get_contents(base_path("tests/Fixtures/Trivy/{$fixture}")) : $fixture);
        }

        return Process::result('', 'boom', $exitCode);
    });
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/trivy-runner-'.uniqid();
    mkdir($this->dir);
    config()->set('pulllens.vulnerability_scanning.cache_dir', '/var/cache/trivy');
});

afterEach(fn () => @rmdir($this->dir));

it('runs trivy offline over the directory with the vuln and misconfig scanners only', function () {
    fakeTrivyReport('empty.json');

    app(TrivyRunner::class)->run($this->dir);

    Process::assertRan(function (PendingProcess $process) {
        $command = (array) $process->command;
        $after = fn (string $flag) => $command[array_search($flag, $command, true) + 1];

        return $command[0] === 'trivy' && $command[1] === 'fs' && $command[2] === '.'
            && $process->path === $this->dir
            && $after('--scanners') === 'vuln,misconfig'
            && $after('--severity') === 'MEDIUM,HIGH,CRITICAL'
            && $after('--format') === 'json'
            && $after('--cache-dir') === '/var/cache/trivy'
            && $after('--exit-code') === '0'
            && collect(['--skip-db-update', '--skip-java-db-update', '--skip-check-update', '--skip-vex-repo-update',
                '--offline-scan', '--skip-version-check', '--disable-telemetry', '--no-progress', '--quiet', '--list-all-pkgs'])
                ->every(fn ($flag) => in_array($flag, $command, true))
            && ! str_contains(implode(' ', $command), 'secret');
    });
});

it('maps vulnerabilities to the line of their package', function () {
    fakeTrivyReport('composer-head.json');

    $report = app(TrivyRunner::class)->run($this->dir);

    expect($report->vulnerabilities)->toHaveCount(2)
        ->and($report->vulnerabilities[0]->file)->toBe('composer.lock')
        ->and($report->vulnerabilities[0]->vulnerabilityId)->toBe('CVE-2022-24775')
        ->and($report->vulnerabilities[0]->packageName)->toBe('guzzlehttp/psr7')
        ->and($report->vulnerabilities[0]->installedVersion)->toBe('1.8.3')
        ->and($report->vulnerabilities[0]->fixedVersion)->toBe('1.8.4')
        ->and($report->vulnerabilities[0]->severity)->toBe('HIGH')
        ->and($report->vulnerabilities[0]->url)->toBe('https://avd.aquasec.com/nvd/cve-2022-24775')
        ->and($report->vulnerabilities[0]->line)->toBe(130)
        ->and($report->vulnerabilities[0]->identity())->toBe('vuln:composer.lock:guzzlehttp/psr7:1.8.3:CVE-2022-24775');
});

it('leaves the line empty when the package has no location', function () {
    fakeTrivyReport('npm-no-location.json');

    expect(app(TrivyRunner::class)->run($this->dir)->vulnerabilities[0]->line)->toBeNull();
});

it('keeps failed misconfigurations only, with their resource and line when trivy has one', function () {
    fakeTrivyReport('dockerfile.json');

    $configs = app(TrivyRunner::class)->run($this->dir)->misconfigurations;

    expect($configs)->toHaveCount(3)
        ->and($configs[0]->checkId)->toBe('DS-0002')
        ->and($configs[0]->line)->toBeNull()
        ->and($configs[0]->identity())->toBe('config:Dockerfile:DS-0002:-')
        ->and($configs[1]->line)->toBe(1)
        ->and($configs[1]->resource)->toBe('from alpine')
        ->and($configs[1]->identity())->toBe('config:Dockerfile:DS-0001:from alpine');
});

it('falls back to AVDID when a misconfiguration has no ID', function () {
    fakeTrivyReport('dockerfile.json');

    $config = app(TrivyRunner::class)->run($this->dir)->misconfigurations[2];

    expect($config->checkId)->toBe('AVD-DS-0026')
        ->and($config->identity())->toBe('config:Dockerfile:AVD-DS-0026:-');
});

it('returns an empty report when nothing was found', function () {
    fakeTrivyReport('empty.json');

    $report = app(TrivyRunner::class)->run($this->dir);

    expect($report->vulnerabilities)->toBe([])->and($report->misconfigurations)->toBe([]);
});

it('fails when trivy exits non-zero', function () {
    fakeTrivyReport(null, 1);

    app(TrivyRunner::class)->run($this->dir);
})->throws(TrivyFailed::class, 'trivy exited with code 1');

it('fails when the report is not json', function () {
    fakeTrivyReport('not json');

    app(TrivyRunner::class)->run($this->dir);
})->throws(TrivyFailed::class, 'not valid JSON');

it('keeps both runs of a scan inside the job, whatever the configured timeout', function (int $configured, int $effective) {
    config()->set('pulllens.vulnerability_scanning.timeout', $configured);
    fakeTrivyReport('empty.json');

    app(TrivyRunner::class)->run($this->dir);

    Process::assertRan(fn (PendingProcess $process) => in_array('fs', (array) $process->command, true)
        && $process->timeout === $effective
        && in_array("{$effective}s", (array) $process->command, true));
})->with([
    'mis-set to ten minutes' => [600, 80],
    'in range' => [60, 60],
    'too short' => [1, 5],
]);

it('reads the trivy version and reports a missing binary', function () {
    fakeTrivyReport('empty.json');
    expect(app(TrivyRunner::class)->version())->toBe('0.74.0');

    Process::fake(fn () => Process::result('', 'not found', 127));
    expect(app(TrivyRunner::class)->isAvailable())->toBeFalse();
});
