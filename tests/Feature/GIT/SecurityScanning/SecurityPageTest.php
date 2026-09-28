<?php

use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSource;
use App\Enums\Users\RepositoryAccessLevel;
use App\Enums\Users\UserRole;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\Users\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A scanner finding of one kind on a fresh pull request in $repository.
 */
function securityFinding(GitRepository $repository, string $kind, array $attributes = []): PullRequestReviewFinding
{
    $pullRequest = secretScanPullRequest($repository, ['provider_pr_id' => random_int(1, 999999)]);

    return PullRequestReviewFinding::query()->create([
        'pull_request_id' => $pullRequest->id,
        'git_repository_id' => $repository->id,
        'source' => $kind === 'secret' ? FindingSource::Gitleaks->value : FindingSource::Trivy->value,
        'dedupe_key' => "{$kind}-".uniqid(),
        'title' => match ($kind) {
            'secret' => 'Secret detected: AWS Access Key',
            'vulnerability' => 'CVE-2022-24775 in guzzlehttp/psr7@1.8.3',
            default => "Image user should not be 'root'",
        },
        'severity' => 'high',
        'category' => 'security',
        'file' => $kind === 'misconfiguration' ? 'Dockerfile' : 'composer.lock',
        'line' => 3,
        'confidence' => 1.0,
        'explanation' => $kind === 'secret' ? 'gitleaks rule `aws-access-token` matched `AWS_KEY=REDACTED`.' : 'explanation',
        'suggested_fix' => 'fix',
        'metadata' => match ($kind) {
            'secret' => ['kind' => 'secret', 'rule_id' => 'aws-access-token'],
            'vulnerability' => ['kind' => 'vulnerability', 'rule_id' => 'CVE-2022-24775', 'package' => 'guzzlehttp/psr7', 'installed_version' => '1.8.3', 'fixed_version' => '1.8.4', 'url' => 'https://avd.aquasec.com/nvd/cve-2022-24775'],
            default => ['kind' => 'misconfiguration', 'rule_id' => 'DS-0002', 'resource' => 'from alpine'],
        },
        ...$attributes,
    ]);
}

it('shows secrets by default and each kind on its own tab', function (string $kind) {
    $repository = secretScanRepository();
    securityFinding($repository, 'secret');
    securityFinding($repository, 'vulnerability');
    securityFinding($repository, 'misconfiguration');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('security.index', $kind === 'secret' ? [] : ['kind' => $kind]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('findings', 1)
            ->where('findings.0.kind', $kind)
            ->where('filters.kind', $kind)
            ->etc());
})->with(['secret', 'vulnerability', 'misconfiguration']);

it('shows the vulnerability columns', function () {
    securityFinding(secretScanRepository(), 'vulnerability');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('security.index', ['kind' => 'vulnerability']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('findings.0.package', 'guzzlehttp/psr7')
            ->where('findings.0.installed_version', '1.8.3')
            ->where('findings.0.fixed_version', '1.8.4')
            ->where('findings.0.rule_id', 'CVE-2022-24775')
            ->where('findings.0.url', 'https://avd.aquasec.com/nvd/cve-2022-24775')
            ->etc());
});

it('counts the tiles and the open findings per tab', function () {
    $repository = secretScanRepository();
    securityFinding($repository, 'secret');
    securityFinding($repository, 'secret', ['resolved_at' => now()->subDays(2), 'resolution_type' => 'false_positive']);
    securityFinding($repository, 'vulnerability', ['severity' => 'critical']);
    securityFinding($repository, 'vulnerability', ['severity' => 'medium']);
    securityFinding($repository, 'misconfiguration');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('security.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tiles', ['open_secrets' => 1, 'open_critical_high_vulnerabilities' => 1, 'open_misconfigurations' => 1, 'resolved_last_7_days' => 1])
            ->where('open_counts', ['secret' => 1, 'vulnerability' => 2, 'misconfiguration' => 1])
            ->where('scanning_enabled', ['secret' => true, 'vulnerability' => true, 'misconfiguration' => true])
            ->etc());
});

it('filters by severity, status and search', function () {
    $repository = secretScanRepository();
    securityFinding($repository, 'vulnerability', ['severity' => 'critical']);
    securityFinding($repository, 'vulnerability', ['severity' => 'medium', 'title' => 'CVE-1 in lodash@4.0.0']);
    securityFinding($repository, 'vulnerability', ['resolved_at' => now(), 'resolution_type' => 'wont_fix']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('security.index', ['kind' => 'vulnerability', 'severity' => 'critical']))
        ->assertInertia(fn (Assert $page) => $page->has('findings', 1)->where('findings.0.severity', 'critical')->etc());
    $this->actingAs($admin)->get(route('security.index', ['kind' => 'vulnerability', 'status' => 'resolved']))
        ->assertInertia(fn (Assert $page) => $page->has('findings', 1)->where('findings.0.resolution_type', 'wont_fix')->etc());
    $this->actingAs($admin)->get(route('security.index', ['kind' => 'vulnerability', 'search' => 'LODASH']))
        ->assertInertia(fn (Assert $page) => $page->has('findings', 1)->where('findings.0.title', 'CVE-1 in lodash@4.0.0')->etc());
});

it('searches the file and the package as well as the title', function () {
    $repository = secretScanRepository();
    securityFinding($repository, 'vulnerability', ['title' => 'CVE-9 advisory', 'metadata' => ['kind' => 'vulnerability', 'package' => 'Left-Pad']]);
    securityFinding($repository, 'vulnerability', ['file' => 'web/package-lock.json']);
    securityFinding($repository, 'vulnerability');
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('security.index', ['kind' => 'vulnerability', 'search' => 'left-pad']))
        ->assertInertia(fn (Assert $page) => $page->has('findings', 1)->where('findings.0.package', 'Left-Pad')->etc());
    $this->actingAs($admin)->get(route('security.index', ['kind' => 'vulnerability', 'search' => 'PACKAGE-LOCK']))
        ->assertInertia(fn (Assert $page) => $page->has('findings', 1)->where('findings.0.file', 'web/package-lock.json')->etc());
});

it('never lists AI review findings', function () {
    $repository = secretScanRepository();
    securityFinding($repository, 'secret');
    PullRequestReviewFinding::query()->create([
        'pull_request_id' => secretScanPullRequest($repository, ['provider_pr_id' => 1])->id, 'git_repository_id' => $repository->id,
        'dedupe_key' => 'ai', 'title' => 'Null check', 'severity' => 'high', 'category' => 'security', 'file' => 'a.php',
        'confidence' => 0.9, 'explanation' => 'e', 'suggested_fix' => 'f', 'metadata' => ['kind' => 'secret'],
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('security.index'))
        ->assertInertia(fn (Assert $page) => $page->has('findings', 1)->where('tiles.open_secrets', 1)->etc());
});

it('never renders a secret value, only the redacted match', function () {
    // A raw-looking key sits in stored metadata the page must never receive; only the
    // presenter's whitelist stands between it and the rendered page.
    securityFinding(secretScanRepository(), 'secret', [
        'metadata' => ['kind' => 'secret', 'rule_id' => 'aws-access-token', 'match' => 'AWS_KEY=AKIAIOSFODNN7EXAMPLE'],
    ]);

    $response = $this->actingAs(User::factory()->admin()->create())->get(route('security.index'));

    expect($response->getContent())->toContain('REDACTED')->not->toContain('AKIA');
    $response->assertInertia(fn (Assert $page) => $page
        ->where('findings.0', fn ($row) => array_keys(collect($row)->all()) === [
            'id', 'title', 'severity', 'kind', 'rule_id', 'package', 'installed_version', 'fixed_version',
            'resource', 'url', 'file', 'line', 'explanation', 'suggested_fix', 'resolved_at',
            'resolution_type', 'created_at', 'repository', 'pull_request',
        ])
        ->etc());
});

it('shows a scoped user only the repositories granted to them', function () {
    $granted = GitRepository::factory()->create();
    $hidden = GitRepository::factory()->create();
    securityFinding($granted, 'secret');
    securityFinding($hidden, 'secret');

    $user = User::factory()->withRole(UserRole::Contributor)->create();
    $user->repositories()->attach($granted->id, ['access_level' => RepositoryAccessLevel::View->value]);

    $this->actingAs($user)
        ->get(route('security.index', ['repository_id' => $hidden->id]))
        ->assertInertia(fn (Assert $page) => $page->has('findings', 0)->where('tiles.open_secrets', 0)->etc());
    $this->actingAs($user)
        ->get(route('security.index'))
        ->assertInertia(fn (Assert $page) => $page->has('findings', 1)->where('tiles.open_secrets', 1)->etc());
});

it('reports scanning as off when every visible repository turned it off', function () {
    secretScanRepository(['secret_scanning_enabled' => false, 'vulnerability_scanning_enabled' => false]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('security.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scanning_enabled', ['secret' => false, 'vulnerability' => false, 'misconfiguration' => false])->etc());
});

it('rejects an unknown kind', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('security.index', ['kind' => 'nope']))
        ->assertSessionHasErrors('kind');
});

it('offers only the manual resolution reasons', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('security.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('resolution_types', fn ($types) => collect($types)->pluck('value')->all() === FindingResolutionType::manualValues())
            ->etc());
});

it('bulk resolves scanner findings with a manual reason', function () {
    $repository = secretScanRepository();
    $secret = securityFinding($repository, 'secret');
    $vulnerability = securityFinding($repository, 'vulnerability');

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.findings.bulk-resolve'), ['finding_ids' => [$secret->id, $vulnerability->id], 'resolution_type' => 'wont_fix'])
        ->assertSessionHasNoErrors();

    expect($secret->fresh()->resolution_type)->toBe(FindingResolutionType::WontFix)
        ->and($vulnerability->fresh()->resolution_type)->toBe(FindingResolutionType::WontFix);
});

it('refuses a system-only reason when bulk resolving scanner findings', function (string $reason) {
    $repository = secretScanRepository();
    $secret = securityFinding($repository, 'secret');
    $vulnerability = securityFinding($repository, 'vulnerability');

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.findings.bulk-resolve'), ['finding_ids' => [$secret->id, $vulnerability->id], 'resolution_type' => $reason])
        ->assertSessionHasErrors('resolution_type');

    expect($secret->fresh()->resolved_at)->toBeNull()
        ->and($vulnerability->fresh()->resolved_at)->toBeNull();
})->with(['secret_removed', 'fixed_in_later_push']);
