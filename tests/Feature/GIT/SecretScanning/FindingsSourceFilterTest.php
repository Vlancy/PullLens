<?php

use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\Users\User;
use Inertia\Testing\AssertableInertia as Assert;

function aiFinding($pullRequest): PullRequestReviewFinding
{
    $review = PullRequestReview::query()->create([
        'pull_request_id' => $pullRequest->id, 'walkthrough' => 'w', 'detected_stack' => [], 'suggested_labels' => [],
        'skipped_files' => [], 'summary' => 's', 'risk_level' => 'low', 'review_intensity' => 'balanced', 'follow_up_questions' => [],
    ]);

    return PullRequestReviewFinding::query()->create([
        'pull_request_review_id' => $review->id, 'pull_request_id' => $pullRequest->id,
        'git_repository_id' => $pullRequest->git_repository_id, 'dedupe_key' => 'ai-1', 'title' => 'Null check',
        'severity' => 'high', 'category' => 'correctness', 'file' => 'a.php', 'confidence' => 0.9,
        'explanation' => 'e', 'suggested_fix' => 'f',
    ]);
}

it('filters findings by source and labels each row with it', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    aiFinding($pullRequest);
    gitleaksFinding($pullRequest);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('findings.index', ['source' => 'gitleaks']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('findings', 1)
            ->where('findings.0.source', 'gitleaks')
            ->where('filters.source', 'gitleaks')
            ->where('sources', [['value' => 'ai', 'label' => 'AI review'], ['value' => 'gitleaks', 'label' => 'Secrets']])
            ->etc());
});

it('shows both sources when no source is chosen', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    aiFinding($pullRequest);
    gitleaksFinding($pullRequest);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('findings.index'))
        ->assertInertia(fn (Assert $page) => $page->has('findings', 2)->where('filters.source', '')->etc());
});

it('rejects an unknown source', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('findings.index', ['source' => 'nope']))
        ->assertSessionHasErrors('source');
});
