<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

it('removes a stale secret scan workspace, keeps a fresh one, and reports the count', function () {
    $root = storage_path('app/secret-scans');
    $stale = $root.'/stale-'.uniqid();
    $fresh = $root.'/fresh-'.uniqid();

    mkdir($stale, 0777, true);
    mkdir($fresh, 0777, true);
    touch($stale, time() - 16 * 60);

    try {
        Artisan::call('pulllens:sweep-secret-scans');

        expect(Artisan::output())->toContain('Removed 1 leftover secret scan workspaces.')
            ->and(is_dir($stale))->toBeFalse()
            ->and(is_dir($fresh))->toBeTrue();
    } finally {
        @rmdir($fresh);
    }
});

it('schedules the secret scan sweep hourly', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'pulllens:sweep-secret-scans'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');
});
