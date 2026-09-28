<?php

use App\Services\Git\VulnerabilityScanning\TrivyDatabase;
use App\Services\Git\VulnerabilityScanning\TrivyFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

/**
 * Write a complete fake database (metadata and trivy.db) into `<cache>/db`.
 */
function writeTrivyDatabase(string $cache, array $metadata, string $db = 'bolt'): void
{
    writeTrivyMetadata($cache, $metadata);
    file_put_contents($cache.'/db/trivy.db', $db);
}

/**
 * Fake trivy: `--version` answers 0.74.0; a download calls $download with the cache directory it was given.
 */
function fakeTrivyDownload(callable $download, ?string &$downloadedInto = null): void
{
    Process::fake(function (PendingProcess $process) use ($download, &$downloadedInto) {
        $command = (array) $process->command;

        if (in_array('--version', $command, true)) {
            return Process::result('Version: 0.74.0');
        }

        $downloadedInto = $command[array_search('--cache-dir', $command, true) + 1];

        return $download($downloadedInto);
    });
}

it('downloads the database only, into a directory beside the live copy', function () {
    fakeTrivyDownload(function (string $into) {
        writeTrivyDatabase($into, ['UpdatedAt' => now()->toIso8601String()]);

        return Process::result('');
    }, $into);

    app(TrivyDatabase::class)->refresh();

    Process::assertRan(fn (PendingProcess $process) => ((array) $process->command)[1] === 'image'
        && in_array('--download-db-only', (array) $process->command, true));
    expect($into)->not->toBe($this->cache)
        ->and(dirname($into))->toBe($this->cache)
        ->and(is_dir($into))->toBeFalse();
});

it('swaps a verified download in and removes the old copy', function () {
    writeTrivyDatabase($this->cache, ['UpdatedAt' => now()->subHours(10)->toIso8601String()], 'old');
    fakeTrivyDownload(function (string $into) {
        writeTrivyDatabase($into, ['UpdatedAt' => now()->subMinute()->toIso8601String()], 'new');

        return Process::result('');
    });

    app(TrivyDatabase::class)->refresh();

    expect(file_get_contents($this->cache.'/db/trivy.db'))->toBe('new')
        ->and(app(TrivyDatabase::class)->updatedAt()->greaterThan(now()->subMinutes(2)))->toBeTrue()
        ->and(glob($this->cache.'/*'))->toBe([$this->cache.'/db']);
});

it('keeps the old database byte-identical when the download fails part-way', function () {
    writeTrivyDatabase($this->cache, ['UpdatedAt' => now()->subHours(10)->toIso8601String()], 'old');
    $metadata = file_get_contents($this->cache.'/db/metadata.json');
    fakeTrivyDownload(function (string $into) {
        // What a real failed download leaves: no metadata and a half-written database.
        File::ensureDirectoryExists($into.'/db');
        file_put_contents($into.'/db/trivy.db', 'partial');

        return Process::result('', 'registry unreachable', 1);
    });

    expect(fn () => app(TrivyDatabase::class)->refresh())->toThrow(TrivyFailed::class, 'registry unreachable');

    expect(file_get_contents($this->cache.'/db/metadata.json'))->toBe($metadata)
        ->and(file_get_contents($this->cache.'/db/trivy.db'))->toBe('old')
        ->and(glob($this->cache.'/*'))->toBe([$this->cache.'/db']);
});

it('keeps the old database when the download has no usable build time', function (?array $metadata) {
    writeTrivyDatabase($this->cache, ['UpdatedAt' => now()->subHours(10)->toIso8601String()], 'old');
    $old = file_get_contents($this->cache.'/db/metadata.json');
    fakeTrivyDownload(function (string $into) use ($metadata) {
        File::ensureDirectoryExists($into.'/db');
        file_put_contents($into.'/db/trivy.db', 'new');
        file_put_contents($into.'/db/metadata.json', $metadata === null ? 'not json' : json_encode($metadata));

        return Process::result('');
    });

    expect(fn () => app(TrivyDatabase::class)->refresh())->toThrow(TrivyFailed::class);

    expect(file_get_contents($this->cache.'/db/metadata.json'))->toBe($old)
        ->and(file_get_contents($this->cache.'/db/trivy.db'))->toBe('old')
        ->and(glob($this->cache.'/*'))->toBe([$this->cache.'/db']);
})->with([
    'unparseable' => [null],
    'zero time' => [['UpdatedAt' => '0001-01-01T00:00:00Z', 'DownloadedAt' => '0001-01-01T00:00:00Z']],
]);

it('keeps the old database when the download wrote no database file', function () {
    writeTrivyDatabase($this->cache, ['UpdatedAt' => now()->subHours(10)->toIso8601String()], 'old');
    fakeTrivyDownload(function (string $into) {
        writeTrivyMetadata($into, ['UpdatedAt' => now()->toIso8601String()]);

        return Process::result('');
    });

    expect(fn () => app(TrivyDatabase::class)->refresh())->toThrow(TrivyFailed::class);

    expect(file_get_contents($this->cache.'/db/trivy.db'))->toBe('old');
});

it('clears what a killed refresh left behind', function () {
    writeTrivyDatabase($this->cache, ['UpdatedAt' => now()->subHours(10)->toIso8601String()], 'old');
    File::ensureDirectoryExists($this->cache.'/.incoming-dead/db');
    File::ensureDirectoryExists($this->cache.'/db.old');
    fakeTrivyDownload(function (string $into) {
        writeTrivyDatabase($into, ['UpdatedAt' => now()->toIso8601String()], 'new');

        return Process::result('');
    });

    app(TrivyDatabase::class)->refresh();

    expect(glob($this->cache.'/{,.}*[!.]', GLOB_BRACE))->toBe([$this->cache.'/db']);
});

it('does not download while another refresh holds the lock', function () {
    Process::fake();
    $lock = Cache::lock(TrivyDatabase::REFRESH_LOCK, 60);
    $lock->get();

    try {
        expect(fn () => app(TrivyDatabase::class)->refresh())->toThrow(TrivyFailed::class, 'already running');
    } finally {
        $lock->release();
    }

    Process::assertNothingRan();
});

it('reports a failed download from the command without touching the old database', function () {
    writeTrivyDatabase($this->cache, ['UpdatedAt' => now()->subHours(10)->toIso8601String()]);
    fakeTrivyDownload(fn () => Process::result('', 'registry unreachable', 1));

    expect(Artisan::call('pulllens:update-trivy-db'))->toBe(1)
        ->and(Artisan::output())->toContain('Could not update the vulnerability database')
        ->and(app(TrivyDatabase::class)->isFresh())->toBeTrue();
});

it('updates the database from the command', function () {
    fakeTrivyDownload(function (string $into) {
        writeTrivyDatabase($into, ['UpdatedAt' => now()->toIso8601String()]);

        return Process::result('');
    });

    expect(Artisan::call('pulllens:update-trivy-db'))->toBe(0)
        ->and(Artisan::output())->toContain('Vulnerability database updated')
        ->and(app(TrivyDatabase::class)->isFresh())->toBeTrue();
});

it('schedules the database refresh every six hours', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'pulllens:update-trivy-db'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 */6 * * *');
});
