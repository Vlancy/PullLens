<?php

use App\Jobs\GIT\ScanPullRequestSecrets;
use App\Jobs\GIT\ScanPullRequestVulnerabilities;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

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

/**
 * Every concrete job class under app/Jobs.
 *
 * @return list<class-string>
 */
function concreteJobClasses(): array
{
    $classes = [];

    foreach (File::allFiles(app_path('Jobs')) as $file) {
        $class = 'App\\Jobs\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (class_exists($class) && ! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/**
 * What a queued class is missing of a timeout and a retry policy; empty when it has both
 * or is not queued. Declared or inherited members both count.
 *
 * @return list<string>
 */
function queuePolicyGaps(string $class): array
{
    $reflection = new ReflectionClass($class);

    if (! $reflection->implementsInterface(ShouldQueue::class)) {
        return [];
    }

    $gaps = [];

    if (! $reflection->hasProperty('timeout')) {
        $gaps[] = class_basename($class).' has no $timeout';
    }

    // retryUntil() bounds retries by time and makes Laravel ignore $tries.
    if (! $reflection->hasProperty('tries') && ! $reflection->hasMethod('retryUntil')) {
        $gaps[] = class_basename($class).' has no $tries or retryUntil()';
    }

    return $gaps;
}

/** A queued job with neither a timeout nor a retry policy, to prove the check can fail. */
final class QueueConfigurationTestJobWithoutPolicy implements ShouldQueue
{
    use Queueable;
}

test('every queued job declares a timeout and a retry policy', function () {
    // A job with no timeout inherits the worker's, which is easy to misconfigure.
    $classes = concreteJobClasses();
    expect($classes)->toContain(ScanPullRequestVulnerabilities::class, ScanPullRequestSecrets::class);

    expect(array_merge(...array_map(fn (string $class) => queuePolicyGaps($class), $classes)))->toBe([])
        ->and(queuePolicyGaps(QueueConfigurationTestJobWithoutPolicy::class))->toHaveCount(2);
});
