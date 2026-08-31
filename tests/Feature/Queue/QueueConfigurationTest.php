<?php

use Illuminate\Support\Facades\Config;

/*
| Laravel releases a job back to the queue once `retry_after` elapses, whether or not
| it is still running. `retry_after` is connection-level and a job cannot override it,
| so if any job's timeout exceeds it, that job runs a second time CONCURRENTLY.
|
| For an AI review that means paying for the review twice and posting the findings to
| the pull request twice. This invariant is the only thing preventing it.
*/

/**
 * The declared timeout of every queued job in the application.
 *
 * @return array<string, int>
 */
function jobTimeouts(): array
{
    $timeouts = [];

    foreach (glob(app_path('Jobs/**/*.php')) ?: [] as $file) {
        $class = 'App\\Jobs\\'.str_replace(
            ['/', '.php'],
            ['\\', ''],
            ltrim(str_replace(app_path('Jobs'), '', $file), '/'),
        );

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->hasProperty('timeout')) {
            continue;
        }

        $timeouts[$class] = (int) $reflection->getDefaultProperties()['timeout'];
    }

    return $timeouts;
}

test('every queue connection retries later than the slowest job can run', function () {
    $slowest = max(jobTimeouts());

    foreach (['database', 'redis', 'beanstalkd'] as $connection) {
        $retryAfter = Config::get("queue.connections.{$connection}.retry_after");

        expect($retryAfter)->toBeGreaterThan(
            $slowest,
            "queue.connections.{$connection}.retry_after ({$retryAfter}s) must exceed the "
            ."slowest job timeout ({$slowest}s), or that job will be run twice at once."
        );
    }
});

test('the horizon worker timeout is not below the slowest job', function () {
    // A job declaring its own timeout overrides this, but one that does not would be
    // killed part-way through.
    $slowest = max(jobTimeouts());

    expect(Config::get('horizon.defaults.supervisor-1.timeout'))
        ->toBeGreaterThanOrEqual($slowest);
});

test('every queued job declares a timeout and a retry policy', function () {
    // A job with no timeout inherits the worker's, which is easy to misconfigure.
    $files = glob(app_path('Jobs/**/*.php')) ?: [];
    expect($files)->not->toBeEmpty();

    $missing = [];

    foreach ($files as $file) {
        $source = file_get_contents($file);

        if (! str_contains($source, 'ShouldQueue')) {
            continue;
        }

        if (! preg_match('/public\s+(?:int\s+)?\$timeout/', $source)) {
            $missing[] = basename($file).' has no $timeout';
        }

        if (! preg_match('/public\s+(?:int\s+)?\$tries/', $source)) {
            $missing[] = basename($file).' has no $tries';
        }
    }

    expect($missing)->toBe([]);
});
