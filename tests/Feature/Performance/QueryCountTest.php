<?php

use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\PullRequestTask;
use App\Models\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Guards the list pages against N+1 queries.
 *
 * The counts are deliberately generous ceilings, not exact figures - the point is
 * that adding a row must not add a query. Each page is measured twice, with a small
 * dataset and a larger one; if the query count grows with the data, it is an N+1.
 */
function measure(callable $seed, string $url, int $small, int $large): array
{
    $user = User::factory()->admin()->create();

    $seed($small);
    test()->actingAs($user);
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get($url)->assertOk();
    $a = count(DB::getQueryLog());

    DB::disableQueryLog();
    $seed($large - $small);
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get($url)->assertOk();
    $b = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [$a, $b];
}

test('list pages do not issue more queries as data grows', function (string $url) {
    $seed = function (int $n): void {
        $repo = GitRepository::factory()->create();
        for ($i = 0; $i < $n; $i++) {
            $pr = PullRequest::query()->create([
                'git_repository_id' => $repo->id,
                'provider_pr_id' => random_int(1, 10_000_000),
                'number' => random_int(1, 100_000),
                'title' => "PR {$i}", 'state' => 'merged',
                'author_login' => 'dev'.($i % 3), 'author_name' => 'Dev '.($i % 3),
                'source_branch' => 'f', 'target_branch' => 'main',
                'opened_at' => now()->subDays(3), 'merged_at' => now()->subDay(),
            ]);
            $review = PullRequestReview::query()->create([
                'pull_request_id' => $pr->id, 'walkthrough' => 'w', 'detected_stack' => [],
                'suggested_labels' => [], 'skipped_files' => [], 'summary' => 's',
                'verdict' => 'comment', 'risk_level' => 'low', 'review_intensity' => 'balanced',
                'follow_up_questions' => [], 'triggered_by' => 'auto', 'reviewed_at' => now(),
            ]);
            PullRequestReviewFinding::query()->create([
                'pull_request_review_id' => $review->id, 'pull_request_id' => $pr->id,
                'git_repository_id' => $repo->id, 'dedupe_key' => "k{$i}-".uniqid(),
                'title' => "Finding {$i}", 'severity' => 'high', 'category' => 'security',
                'file' => 'app/A.php', 'confidence' => 0.9, 'explanation' => 'e', 'suggested_fix' => 'f',
            ]);
            PullRequestTask::query()->create([
                'pull_request_review_id' => $review->id, 'pull_request_id' => $pr->id,
                'git_repository_id' => $repo->id, 'dedupe_key' => "t{$i}-".uniqid(),
                'title' => "Task {$i}", 'type' => 'feature', 'status' => 'delivered',
                'estimated_hours' => 2, 'author_login' => 'dev'.($i % 3),
                'delivered_at' => now()->subDay(),
            ]);
        }
    };

    [$small, $large] = measure($seed, $url, 3, 12);

    expect($large)->toBeLessThanOrEqual($small + 2)
        ->and($large)->toBeLessThan(40);
})->with(['/findings', '/tasks', '/dashboard', '/repositories']);
