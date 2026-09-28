<?php

use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\Users\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function reviewFinding($pullRequest): PullRequestReviewFinding
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

it('lists only AI review findings, with totals that match', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    reviewFinding($pullRequest);
    gitleaksFinding($pullRequest);
    gitleaksFinding($pullRequest, ['source' => 'trivy', 'dedupe_key' => 'trivy:vuln:composer.lock:a:1:CVE-1', 'category' => 'security']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('findings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('findings', 1)
            ->where('findings.0.source', 'ai')
            ->where('stats.total', 1)
            ->where('categories', ['correctness'])
            ->missing('sources')
            ->missing('filters.source')
            ->etc());
});

it('ignores a leftover source parameter from an old bookmark', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    reviewFinding($pullRequest);
    gitleaksFinding($pullRequest);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('findings.index', ['source' => 'gitleaks']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('findings', 1)->where('findings.0.source', 'ai')->etc());
});

it('searches titles case-insensitively, with % and _ matched only literally', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    foreach (['Null check missing', '100% coverage lost', 'snake_case name', 'snakeXcase name', '1000 coverage lost'] as $i => $title) {
        reviewFinding($pullRequest)->update(['title' => $title, 'dedupe_key' => "ai-{$i}"]);
    }
    $admin = User::factory()->admin()->create();

    $titles = fn (string $search) => collect($this->actingAs($admin)->get(route('findings.index', ['search' => $search, 'status' => 'all']))
        ->viewData('page')['props']['findings'])->pluck('title')->sort()->values()->all();

    expect($titles('NULL'))->toBe(['Null check missing'])
        ->and($titles('100%'))->toBe(['100% coverage lost'])
        ->and($titles('snake_case'))->toBe(['snake_case name'])
        ->and($titles('!'))->toBe([]);
});

it('builds the search without a backslash escape, which MySQL would read as a string escape', function () {
    DB::enableQueryLog();

    $this->actingAs(User::factory()->admin()->create())->get(route('findings.index', ['search' => '50%_off']))->assertOk();

    $search = collect(DB::getQueryLog())->first(fn (array $query) => str_contains($query['query'], ' LIKE ? ESCAPE '));

    expect($search['query'])->toContain("ESCAPE '!'")->not->toContain('\\')
        ->and($search['bindings'])->toContain('%50!%!_off%');
});
