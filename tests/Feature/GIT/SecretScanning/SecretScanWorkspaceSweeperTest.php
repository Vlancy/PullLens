<?php

use App\Services\Git\SecretScanning\SecretScanWorkspaceSweeper;

it('removes workspaces and orphaned reports an earlier scan left behind, keeping fresh ones', function () {
    $root = storage_path('app/secret-scans');
    $stale = $root.'/stale-'.uniqid();
    $fresh = $root.'/fresh-'.uniqid();
    $staleReport = $root.'/stale-'.uniqid().'.report.json';

    mkdir($stale.'/src', 0777, true);
    file_put_contents($stale.'/src/a.php', 'AWS_KEY=AKIAABCDEFGHIJKLMNOP');
    mkdir($fresh);
    file_put_contents($staleReport, '[]');
    touch($stale, time() - 16 * 60);
    touch($staleReport, time() - 16 * 60);

    try {
        $removed = app(SecretScanWorkspaceSweeper::class)->sweep();

        expect($removed)->toBe(2)
            ->and(is_dir($stale))->toBeFalse()
            ->and(is_file($staleReport))->toBeFalse()
            ->and(is_dir($fresh))->toBeTrue();
    } finally {
        @rmdir($fresh);
    }
});

it('reports nothing to remove when every workspace is fresh', function () {
    $root = storage_path('app/secret-scans');
    $fresh = $root.'/fresh-'.uniqid();
    mkdir($fresh, 0777, true);

    try {
        expect(app(SecretScanWorkspaceSweeper::class)->sweep())->toBe(0)
            ->and(is_dir($fresh))->toBeTrue();
    } finally {
        @rmdir($fresh);
    }
});
