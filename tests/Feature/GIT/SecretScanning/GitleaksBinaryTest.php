<?php

use App\Services\Git\SecretScanning\GitleaksRunner;
use Illuminate\Support\Facades\File;

it('finds and redacts a real AWS key with the installed gitleaks', function () {
    $runner = app(GitleaksRunner::class);

    if (! $runner->isAvailable()) {
        $this->markTestSkipped('gitleaks is not installed.');
    }

    $dir = storage_path('app/secret-scans/binary-test-'.uniqid());
    File::ensureDirectoryExists($dir.'/config');
    // A well-known documentation key split so this file itself does not trip scanners.
    file_put_contents($dir.'/config/aws.php', "\n\n\$key = '".'AKIA'.'IOSFODNN7'.'REALKEY'."';\n");

    try {
        $hits = $runner->run($dir);
    } finally {
        File::deleteDirectory($dir);
    }

    expect($hits)->not->toBeEmpty()
        ->and($hits[0]->file)->toBe('config/aws.php')
        ->and($hits[0]->line)->toBe(3)
        ->and($hits[0]->match)->not->toContain('IOSFODNN7');
});
