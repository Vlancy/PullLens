<?php

use App\Services\Git\VulnerabilityScanning\TrivyDatabase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->cache = storage_path('framework/testing/trivy-cache-'.uniqid());
    config()->set('pulllens.vulnerability_scanning.cache_dir', $this->cache);
});

afterEach(fn () => File::deleteDirectory($this->cache));

/** Write a fake `<cache>/db/metadata.json`, as Trivy would after downloading its database. */
function writeTrivyMetadata(string $cache, array $metadata): void
{
    File::ensureDirectoryExists($cache.'/db');
    file_put_contents($cache.'/db/metadata.json', json_encode($metadata));
}

it('is fresh when the database was updated recently', function () {
    writeTrivyMetadata($this->cache, ['Version' => 2, 'UpdatedAt' => now()->subHours(7)->toIso8601String()]);

    expect(app(TrivyDatabase::class)->isFresh())->toBeTrue();
});

it('is stale when the database is missing, unreadable or too old', function (?array $metadata) {
    if ($metadata !== null) {
        writeTrivyMetadata($this->cache, $metadata);
    }

    expect(app(TrivyDatabase::class)->isFresh())->toBeFalse();
})->with([
    'never downloaded' => [null],
    'older than three days' => [['UpdatedAt' => now()->subHours(73)->toIso8601String()]],
    'zero time' => [['UpdatedAt' => '0001-01-01T00:00:00Z']],
]);

it('falls back to when the database was downloaded', function () {
    writeTrivyMetadata($this->cache, ['UpdatedAt' => '0001-01-01T00:00:00Z', 'DownloadedAt' => now()->subHour()->toIso8601String()]);

    expect(app(TrivyDatabase::class)->isFresh())->toBeTrue();
});

it('downloads the database only, into the cache directory', function () {
    Process::fake(fn () => Process::result(''));

    app(TrivyDatabase::class)->refresh();

    Process::assertRan(function (PendingProcess $process) {
        $command = (array) $process->command;

        return $command[0] === 'trivy' && $command[1] === 'image'
            && in_array('--download-db-only', $command, true)
            && $command[array_search('--cache-dir', $command, true) + 1] === $this->cache;
    });
});

it('reports a failed download from the command without touching the old database', function () {
    writeTrivyMetadata($this->cache, ['UpdatedAt' => now()->subHours(10)->toIso8601String()]);
    Process::fake(function (PendingProcess $process) {
        return in_array('--version', (array) $process->command, true)
            ? Process::result('Version: 0.74.0')
            : Process::result('', 'registry unreachable', 1);
    });

    expect(Artisan::call('pulllens:update-trivy-db'))->toBe(1)
        ->and(Artisan::output())->toContain('Could not update the vulnerability database')
        ->and(app(TrivyDatabase::class)->isFresh())->toBeTrue();
});

it('updates the database from the command', function () {
    Process::fake(function (PendingProcess $process) {
        if (in_array('--version', (array) $process->command, true)) {
            return Process::result('Version: 0.74.0');
        }

        writeTrivyMetadata($this->cache, ['UpdatedAt' => now()->toIso8601String()]);

        return Process::result('');
    });

    expect(Artisan::call('pulllens:update-trivy-db'))->toBe(0)
        ->and(Artisan::output())->toContain('Vulnerability database updated');
});

it('schedules the database refresh every six hours', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'pulllens:update-trivy-db'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 */6 * * *');
});
