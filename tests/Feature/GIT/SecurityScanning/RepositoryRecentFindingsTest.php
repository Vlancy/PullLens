<?php

use App\Models\GIT\PullRequestReviewFinding;
use App\Models\Users\User;
use Inertia\Testing\AssertableInertia as Assert;

it('labels each recent finding on the repository page with where it came from', function () {
    $repository = secretScanRepository();
    $pullRequest = secretScanPullRequest($repository);
    gitleaksFinding($pullRequest, ['source' => 'ai', 'dedupe_key' => 'ai-1']);
    $this->travel(1)->minutes();
    gitleaksFinding($pullRequest);
    $this->travel(1)->minutes();
    gitleaksFinding($pullRequest, ['source' => 'trivy', 'dedupe_key' => 'trivy:vuln:composer.lock:a:1:CVE-1']);

    expect(PullRequestReviewFinding::query()->count())->toBe(3);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('repositories.show', $repository))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recent_findings.0.source', 'trivy')
            ->where('recent_findings.0.source_label', 'Vulnerabilities')
            ->where('recent_findings.1.source_label', 'Secrets')
            ->where('recent_findings.2.source_label', 'AI review')
            ->etc());
});
