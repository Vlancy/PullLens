<?php

use App\Enums\GIT\TaskType;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestTask;
use App\Models\Users\User;
use App\Services\Tasks\TaskRecorder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The task report answers "who did what last month", so the rules that matter are:
| only merged work counts, a re-review must not duplicate it, and the period window
| must be a real calendar month.
*/

function makeReviewedPullRequest(array $prAttributes = []): array
{
    $repository = GitRepository::factory()->create();

    $pullRequest = PullRequest::query()->create([
        'git_repository_id' => $repository->id,
        'provider_pr_id' => random_int(1, 99999),
        'number' => random_int(1, 999),
        'title' => 'Add caching layer',
        'state' => 'merged',
        'author_login' => 'octo',
        'author_name' => 'Octo Cat',
        'source_branch' => 'feature',
        'target_branch' => 'main',
        'opened_at' => now()->subDays(3),
        'merged_at' => now()->subDay(),
        ...$prAttributes,
    ]);

    $review = PullRequestReview::query()->create([
        'pull_request_id' => $pullRequest->id,
        'walkthrough' => 'w',
        'detected_stack' => [],
        'suggested_labels' => [],
        'skipped_files' => [],
        'summary' => 's',
        'verdict' => 'comment',
        'risk_level' => 'low',
        'review_intensity' => 'balanced',
        'follow_up_questions' => [],
        'triggered_by' => 'auto',
        'reviewed_at' => now(),
    ]);

    return [$repository, $pullRequest, $review];
}

function taskPayload(string $key, string $type = 'feature', float $hours = 2.0): array
{
    return [
        'dedupe_key' => $key,
        'title' => Str::headline($key),
        'type' => $type,
        'description' => 'Did the thing.',
        'estimated_hours' => $hours,
        'files' => ['app/Foo.php'],
    ];
}

test('a review records the tasks it extracted', function () {
    [, $pullRequest, $review] = makeReviewedPullRequest();

    $recorded = app(TaskRecorder::class)->record($pullRequest, $review, [
        taskPayload('add-caching-layer'),
        taskPayload('fix-avatar-fallback', 'bugfix', 1.0),
    ]);

    expect($recorded)->toBe(2)
        ->and(PullRequestTask::query()->count())->toBe(2);

    $task = PullRequestTask::query()->where('dedupe_key', 'fix-avatar-fallback')->firstOrFail();

    expect($task->type)->toBe(TaskType::BugFix)
        ->and($task->author_login)->toBe('octo')
        ->and($task->delivered_at)->not->toBeNull();
});

test('re-reviewing a pull request updates its tasks instead of duplicating them', function () {
    [, $pullRequest, $review] = makeReviewedPullRequest();
    $recorder = app(TaskRecorder::class);

    $recorder->record($pullRequest, $review, [taskPayload('add-caching-layer', 'feature', 2.0)]);
    $recorder->record($pullRequest, $review, [taskPayload('add-caching-layer', 'feature', 5.0)]);

    expect(PullRequestTask::query()->count())->toBe(1)
        ->and(PullRequestTask::query()->first()->estimated_hours)->toBe(5.0);
});

test('a task the latest review no longer claims is dropped', function () {
    [, $pullRequest, $review] = makeReviewedPullRequest();
    $recorder = app(TaskRecorder::class);

    $recorder->record($pullRequest, $review, [
        taskPayload('add-caching-layer'),
        taskPayload('remove-me'),
    ]);
    $recorder->record($pullRequest, $review, [taskPayload('add-caching-layer')]);

    expect(PullRequestTask::query()->pluck('dedupe_key')->all())->toBe(['add-caching-layer']);
});

test('malformed model output cannot poison the report', function () {
    [, $pullRequest, $review] = makeReviewedPullRequest();

    $recorded = app(TaskRecorder::class)->record($pullRequest, $review, [
        ['title' => '', 'type' => 'feature'],                       // no title, dropped
        ['title' => 'Unknown type', 'type' => 'not-a-type'],        // falls back to chore
        ['title' => 'Silly estimate', 'estimated_hours' => 99999],  // clamped
    ]);

    expect($recorded)->toBe(2)
        ->and(PullRequestTask::query()->pluck('type')->all())->toContain(TaskType::Chore)
        ->and(PullRequestTask::query()->max('estimated_hours'))->toBeLessThanOrEqual(200.0);
});

test('unmerged work is not reported as delivered', function () {
    [, $pullRequest, $review] = makeReviewedPullRequest([
        'state' => 'open',
        'merged_at' => null,
    ]);

    app(TaskRecorder::class)->record($pullRequest, $review, [taskPayload('work-in-progress')]);

    $this->actingAs(User::factory()->admin()->create());

    $this->get('/reports/tasks?period=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats.total_tasks', 0));
});

test('the report groups delivered tasks by developer', function () {
    [, $pullRequest, $review] = makeReviewedPullRequest();

    app(TaskRecorder::class)->record($pullRequest, $review, [
        taskPayload('add-caching-layer', 'feature', 3.0),
        taskPayload('fix-avatar', 'bugfix', 1.5),
    ]);

    $this->actingAs(User::factory()->admin()->create());

    $this->get('/reports/tasks?period=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_tasks', 2)
            ->where('stats.developers', 1)
            ->where('stats.estimated_hours', 4.5)
            ->where('developers.0.author_login', 'octo')
            ->where('developers.0.total_tasks', 2)
            ->has('developers.0.tasks', 2)
            ->etc()
        );
});

test('the report can be narrowed to one task type', function () {
    [, $pullRequest, $review] = makeReviewedPullRequest();

    app(TaskRecorder::class)->record($pullRequest, $review, [
        taskPayload('add-caching-layer', 'feature'),
        taskPayload('fix-avatar', 'bugfix'),
    ]);

    $this->actingAs(User::factory()->admin()->create());

    $this->get('/reports/tasks?period=all&type=bugfix')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats.total_tasks', 1)->etc());
});

test('a task merged last month is outside this month', function () {
    [, $pullRequest, $review] = makeReviewedPullRequest([
        'merged_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2),
    ]);

    app(TaskRecorder::class)->record($pullRequest, $review, [taskPayload('shipped-last-month')]);

    $this->actingAs(User::factory()->admin()->create());

    $this->get('/reports/tasks?period=this_month')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats.total_tasks', 0)->etc());

    $this->get('/reports/tasks?period=last_month')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats.total_tasks', 1)->etc());
});

test('a scoped user only sees tasks from repositories granted to them', function () {
    [, $pullRequest, $review] = makeReviewedPullRequest();

    app(TaskRecorder::class)->record($pullRequest, $review, [taskPayload('add-caching-layer')]);

    // Manager sees everything; the aggregate reports require repositories.view-all.
    $this->actingAs(User::factory()->manager()->create());

    $this->get('/reports/tasks?period=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats.total_tasks', 1)->etc());
});
