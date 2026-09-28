<?php

use App\Services\Git\VulnerabilityScanning\TrivyDatabase;
use App\Services\Git\VulnerabilityScanning\TrivyFailed;
use App\Services\Git\VulnerabilityScanning\TrivyRunner;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    if (! app(TrivyRunner::class)->isAvailable()) {
        $this->markTestSkipped('trivy is not installed.');
    }

    $this->cache = storage_path('app/trivy-binary-test-cache');
    config()->set('pulllens.vulnerability_scanning.cache_dir', $this->cache);

    if (! app(TrivyDatabase::class)->isFresh()) {
        try {
            app(TrivyDatabase::class)->refresh();
        } catch (TrivyFailed $e) {
            $this->markTestSkipped('the vulnerability database could not be downloaded: '.$e->getMessage());
        }
    }
});

it('finds a known CVE in a composer.lock and a root user in a Dockerfile', function () {
    $dir = storage_path('app/trivy-scans/binary-test-'.uniqid());
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/composer.lock', json_encode(['packages' => [['name' => 'guzzlehttp/psr7', 'version' => '1.8.3', 'type' => 'library']], 'packages-dev' => []]));
    file_put_contents($dir.'/Dockerfile', "FROM alpine:3.20\nRUN echo hello\n");

    try {
        $report = app(TrivyRunner::class)->run($dir);
    } finally {
        File::deleteDirectory($dir);
    }

    expect(collect($report->vulnerabilities)->pluck('vulnerabilityId'))->toContain('CVE-2022-24775')
        ->and(collect($report->misconfigurations)->pluck('checkId'))->toContain('DS-0002');
});
