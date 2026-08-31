<?php

use App\Enums\GIT\TaskRelation;
use App\Enums\GIT\TaskStatus;
use App\Enums\Users\UserRole;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestTask;
use App\Models\GIT\PullRequestTaskLink;
use App\Models\Users\User;
use App\Services\Tasks\TaskRecorder;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The lifecycle is what makes the task history trustworthy: it is derived from links
| and delivery, never set by hand, so it cannot drift from the evidence.
*/

function reviewedPr(GitRepository $repository, int $number, array $attributes = []): array
{
    $pullRequest = PullRequest::query()->create([
        'git_repository_id' => $repository->id,
        'provider_pr_id' => $number * 100,
        'number' => $number,
        'title' => "PR {$number}",
        'state' => 'merged',
        'author_login' => 'octo',
        'author_name' => 'Octo Cat',
        'source_branch' => "feature/{$number}",
        'target_branch' => 'main',
        'opened_at' => now()->subDays(10),
        'merged_at' => now()->subDays(9),
        ...$attributes,
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

    return [$pullRequest, $review];
}

function task(string $key, string $type = 'feature', array $links = []): array
{
    return [
        'dedupe_key' => $key,
        'title' => ucfirst(str_replace('-', ' ', $key)),
        'type' => $type,
        'description' => 'Work.',
        'estimated_hours' => 2,
        'files' => ['app/Foo.php'],
        'links' => $links,
    ];
}

test('a delivered task with nothing pointing at it is first time right', function () {
    $repository = GitRepository::factory()->create();
    [$pr, $review] = reviewedPr($repository, 1);

    app(TaskRecorder::class)->record($pr, $review, [task('add-caching')]);

    $stored = PullRequestTask::query()->firstOrFail();

    expect($stored->status)->toBe(TaskStatus::Delivered)
        ->and($stored->isFirstTimeRight())->toBeTrue()
        ->and($stored->rework_count)->toBe(0);
});

test('a later task that fixes it marks the original as reworked', function () {
    $repository = GitRepository::factory()->create();
    $recorder = app(TaskRecorder::class);

    [$first, $firstReview] = reviewedPr($repository, 1);
    $recorder->record($first, $firstReview, [task('add-caching')]);

    [$second, $secondReview] = reviewedPr($repository, 2);
    $recorder->record($second, $secondReview, [
        task('fix-cache-key', 'bugfix', [[
            'target_dedupe_key' => 'add-caching',
            'relation' => 'fixes',
            'reason' => 'Corrects the key built in the caching task.',
            'confidence' => 0.9,
        ]]),
    ]);

    $original = PullRequestTask::query()->where('dedupe_key', 'add-caching')->firstOrFail();

    expect($original->status)->toBe(TaskStatus::Reworked)
        ->and($original->rework_count)->toBe(1)
        ->and($original->isFirstTimeRight())->toBeFalse();
});

test('an extending task marks the original as revised, not reworked', function () {
    $repository = GitRepository::factory()->create();
    $recorder = app(TaskRecorder::class);

    [$first, $firstReview] = reviewedPr($repository, 1);
    $recorder->record($first, $firstReview, [task('add-caching')]);

    [$second, $secondReview] = reviewedPr($repository, 2);
    $recorder->record($second, $secondReview, [
        task('add-cache-metrics', 'feature', [[
            'target_dedupe_key' => 'add-caching',
            'relation' => 'extends',
            'reason' => 'Builds on the cache.',
            'confidence' => 0.8,
        ]]),
    ]);

    $original = PullRequestTask::query()->where('dedupe_key', 'add-caching')->firstOrFail();

    expect($original->status)->toBe(TaskStatus::Revised)
        ->and($original->revision_count)->toBe(1)
        ->and($original->rework_count)->toBe(0);
});

test('the worst outcome wins when several links apply', function () {
    $repository = GitRepository::factory()->create();
    $recorder = app(TaskRecorder::class);

    [$first, $firstReview] = reviewedPr($repository, 1);
    $recorder->record($first, $firstReview, [task('add-caching')]);

    [$second, $secondReview] = reviewedPr($repository, 2);
    $recorder->record($second, $secondReview, [
        task('extend-cache', 'feature', [[
            'target_dedupe_key' => 'add-caching', 'relation' => 'extends',
            'reason' => 'r', 'confidence' => 0.8,
        ]]),
        task('revert-cache', 'chore', [[
            'target_dedupe_key' => 'add-caching', 'relation' => 'reverts',
            'reason' => 'r', 'confidence' => 0.9,
        ]]),
    ]);

    $original = PullRequestTask::query()->where('dedupe_key', 'add-caching')->firstOrFail();

    expect($original->status)->toBe(TaskStatus::Reverted)
        ->and($original->reverted_at)->not->toBeNull();
});

test('a link to an unknown task is discarded rather than invented', function () {
    $repository = GitRepository::factory()->create();
    [$pr, $review] = reviewedPr($repository, 1);

    app(TaskRecorder::class)->record($pr, $review, [
        task('fix-something', 'bugfix', [[
            'target_dedupe_key' => 'a-task-that-never-existed',
            'relation' => 'fixes',
            'reason' => 'r',
            'confidence' => 0.9,
        ]]),
    ]);

    expect(PullRequestTaskLink::query()->count())->toBe(0);
});

test('a task cannot link to work in another repository', function () {
    $recorder = app(TaskRecorder::class);

    [$firstPr, $firstReview] = reviewedPr(GitRepository::factory()->create(), 1);
    $recorder->record($firstPr, $firstReview, [task('add-caching')]);

    [$otherPr, $otherReview] = reviewedPr(GitRepository::factory()->create(), 2);
    $recorder->record($otherPr, $otherReview, [
        task('fix-cache', 'bugfix', [[
            'target_dedupe_key' => 'add-caching',
            'relation' => 'fixes',
            'reason' => 'r',
            'confidence' => 0.9,
        ]]),
    ]);

    expect(PullRequestTaskLink::query()->count())->toBe(0);
});

test('a manual link survives a later review', function () {
    $repository = GitRepository::factory()->create();
    $recorder = app(TaskRecorder::class);

    [$first, $firstReview] = reviewedPr($repository, 1);
    $recorder->record($first, $firstReview, [task('add-caching')]);

    [$second, $secondReview] = reviewedPr($repository, 2);
    $recorder->record($second, $secondReview, [task('fix-cache-key', 'bugfix')]);

    $fix = PullRequestTask::query()->where('dedupe_key', 'fix-cache-key')->firstOrFail();
    $original = PullRequestTask::query()->where('dedupe_key', 'add-caching')->firstOrFail();

    // A person draws the link the model missed.
    PullRequestTaskLink::query()->create([
        'task_id' => $fix->id,
        'related_task_id' => $original->id,
        'relation' => TaskRelation::Fixes->value,
        'source' => PullRequestTaskLink::SOURCE_MANUAL,
    ]);

    // The PR is reviewed again and still proposes nothing.
    $recorder->record($second, $secondReview, [task('fix-cache-key', 'bugfix')]);

    expect(PullRequestTaskLink::query()->where('source', PullRequestTaskLink::SOURCE_MANUAL)->count())
        ->toBe(1);
});

test('an ai link the latest review drops is retracted and the status recovers', function () {
    $repository = GitRepository::factory()->create();
    $recorder = app(TaskRecorder::class);

    [$first, $firstReview] = reviewedPr($repository, 1);
    $recorder->record($first, $firstReview, [task('add-caching')]);

    [$second, $secondReview] = reviewedPr($repository, 2);
    $recorder->record($second, $secondReview, [
        task('fix-cache-key', 'bugfix', [[
            'target_dedupe_key' => 'add-caching', 'relation' => 'fixes',
            'reason' => 'r', 'confidence' => 0.9,
        ]]),
    ]);

    expect(PullRequestTask::query()->where('dedupe_key', 'add-caching')->firstOrFail()->status)
        ->toBe(TaskStatus::Reworked);

    // Re-reviewed, and the model no longer claims the relationship.
    $recorder->record($second, $secondReview, [task('fix-cache-key', 'bugfix')]);

    $original = PullRequestTask::query()->where('dedupe_key', 'add-caching')->firstOrFail();

    expect($original->status)->toBe(TaskStatus::Delivered)
        ->and($original->rework_count)->toBe(0);
});

test('work on an unmerged pull request is in progress', function () {
    $repository = GitRepository::factory()->create();
    [$pr, $review] = reviewedPr($repository, 1, ['state' => 'open', 'merged_at' => null]);

    app(TaskRecorder::class)->record($pr, $review, [task('work-in-progress')]);

    expect(PullRequestTask::query()->firstOrFail()->status)->toBe(TaskStatus::InProgress);
});

test('a tracker key in the branch name is captured for a future integration', function () {
    config()->set('pulllens.tasks.tracker', 'jira');
    config()->set('pulllens.tasks.tracker_base_url', 'https://acme.atlassian.net');

    $repository = GitRepository::factory()->create();
    [$pr, $review] = reviewedPr($repository, 1, ['source_branch' => 'feature/PROJ-451-add-caching']);

    app(TaskRecorder::class)->record($pr, $review, [task('add-caching')]);

    $stored = PullRequestTask::query()->firstOrFail();

    expect($stored->external_key)->toBe('PROJ-451')
        ->and($stored->external_url)->toBe('https://acme.atlassian.net/browse/PROJ-451');
});

test('a false-positive issue key is not captured', function () {
    $repository = GitRepository::factory()->create();
    [$pr, $review] = reviewedPr($repository, 1, [
        'source_branch' => 'feature/utf-8-encoding',
        'title' => 'Fix UTF-8 handling',
        'description' => null,
    ]);

    app(TaskRecorder::class)->record($pr, $review, [task('fix-encoding')]);

    expect(PullRequestTask::query()->firstOrFail()->external_key)->toBeNull();
});

test('the board searches, filters and shows what came back', function () {
    $repository = GitRepository::factory()->create();
    $recorder = app(TaskRecorder::class);

    [$first, $firstReview] = reviewedPr($repository, 1);
    $recorder->record($first, $firstReview, [task('add-caching')]);

    [$second, $secondReview] = reviewedPr($repository, 2);
    $recorder->record($second, $secondReview, [
        task('fix-cache-key', 'bugfix', [[
            'target_dedupe_key' => 'add-caching', 'relation' => 'fixes',
            'reason' => 'Corrects the cache key.', 'confidence' => 0.9,
        ]]),
    ]);

    $this->actingAs(User::factory()->admin()->create());

    $this->get('/tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 2)
            ->where('summary.reworked', 1)
            ->where('summary.first_time_right', 1)
            ->etc());

    // Search narrows to one.
    $this->get('/tasks?search=caching')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('summary.total', 1)->etc());

    // "Came back only" shows the reworked original.
    $this->get('/tasks?rework=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)
            ->where('tasks.0.status', 'reworked')
            ->where('tasks.0.inbound_links.0.relation_label', 'Fixed by')
            ->etc());
});

test('the board is closed to a user without the tasks permission', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/tasks')->assertForbidden();
});

test('a scoped user only sees tasks from their repositories', function () {
    $granted = GitRepository::factory()->create();
    $hidden = GitRepository::factory()->create();
    $recorder = app(TaskRecorder::class);

    [$a, $aReview] = reviewedPr($granted, 1);
    $recorder->record($a, $aReview, [task('granted-work')]);

    [$b, $bReview] = reviewedPr($hidden, 2);
    $recorder->record($b, $bReview, [task('hidden-work')]);

    $user = User::factory()->withRole(UserRole::Contributor)->create();
    $user->repositories()->attach($granted->id, ['access_level' => 'view']);

    $this->actingAs($user);

    $this->get('/tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)
            ->where('tasks.0.title', 'Granted work')
            ->etc());
});
