# Gitleaks Secret Scanning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Scan every pull request diff for leaked credentials with the free gitleaks CLI and report each hit, always redacted, in four places: an inline PR comment, a PullLens finding, a failing `PullLens / Secrets` check run, and a git note under `refs/notes/gitleaks`.

**Architecture:**
- A new queued job, `ScanPullRequestSecrets`, is dispatched from the `pull_request` webhook for opened, synchronize and reopened events. It is independent of AI reviews.
- `SecretScanner` rebuilds only the added lines of each patch into "mirror" files, laid out so a line's number in the mirror equals its number in the new file. `GitleaksRunner` runs `gitleaks dir --redact` over them and maps the hits back.
- Findings reuse `pull_request_review_findings` through a new `source` column and a nullable `pull_request_review_id`. Each run is recorded in a new `secret_scans` table.
- Git notes are written with the GitHub Git Data API (blob → tree → commit → ref), so nothing is cloned.

**Tech Stack:** Laravel 12 (PHP 8.4), Pest, Laravel `Process` and `Http` facades, Inertia + React/TypeScript, PostgreSQL (SQLite in tests), gitleaks v8.28.0 (MIT).

**Spec:** `docs/superpowers/specs/2026-09-28-gitleaks-secret-scanning-design.md`

## Global Constraints

- gitleaks is the MIT CLI only. Do not use `gitleaks-action` and do not use a license key. Pinned version: `8.28.0`.
- Every surface (comment, finding, annotation, note, log) shows the secret **redacted**. Always pass `--redact`. Never copy raw patch content into any output or log.
- Scan scope is the PR diff only: the added lines from `GET /pulls/{n}/files`. No clone.
- Scanning does not depend on `reviews_enabled`, `ReviewTriggerPolicy` or the `ai-reviews` rate limiter.
- Per-repo toggle `secret_scanning_enabled`, default `true`.
- `.gitleaks.toml` and `.gitleaksignore` are read from the PR's **target branch**, never from the PR head. Otherwise a PR could allowlist its own secret.
- Check run name: `PullLens / Secrets`. Conclusion: `failure` when there are findings, `success` when there are none, `neutral` on a scan error.
- Gitleaks findings are `severity=critical`, `category=security`, `confidence=1.0` and `source=gitleaks`.
- `dedupe_key` = `gitleaks:{rule_id}:{file}:{first 16 hex of hash_hmac('sha256', trim(added line), app.key)}`.
- Secret findings are never auto-resolved because their file changed. They resolve only when a rescan no longer finds them (`resolution_type=secret_removed`), or manually.
- Every job's `$timeout` must stay below the queue's `retry_after` (300). `QueueConfigurationTest` enforces this.
- Follow the repo conventions:
  - A PHPDoc sentence above every method.
  - Enums implement `values()` and, where they are shown in the UI, `label()`.
  - Commit messages use Conventional Commits and never mention AI tools or assistants.

## Review Focus

1. **A PR whose base branch allowlists nothing, but whose head adds a `.gitleaks.toml` allowlisting its own secret.** The secret must still be reported. Pinned in Task 6 (the config is fetched with `ref = target_branch`).
2. **A push that only shifts a secret's line (code added above it).** There must be no second comment and no false "secret removed" resolution. Pinned in Task 7 (the dedupe key is a hash of the line content, not its number).
3. **A patch GitHub omitted (binary file, or a patch too large to include) and a deleted file.** It must be skipped and counted, never crash. Pinned in Task 6.
4. **A malicious filename with `../` in it.** It must never be written outside the scan workspace. Pinned in Task 6.
5. **A reply to a secret finding's inline comment ("this is fake").** It must not trigger the AI dispute flow and must not resolve the secret as a false positive. Pinned in Task 3.

---

## File Structure

**Create**
- `database/migrations/2026_09_28_100000_create_secret_scans_table.php`: the run table.
- `database/migrations/2026_09_28_100001_add_source_to_pull_request_review_findings.php`: `source`, `secret_scan_id`, and a nullable review FK.
- `database/migrations/2026_09_28_100002_add_secret_scanning_enabled_to_git_repositories.php`: the toggle.
- `app/Enums/GIT/FindingSource.php`: `ai` | `gitleaks`.
- `app/Enums/GIT/SecretScanStatus.php`: `running` | `completed` | `failed` | `skipped`.
- `app/Models/GIT/SecretScan.php`: the run model.
- `app/Services/Git/SecretScanning/PatchAddedLinesExtractor.php`: pure patch parser.
- `app/Services/Git/SecretScanning/GitleaksHit.php`: DTO for one raw gitleaks result.
- `app/Services/Git/SecretScanning/GitleaksFailed.php`: runtime exception.
- `app/Services/Git/SecretScanning/GitleaksRunner.php`: the process wrapper.
- `app/Services/Git/SecretScanning/SecretHit.php`: DTO for a hit mapped to the PR, with its content hash.
- `app/Services/Git/SecretScanning/SecretScanResult.php`: DTO for the whole scan.
- `app/Services/Git/SecretScanning/SecretScanner.php`: fetch, mirror, run, map.
- `app/Services/Git/SecretScanning/GitHubNotesWriter.php`: writes notes through the Git Data API.
- `app/Jobs/GIT/ScanPullRequestSecrets.php`: orchestration, persistence and outputs.
- Tests:
  - `tests/Unit/SecretScanning/PatchAddedLinesExtractorTest.php`
  - `tests/Feature/GIT/SecretScanning/SecretScanSchemaTest.php`
  - `tests/Feature/GIT/SecretScanning/ReportQueriesTest.php`
  - `tests/Feature/GIT/SecretScanning/AiOnlyFlowsTest.php`
  - `tests/Feature/GIT/SecretScanning/GitleaksRunnerTest.php`
  - `tests/Feature/GIT/SecretScanning/GitHubNotesWriterTest.php`
  - `tests/Feature/GIT/SecretScanning/SecretScannerTest.php`
  - `tests/Feature/GIT/SecretScanning/ScanPullRequestSecretsTest.php`
  - `tests/Feature/GIT/SecretScanning/SecretScanDispatchTest.php`
  - `tests/Feature/GIT/SecretScanning/FindingsSourceFilterTest.php`
  - `tests/Feature/GIT/SecretScanning/GitleaksBinaryTest.php`

**Modify**
- `app/Enums/GIT/FindingResolutionType.php`: add `SecretRemoved`.
- `app/Enums/GIT/PullRequestWebhookAction.php`: add `introducesCode()`.
- `app/Models/GIT/PullRequestReviewFinding.php`: fillable, casts, `secretScan()`.
- `app/Models/GIT/GitRepository.php`: fillable, cast.
- `app/Services/Reports/OverviewReportService.php`, `LeaderboardReportService.php` and `DailyActivityReportService.php`: stop inner-joining reviews.
- `app/Services/Reports/DeveloperMetricsReportService.php`: count AI findings only.
- `app/Jobs/GIT/CheckFindingResolutions.php` and `app/Jobs/GIT/DisputePullRequestFinding.php`: AI findings only.
- `app/Services/Git/GitHubApiClient.php`: Git Data API methods.
- `app/Services/Git/Webhooks/Handlers/PullRequestEventHandler.php`: dispatch the scan.
- `app/Http/Requests/Settings/GIT/UpdateGitRepositorySettingsRequest.php`, `app/Http/Controllers/Settings/GIT/GitRepositorySettingsController.php` and `resources/js/pages/settings/git-repository-settings.tsx`: the toggle.
- `app/Http/Requests/Findings/IndexFindingsRequest.php`, `app/Support/Queries/GIT/FindingQuery.php`, `app/Http/Controllers/Findings/FindingsController.php`, `app/Support/Presenters/GIT/FindingPresenter.php` and `resources/js/pages/findings/index.tsx`: the source filter and badge.
- `config/pulllens.php` and `.env.example`: the `secret_scanning` block.
- `tests/Pest.php`: shared fixtures.
- `docker/services/laravel/Dockerfile`, `install.sh`, `INSTALL.md` and `DOCKER.md`: install the binary.

---

### Task 1: Schema, enums and models

**Files:**
- Create: the three migrations above, `app/Enums/GIT/FindingSource.php`, `app/Enums/GIT/SecretScanStatus.php` and `app/Models/GIT/SecretScan.php`
- Modify: `app/Enums/GIT/FindingResolutionType.php`, `app/Models/GIT/PullRequestReviewFinding.php`, `app/Models/GIT/GitRepository.php` and `tests/Pest.php`
- Test: `tests/Feature/GIT/SecretScanning/SecretScanSchemaTest.php`

**Interfaces:**
- Produces:
  - `FindingSource::{Ai, Gitleaks}` and `FindingSource::values()`.
  - `SecretScanStatus::{Running, Completed, Failed, Skipped}`.
  - `FindingResolutionType::SecretRemoved` (`'secret_removed'`).
  - `SecretScan` model, with fillable fields `pull_request_id`, `git_repository_id`, `head_sha`, `status`, `findings_count`, `files_scanned`, `files_skipped`, `check_run_id`, `notes_commit_sha`, `gitleaks_version`, `duration_ms` and `error`, and the relations `pullRequest()`, `repository()` and `findings()`.
  - On `PullRequestReviewFinding`: `source` (cast to `FindingSource`), `secret_scan_id`, and `secretScan()`.
  - On `GitRepository`: `secret_scanning_enabled` (bool).
  - Test helpers in `tests/Pest.php`:
    - `secretScanRepository(array $attributes = []): GitRepository`
    - `secretScanPullRequest(GitRepository $repository, array $attributes = []): PullRequest`
    - `gitleaksFinding(PullRequest $pullRequest, array $attributes = []): PullRequestReviewFinding`

- [ ] **Step 1: Add the shared fixtures to `tests/Pest.php`** (append at the end of the file, and add the `use` lines at the top)

```php
use App\Enums\GIT\FindingSource;
use App\Enums\GIT\PullRequestState;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReviewFinding;

/**
 * Create a tracked repository for secret-scanning tests.
 */
function secretScanRepository(array $attributes = []): GitRepository
{
    $account = GitAccount::query()->firstOrCreate(
        ['provider' => GitProvider::Github, 'provider_user_id' => '777'],
        ['access_token' => 'token', 'connected_at' => now()],
    );

    return GitRepository::query()->create([
        'git_account_id' => $account->id,
        'provider' => GitProvider::Github,
        'provider_repo_id' => random_int(1, 999999),
        'owner_login' => 'octocat',
        'name' => 'app',
        'full_name' => 'octocat/app',
        'default_branch' => 'main',
        'reviews_enabled' => true,
        ...$attributes,
    ]);
}

/**
 * Create an open pull request number 7 targeting main.
 */
function secretScanPullRequest(GitRepository $repository, array $attributes = []): PullRequest
{
    return PullRequest::query()->create([
        'git_repository_id' => $repository->id,
        'provider_pr_id' => random_int(1, 999999),
        'number' => 7,
        'title' => 'Add config',
        'state' => PullRequestState::Open->value,
        'author_login' => 'octocat',
        'source_branch' => 'feature/config',
        'target_branch' => 'main',
        'head_sha' => 'head-sha-1',
        'opened_at' => now()->subHour(),
        ...$attributes,
    ]);
}

/**
 * Create an open gitleaks finding with no AI review behind it.
 */
function gitleaksFinding(PullRequest $pullRequest, array $attributes = []): PullRequestReviewFinding
{
    return PullRequestReviewFinding::query()->create([
        'pull_request_review_id' => null,
        'pull_request_id' => $pullRequest->id,
        'git_repository_id' => $pullRequest->git_repository_id,
        'source' => FindingSource::Gitleaks->value,
        'dedupe_key' => 'gitleaks:aws-access-token:config/app.php:'.substr(md5(uniqid()), 0, 16),
        'title' => 'Secret detected: AWS Access Key',
        'severity' => 'critical',
        'category' => 'security',
        'file' => 'config/app.php',
        'line' => 3,
        'confidence' => 1.0,
        'explanation' => 'Rule `aws-access-token` matched `REDACTED`.',
        'suggested_fix' => 'Rotate the credential.',
        ...$attributes,
    ]);
}
```

- [ ] **Step 2: Write the failing schema test**

`tests/Feature/GIT/SecretScanning/SecretScanSchemaTest.php`:

```php
<?php

use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSource;
use App\Enums\GIT\SecretScanStatus;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\SecretScan;

it('stores a gitleaks finding that belongs to a scan instead of a review', function () {
    $repository = secretScanRepository();
    $pullRequest = secretScanPullRequest($repository);

    $scan = SecretScan::query()->create([
        'pull_request_id' => $pullRequest->id,
        'git_repository_id' => $repository->id,
        'head_sha' => 'head-sha-1',
        'status' => SecretScanStatus::Completed,
        'findings_count' => 1,
    ]);

    $finding = gitleaksFinding($pullRequest, ['secret_scan_id' => $scan->id]);

    expect($finding->fresh()->source)->toBe(FindingSource::Gitleaks)
        ->and($finding->fresh()->pull_request_review_id)->toBeNull()
        ->and($finding->secretScan->is($scan))->toBeTrue()
        ->and($scan->findings()->count())->toBe(1);
});

it('defaults findings that do not name a source to ai', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());

    $finding = PullRequestReviewFinding::query()->create([
        'pull_request_id' => $pullRequest->id, 'git_repository_id' => $pullRequest->git_repository_id,
        'dedupe_key' => 'k', 'title' => 't', 'severity' => 'high', 'category' => 'correctness',
        'file' => 'a.php', 'confidence' => 0.9, 'explanation' => 'e', 'suggested_fix' => 'f',
    ]);

    expect($finding->fresh()->source)->toBe(FindingSource::Ai);
});

it('turns secret scanning on for new repositories by default', function () {
    expect(secretScanRepository()->fresh()->secret_scanning_enabled)->toBeTrue();
});

it('knows the secret removed resolution', function () {
    expect(FindingResolutionType::SecretRemoved->value)->toBe('secret_removed')
        ->and(FindingResolutionType::SecretRemoved->label())->toBe('Secret removed from diff');
});
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `php artisan test tests/Feature/GIT/SecretScanning/SecretScanSchemaTest.php`
Expected: FAIL with `Class "App\Models\GIT\SecretScan" not found`.

- [ ] **Step 4: Add the enums**

`app/Enums/GIT/FindingSource.php`:

```php
<?php

namespace App\Enums\GIT;

/**
 * What produced a finding: the AI review or the gitleaks secret scan.
 */
enum FindingSource: string implements \JsonSerializable
{
    case Ai = 'ai';
    case Gitleaks = 'gitleaks';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ai => 'AI review',
            self::Gitleaks => 'Secrets',
        };
    }

    /**
     * Serialize as the backing string value.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * All backing values, for validation rules and "in" comparisons.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`app/Enums/GIT/SecretScanStatus.php`:

```php
<?php

namespace App\Enums\GIT;

/**
 * Lifecycle of one gitleaks run against a pull request head.
 */
enum SecretScanStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /**
     * All backing values, for validation rules and "in" comparisons.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

In `app/Enums/GIT/FindingResolutionType.php`, add `case SecretRemoved = 'secret_removed';` after `FalsePositive`, and add `self::SecretRemoved => 'Secret removed from diff',` to `label()`.

- [ ] **Step 5: Add the migrations**

`database/migrations/2026_09_28_100000_create_secret_scans_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that records each gitleaks run against a pull request head.
     */
    public function up(): void
    {
        Schema::create('secret_scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pull_request_id')->constrained('pull_requests')->cascadeOnDelete();
            $table->foreignUuid('git_repository_id')->constrained('git_repositories')->cascadeOnDelete();
            $table->string('head_sha', 40);
            $table->string('status', 20);
            $table->unsignedInteger('findings_count')->default(0);
            $table->unsignedInteger('files_scanned')->default(0);
            $table->unsignedInteger('files_skipped')->default(0);
            $table->unsignedBigInteger('check_run_id')->nullable();
            $table->string('notes_commit_sha', 40)->nullable();
            $table->string('gitleaks_version', 40)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['pull_request_id', 'head_sha']);
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::dropIfExists('secret_scans');
    }
};
```

`database/migrations/2026_09_28_100001_add_source_to_pull_request_review_findings.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let findings come from the secret scan as well as from an AI review.
     *
     * A gitleaks finding has no review behind it, so the review key becomes optional
     * and the scan that produced the finding is recorded instead.
     */
    public function up(): void
    {
        Schema::table('pull_request_review_findings', function (Blueprint $table) {
            $table->foreignUuid('pull_request_review_id')->nullable()->change();
            $table->string('source', 20)->default('ai')->after('git_repository_id');
            $table->foreignUuid('secret_scan_id')->nullable()->after('pull_request_review_id')
                ->constrained('secret_scans')->cascadeOnDelete();

            $table->index(['pull_request_id', 'source']);
        });
    }

    /**
     * Reverse the schema change. Findings without a review cannot survive it.
     */
    public function down(): void
    {
        DB::table('pull_request_review_findings')->whereNull('pull_request_review_id')->delete();

        Schema::table('pull_request_review_findings', function (Blueprint $table) {
            $table->dropIndex(['pull_request_id', 'source']);
            $table->dropConstrainedForeignId('secret_scan_id');
            $table->dropColumn('source');
            $table->foreignUuid('pull_request_review_id')->nullable(false)->change();
        });
    }
};
```

`database/migrations/2026_09_28_100002_add_secret_scanning_enabled_to_git_repositories.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Apply the schema change.
     */
    public function up(): void
    {
        Schema::table('git_repositories', function (Blueprint $table) {
            $table->boolean('secret_scanning_enabled')->default(true)->after('reviews_enabled');
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::table('git_repositories', function (Blueprint $table) {
            $table->dropColumn('secret_scanning_enabled');
        });
    }
};
```

- [ ] **Step 6: Add the model**

`app/Models/GIT/SecretScan.php`:

```php
<?php

namespace App\Models\GIT;

use App\Enums\GIT\SecretScanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'pull_request_id',
    'git_repository_id',
    'head_sha',
    'status',
    'findings_count',
    'files_scanned',
    'files_skipped',
    'check_run_id',
    'notes_commit_sha',
    'gitleaks_version',
    'duration_ms',
    'error',
])]
class SecretScan extends Model
{
    use HasUuids;

    /**
     * Attribute casts for this model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SecretScanStatus::class,
            'findings_count' => 'integer',
            'files_scanned' => 'integer',
            'files_skipped' => 'integer',
            'check_run_id' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * The pull request this scan ran against.
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    /**
     * The repository this scan ran against.
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
    }

    /**
     * The findings this scan produced.
     */
    public function findings(): HasMany
    {
        return $this->hasMany(PullRequestReviewFinding::class);
    }
}
```

In `app/Models/GIT/PullRequestReviewFinding.php`:
- Add `'secret_scan_id',` and `'source',` to `#[Fillable]`, after `'git_repository_id'`.
- Add `'source' => FindingSource::class,` to `casts()`, and import `App\Enums\GIT\FindingSource`.
- Add this relation:

```php
    /**
     * The secret scan that produced this finding, when it came from gitleaks.
     */
    public function secretScan(): BelongsTo
    {
        return $this->belongsTo(SecretScan::class);
    }
```

In `app/Models/GIT/GitRepository.php`, add `'secret_scanning_enabled',` to `#[Fillable]` and `'secret_scanning_enabled' => 'boolean',` to `casts()`.

- [ ] **Step 7: Run the test and the full suite**

Run: `php artisan test tests/Feature/GIT/SecretScanning/SecretScanSchemaTest.php`, then `php artisan test`
Expected: all PASS. If the `->change()` call fails on SQLite, check the Laravel version: native column modification needs Laravel 11 or later. Otherwise require `doctrine/dbal`.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_09_28_1000* app/Enums/GIT app/Models/GIT tests/Pest.php tests/Feature/GIT/SecretScanning/SecretScanSchemaTest.php
git commit -m "feat(secrets): store secret scans and findings without an AI review"
```

---

### Task 2: Reports count findings that have no review

**Files:**
- Modify: `app/Services/Reports/OverviewReportService.php:109-117`, `app/Services/Reports/LeaderboardReportService.php:108-120`, `app/Services/Reports/DailyActivityReportService.php:141-158` and `app/Services/Reports/DeveloperMetricsReportService.php:265-275`
- Test: `tests/Feature/GIT/SecretScanning/ReportQueriesTest.php`

**Interfaces:**
- Consumes: `FindingSource` (Task 1).

The report SQL is PostgreSQL-specific, so these tests compile it under `DB::pretend()`, following `tests/Feature/Reports/DeveloperProfileReportTest.php`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Reports\DailyActivityReportService;
use App\Services\Reports\DeveloperMetricsReportService;
use App\Services\Reports\LeaderboardReportService;
use App\Services\Reports\OverviewReportService;
use App\Support\Reports\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Compile every query a report runs, without executing it.
 *
 * @return array<int, string>
 */
function reportSql(callable $run): array
{
    return array_map(static fn (array $q): string => $q['query'], DB::pretend($run));
}

$innerJoinOnReview = 'inner join "pull_request_reviews" as "rev" on "rev"."id" = "f"."pull_request_review_id"';

it('counts overview findings without requiring a review', function () use ($innerJoinOnReview) {
    $sql = reportSql(fn () => app(OverviewReportService::class)->handle(ReportPeriod::AllTime));

    expect(collect($sql)->filter(fn ($s) => str_contains($s, $innerJoinOnReview)))->toBeEmpty();
});

it('counts leaderboard findings without requiring a review', function () use ($innerJoinOnReview) {
    $sql = reportSql(fn () => app(LeaderboardReportService::class)->handle(ReportPeriod::AllTime));

    expect(collect($sql)->filter(fn ($s) => str_contains($s, $innerJoinOnReview)))->toBeEmpty();
});

it('dates daily findings by review when there is one and by creation otherwise', function () use ($innerJoinOnReview) {
    $sql = collect(reportSql(fn () => app(DailyActivityReportService::class)->handle(ReportPeriod::LastWeek)))
        ->first(fn ($s) => str_contains($s, 'critical_findings'));

    expect($sql)->not->toBeNull()
        ->and($sql)->not->toContain($innerJoinOnReview)
        ->and($sql)->toContain('left join "pull_request_reviews" as "rev"')
        ->and($sql)->toContain('COALESCE(rev.reviewed_at, f.created_at)');
});

it('scores developer seniority on ai findings only', function () {
    $sql = collect(reportSql(fn () => app(DeveloperMetricsReportService::class)->handle(ReportPeriod::AllTime)))
        ->first(fn ($s) => str_contains($s, '"f"."id" as "finding_id"'));

    expect($sql)->not->toBeNull()->and($sql)->toContain('"f"."source" = ?');
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Feature/GIT/SecretScanning/ReportQueriesTest.php`
Expected: FAIL on all four tests (the inner join is present, and there is no source filter).

- [ ] **Step 3: Fix the queries**

In `OverviewReportService::findings()` and `LeaderboardReportService::findingTotals()`, replace these two join lines:

```php
            ->join(Table::as(PullRequestReview::class, 'rev'), 'rev.id', '=', 'f.pull_request_review_id')
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'rev.pull_request_id')
```

with:

```php
            // Joined on the finding's own pull request: a secret-scan finding has no review.
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'f.pull_request_id')
```

Update the `findings()` docblock ("Findings joined through their review…") to read "Findings joined to the pull request author.". Remove the `PullRequestReview` import from a file only if nothing else in it uses the class (`OverviewReportService::reviews()` still does).

In `DailyActivityReportService::findingsByDate()`, replace the body with:

```php
        $day = 'CAST(COALESCE(rev.reviewed_at, f.created_at) AS DATE)';

        return PullRequestReviewFinding::query()
            ->from(Table::as(PullRequestReviewFinding::class, 'f'))
            ->leftJoin(Table::as(PullRequestReview::class, 'rev'), 'rev.id', '=', 'f.pull_request_review_id')
            ->join(Table::as(PullRequest::class, 'pr'), 'pr.id', '=', 'f.pull_request_id')
            ->where(DB::raw('COALESCE(rev.reviewed_at, f.created_at)'), '>=', $since)
            ->when($authorLogin, fn (Builder $q) => $q->where('pr.author_login', $authorLogin))
            ->select([
                DB::raw("{$day} as date"),
                DB::raw('COUNT(f.id) as findings'),
                DB::raw("SUM(CASE WHEN f.severity = '".FindingSeverity::Critical->value."' THEN 1 ELSE 0 END) as critical_findings"),
            ])
            ->groupBy(DB::raw($day))
            ->get()
            ->keyBy('date');
```

Update its docblock to: "Findings are dated by the review that produced them, so they line up with the review counts on the same row. Secret-scan findings have no review and are dated by when they were found."

In `DeveloperMetricsReportService::findingBreakdownByAuthor()`, add after the `joinSub(...)` line:

```php
            // Seniority reads the author's AI review history; a leaked secret is scored
            // by the security team, not folded into the code-quality signal.
            ->where('f.source', FindingSource::Ai->value)
```

and import `App\Enums\GIT\FindingSource`.

- [ ] **Step 4: Run the tests**

Run: `php artisan test tests/Feature/GIT/SecretScanning/ReportQueriesTest.php tests/Feature/Reports`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Reports tests/Feature/GIT/SecretScanning/ReportQueriesTest.php
git commit -m "fix(reports): count findings that have no review behind them"
```

---

### Task 3: Keep AI-only flows away from secret findings

**Files:**
- Modify: `app/Jobs/GIT/CheckFindingResolutions.php:52-55` and `app/Jobs/GIT/DisputePullRequestFinding.php:69-73`
- Test: `tests/Feature/GIT/SecretScanning/AiOnlyFlowsTest.php`

**Interfaces:**
- Consumes: `FindingSource`, and `gitleaksFinding()` / `secretScanPullRequest()` / `secretScanRepository()` (Task 1).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\GIT\PullRequestCommentType;
use App\Jobs\GIT\CheckFindingResolutions;
use App\Jobs\GIT\DisputePullRequestFinding;
use App\Models\GIT\PullRequestComment;
use Illuminate\Support\Facades\Http;

it('does not resolve a secret finding just because its file changed', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    $finding = gitleaksFinding($pullRequest, ['is_posted' => true, 'provider_comment_id' => 555]);

    Http::fake([
        'api.github.com/repos/octocat/app/pulls/7/files*' => Http::response([
            ['filename' => 'config/app.php', 'status' => 'modified', 'patch' => "@@ -1 +1 @@\n-a\n+b"],
        ]),
        '*' => Http::response([], 201),
    ]);

    app()->call([new CheckFindingResolutions($pullRequest->id, 'head-sha-2'), 'handle']);

    expect($finding->fresh()->resolved_at)->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/pulls/7/comments'));
});

it('does not dispute a secret finding when someone replies to its comment', function () {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    $finding = gitleaksFinding($pullRequest, ['is_posted' => true, 'provider_comment_id' => 555]);

    $comment = PullRequestComment::query()->create([
        'pull_request_id' => $pullRequest->id,
        'pull_request_review_finding_id' => $finding->id,
        'provider_comment_id' => 556,
        'provider_in_reply_to_id' => 555,
        'comment_type' => PullRequestCommentType::ReviewComment->value,
        'author_login' => 'octocat',
        'author_type' => 'User',
        'body' => 'This is a fake key, it is fine.',
        'is_pull_lens' => false,
    ]);

    Http::fake();

    app()->call([new DisputePullRequestFinding($comment->id), 'handle']);

    expect($finding->fresh()->resolved_at)->toBeNull();
    Http::assertNothingSent();
});
```

Before running, check the `DisputePullRequestFinding` constructor. If it takes more than `$commentId`, pass the same arguments `ReviewCommentEventHandler.php:53` passes.

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Feature/GIT/SecretScanning/AiOnlyFlowsTest.php`
Expected:
- The first test FAILS because `resolved_at` is set.
- The second test FAILS because the AI agent is invoked or an error is thrown while resolving the AI provider.

- [ ] **Step 3: Add the guards**

In `CheckFindingResolutions::handle()`, change the findings query to:

```php
        $findings = PullRequestReviewFinding::where('pull_request_id', $pullRequest->id)
            // A leaked secret stays in git history after its line changes, so only a
            // rescan by ScanPullRequestSecrets may resolve it.
            ->where('source', FindingSource::Ai->value)
            ->whereNull('resolved_at')
            ->whereNotNull('file')
            ->get();
```

In `DisputePullRequestFinding::handle()`, change the guard to:

```php
        // Only AI findings can be argued with. A secret-scan hit is a pattern match,
        // and "it's a fake key" is settled by a human resolving it, not by the model.
        if (! $finding || $finding->resolved_at !== null || $finding->source !== FindingSource::Ai) {
            return;
        }
```

Import `App\Enums\GIT\FindingSource` in both files.

- [ ] **Step 4: Run the tests**

Run: `php artisan test tests/Feature/GIT/SecretScanning/AiOnlyFlowsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/GIT/CheckFindingResolutions.php app/Jobs/GIT/DisputePullRequestFinding.php tests/Feature/GIT/SecretScanning/AiOnlyFlowsTest.php
git commit -m "fix(findings): keep AI resolution and dispute flows off secret findings"
```

---

### Task 4: Patch added-lines extractor

**Files:**
- Create: `app/Services/Git/SecretScanning/PatchAddedLinesExtractor.php`
- Test: `tests/Unit/SecretScanning/PatchAddedLinesExtractorTest.php`

**Interfaces:**
- Produces: `PatchAddedLinesExtractor::addedLines(string $patch): array<int, string>`. It maps each new-file line number (1-based) to the added line's content, without the leading `+` and without a trailing `\r`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Git\SecretScanning\PatchAddedLinesExtractor;

function added(string $patch): array
{
    return (new PatchAddedLinesExtractor)->addedLines($patch);
}

it('keeps added lines at their new-file line numbers', function () {
    $patch = "@@ -1,3 +1,4 @@\n line one\n-old two\n+new two\n+new three\n line four";

    expect(added($patch))->toBe([2 => 'new two', 3 => 'new three']);
});

it('follows multiple hunks', function () {
    $patch = "@@ -1,2 +1,2 @@\n a\n+b\n@@ -10,2 +10,3 @@\n x\n+y\n+z";

    expect(added($patch))->toBe([2 => 'b', 11 => 'y', 12 => 'z']);
});

it('reads a brand new file', function () {
    expect(added("@@ -0,0 +1,2 @@\n+KEY=abc\n+OTHER=def"))->toBe([1 => 'KEY=abc', 2 => 'OTHER=def']);
});

it('ignores the no-newline marker and strips carriage returns', function () {
    $patch = "@@ -1 +1 @@\n-a\r\n+b\r\n\\ No newline at end of file";

    expect(added($patch))->toBe([1 => 'b']);
});

it('returns nothing for a deletion-only patch', function () {
    expect(added("@@ -1,2 +0,0 @@\n-a\n-b"))->toBe([]);
});

it('keeps an added blank line', function () {
    expect(added("@@ -1 +1,2 @@\n a\n+"))->toBe([2 => '']);
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Unit/SecretScanning/PatchAddedLinesExtractorTest.php`
Expected: FAIL with `Class "App\Services\Git\SecretScanning\PatchAddedLinesExtractor" not found`.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Git\SecretScanning;

/**
 * Reads the lines a unified-diff patch adds, keyed by their line number in the new file.
 *
 * Only added lines are scanned for secrets: context lines were already in the base
 * branch, and removed lines are not part of what the pull request introduces.
 */
class PatchAddedLinesExtractor
{
    /**
     * Map each added line's new-file line number to its content.
     *
     * @return array<int, string>
     */
    public function addedLines(string $patch): array
    {
        $added = [];
        $newLine = 0;
        $inHunk = false;

        foreach (explode("\n", $patch) as $row) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $row, $match) === 1) {
                $newLine = (int) $match[1];
                $inHunk = true;

                continue;
            }

            if (! $inHunk || $row === '') {
                continue;
            }

            $marker = $row[0];
            $content = rtrim(substr($row, 1), "\r");

            if ($marker === '+') {
                $added[$newLine] = $content;
                $newLine++;
            } elseif ($marker === ' ') {
                $newLine++;
            }
            // '-' (removed) and '\' (no-newline marker) do not exist in the new file.
        }

        return $added;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Unit/SecretScanning/PatchAddedLinesExtractorTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Git/SecretScanning/PatchAddedLinesExtractor.php tests/Unit/SecretScanning
git commit -m "feat(secrets): read the lines a pull request patch adds"
```

---

### Task 5: Gitleaks runner and configuration

**Files:**
- Create: `app/Services/Git/SecretScanning/GitleaksHit.php`, `GitleaksFailed.php` and `GitleaksRunner.php`
- Modify: `config/pulllens.php` (before the "Landing page analytics" block) and `.env.example`
- Test: `tests/Feature/GIT/SecretScanning/GitleaksRunnerTest.php`

**Interfaces:**
- Produces:
  - `final readonly class GitleaksHit(string $ruleId, string $description, string $file, int $line, string $match)`.
  - `class GitleaksFailed extends \RuntimeException`.
  - `GitleaksRunner::isAvailable(): bool`.
  - `GitleaksRunner::version(): ?string`.
  - `GitleaksRunner::run(string $directory, ?string $configPath = null, ?string $ignorePath = null): list<GitleaksHit>`. It runs with the working directory set to `$directory`, so hit paths are relative. It throws `GitleaksFailed`.
  - Config keys `pulllens.secret_scanning.binary` and `pulllens.secret_scanning.timeout`.

- [ ] **Step 1: Add the configuration**

In `config/pulllens.php`, before the "Landing page analytics" section:

```php
    /*
    |---------------------------------------------------------------------------
    | Secret scanning
    |---------------------------------------------------------------------------
    |
    | Pull request diffs are scanned for leaked credentials with gitleaks, the free
    | MIT-licensed CLI. The Docker image and install.sh put the binary on PATH;
    | point GITLEAKS_BINARY elsewhere to use a different build. When the binary is
    | missing the scan is skipped and logged rather than failing the queue.
    |
    */

    'secret_scanning' => [
        'binary' => env('GITLEAKS_BINARY', 'gitleaks'),
        'timeout' => (int) env('GITLEAKS_TIMEOUT', 60),
    ],
```

Append to `.env.example`:

```
# Secret scanning (gitleaks). Leave the binary as "gitleaks" when it is on PATH.
GITLEAKS_BINARY=gitleaks
GITLEAKS_TIMEOUT=60
```

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Services\Git\SecretScanning\GitleaksFailed;
use App\Services\Git\SecretScanning\GitleaksHit;
use App\Services\Git\SecretScanning\GitleaksRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * Fake the gitleaks binary: `version` prints a version, and a scan writes $report
 * to the path passed after --report-path, the way the real binary does.
 */
function fakeGitleaksReport(?string $report, int $exitCode = 0): void
{
    Process::fake(function (PendingProcess $process) use ($report, $exitCode) {
        $command = (array) $process->command;

        if (in_array('version', $command, true)) {
            return Process::result('8.28.0');
        }

        $path = $command[array_search('--report-path', $command, true) + 1];

        if ($report !== null) {
            file_put_contents($path, $report);
        }

        return Process::result('', 'boom', $exitCode);
    });
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/gitleaks-runner-'.uniqid();
    mkdir($this->dir);
});

afterEach(fn () => @rmdir($this->dir));

it('runs gitleaks redacted over the directory and parses its report', function () {
    fakeGitleaksReport(json_encode([[
        'RuleID' => 'aws-access-token',
        'Description' => 'AWS Access Key',
        'File' => './config/app.php',
        'StartLine' => 3,
        'Match' => 'AWS_KEY=REDACTED',
        'Secret' => 'REDACTED',
    ]]));

    $hits = app(GitleaksRunner::class)->run($this->dir);

    expect($hits)->toEqual([new GitleaksHit('aws-access-token', 'AWS Access Key', 'config/app.php', 3, 'AWS_KEY=REDACTED')]);

    Process::assertRan(function (PendingProcess $process) {
        $command = (array) $process->command;

        return $command[0] === 'gitleaks'
            && $command[1] === 'dir'
            && $command[2] === '.'
            && in_array('--redact', $command, true)
            && $process->path === $this->dir
            && ! in_array('--config', $command, true);
    });
});

it('passes the base branch config and ignore file when given', function () {
    fakeGitleaksReport('[]');

    app(GitleaksRunner::class)->run($this->dir, '/tmp/x/.gitleaks.toml', '/tmp/x/.gitleaksignore');

    Process::assertRan(function (PendingProcess $process) {
        $command = implode(' ', (array) $process->command);

        return str_contains($command, '--config /tmp/x/.gitleaks.toml')
            && str_contains($command, '--gitleaks-ignore-path /tmp/x/.gitleaksignore');
    });
});

it('returns no hits for an empty report', function () {
    fakeGitleaksReport('[]');

    expect(app(GitleaksRunner::class)->run($this->dir))->toBe([]);
});

it('fails when gitleaks exits non-zero', function () {
    fakeGitleaksReport(null, 2);

    app(GitleaksRunner::class)->run($this->dir);
})->throws(GitleaksFailed::class, 'gitleaks exited with code 2');

it('fails when the report is not json', function () {
    fakeGitleaksReport('not json');

    app(GitleaksRunner::class)->run($this->dir);
})->throws(GitleaksFailed::class, 'not valid JSON');

it('reports the binary as missing when it cannot run', function () {
    Process::fake(fn () => Process::result('', 'not found', 127));

    expect(app(GitleaksRunner::class)->isAvailable())->toBeFalse();
});

it('reads the gitleaks version', function () {
    fakeGitleaksReport('[]');

    expect(app(GitleaksRunner::class)->version())->toBe('8.28.0');
});
```

- [ ] **Step 3: Run it to confirm it fails**

Run: `php artisan test tests/Feature/GIT/SecretScanning/GitleaksRunnerTest.php`
Expected: FAIL with `Class "App\Services\Git\SecretScanning\GitleaksRunner" not found`.

- [ ] **Step 4: Implement**

`app/Services/Git/SecretScanning/GitleaksHit.php`:

```php
<?php

namespace App\Services\Git\SecretScanning;

/**
 * One redacted gitleaks result, with its path relative to the scanned directory.
 */
final readonly class GitleaksHit
{
    /**
     * Create the hit.
     */
    public function __construct(
        public string $ruleId,
        public string $description,
        public string $file,
        public int $line,
        public string $match,
    ) {}
}
```

`app/Services/Git/SecretScanning/GitleaksFailed.php`:

```php
<?php

namespace App\Services\Git\SecretScanning;

use RuntimeException;

/**
 * Gitleaks ran but did not produce a usable report.
 */
class GitleaksFailed extends RuntimeException {}
```

`app/Services/Git/SecretScanning/GitleaksRunner.php`:

```php
<?php

namespace App\Services\Git\SecretScanning;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Runs the gitleaks CLI over a directory and returns its redacted findings.
 *
 * The process runs from inside the directory and scans ".", so every reported path
 * is relative - which is also the form .gitleaksignore fingerprints use.
 */
class GitleaksRunner
{
    /** Longest match text kept, so one minified line cannot flood a comment. */
    private const MAX_MATCH_LENGTH = 200;

    private ?string $version = null;

    /**
     * Whether the configured binary can be executed.
     */
    public function isAvailable(): bool
    {
        return $this->version() !== null;
    }

    /**
     * The gitleaks version string, or null when the binary cannot run.
     */
    public function version(): ?string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        try {
            $result = Process::timeout(10)->run([$this->binary(), 'version']);
        } catch (Throwable) {
            return null;
        }

        $version = trim($result->output());

        if (! $result->successful() || $version === '') {
            return null;
        }

        return $this->version = mb_substr($version, 0, 40);
    }

    /**
     * Scan a directory and return the redacted hits.
     *
     * @return list<GitleaksHit>
     *
     * @throws GitleaksFailed
     */
    public function run(string $directory, ?string $configPath = null, ?string $ignorePath = null): array
    {
        $reportPath = rtrim($directory, '/').'.report.json';

        $command = [
            $this->binary(), 'dir', '.',
            '--redact',
            '--no-banner',
            '--no-color',
            '--log-level', 'error',
            '--exit-code', '0',
            '--report-format', 'json',
            '--report-path', $reportPath,
        ];

        if ($configPath !== null) {
            array_push($command, '--config', $configPath);
        }

        if ($ignorePath !== null) {
            array_push($command, '--gitleaks-ignore-path', $ignorePath);
        }

        try {
            try {
                $result = Process::path($directory)->timeout($this->timeout())->run($command);
            } catch (ProcessTimedOutException $e) {
                throw new GitleaksFailed("gitleaks timed out after {$this->timeout()} seconds", previous: $e);
            }

            if (! $result->successful()) {
                throw new GitleaksFailed(sprintf(
                    'gitleaks exited with code %d: %s',
                    $result->exitCode(),
                    mb_substr(trim($result->errorOutput()), 0, 500),
                ));
            }

            if (! is_file($reportPath)) {
                throw new GitleaksFailed('gitleaks wrote no report');
            }

            return $this->parse((string) file_get_contents($reportPath));
        } finally {
            @unlink($reportPath);
        }
    }

    /**
     * Turn the JSON report into hits.
     *
     * @return list<GitleaksHit>
     */
    private function parse(string $report): array
    {
        $decoded = json_decode($report, true);

        if (! is_array($decoded)) {
            throw new GitleaksFailed('gitleaks report is not valid JSON');
        }

        $hits = [];

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $hits[] = new GitleaksHit(
                ruleId: (string) ($row['RuleID'] ?? ''),
                description: (string) ($row['Description'] ?? ''),
                file: ltrim((string) preg_replace('#^\./#', '', (string) ($row['File'] ?? '')), '/'),
                line: (int) ($row['StartLine'] ?? 0),
                match: mb_substr((string) ($row['Match'] ?? ''), 0, self::MAX_MATCH_LENGTH),
            );
        }

        return $hits;
    }

    /**
     * The configured binary path.
     */
    private function binary(): string
    {
        return (string) config('pulllens.secret_scanning.binary', 'gitleaks');
    }

    /**
     * The configured scan timeout in seconds.
     */
    private function timeout(): int
    {
        return max(5, (int) config('pulllens.secret_scanning.timeout', 60));
    }
}
```

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Feature/GIT/SecretScanning/GitleaksRunnerTest.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Git/SecretScanning config/pulllens.php .env.example tests/Feature/GIT/SecretScanning/GitleaksRunnerTest.php
git commit -m "feat(secrets): run gitleaks redacted over a directory"
```

---

### Task 6: Secret scanner (fetch, mirror, run, map)

**Files:**
- Create: `app/Services/Git/SecretScanning/SecretHit.php`, `SecretScanResult.php` and `SecretScanner.php`
- Test: `tests/Feature/GIT/SecretScanning/SecretScannerTest.php`

**Interfaces:**
- Consumes:
  - `PatchAddedLinesExtractor::addedLines()` (Task 4).
  - `GitleaksRunner::run()` and `GitleaksHit` (Task 5).
  - `GitHubApiClient::pullRequestFiles()` and `GitHubApiClient::fetchFileContent()` (existing).
- Produces:
  - `final readonly class SecretHit(string $ruleId, string $description, string $file, int $line, string $match, string $contentHash)`, plus `SecretHit::dedupeKey(): string`.
  - `final readonly class SecretScanResult(array $hits /* list<SecretHit> */, int $filesScanned, int $filesSkipped)`.
  - `SecretScanner::scan(GitAccount|string $caller, string $owner, string $repo, int $number, string $configRef): SecretScanResult`. `$configRef` is the PR's target branch.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Git\SecretScanning\SecretScanner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Fake gitleaks. Each scan records the mirrored files it was pointed at, then
 * reports $hits.
 */
function fakeGitleaksScan(array $hits, ?array &$seen = null): void
{
    Process::fake(function (PendingProcess $process) use ($hits, &$seen) {
        $command = (array) $process->command;

        if (in_array('version', $command, true)) {
            return Process::result('8.28.0');
        }

        $seen = ['path' => $process->path, 'command' => $command, 'files' => []];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($process->path, FilesystemIterator::SKIP_DOTS)) as $file) {
            $seen['files'][substr($file->getPathname(), strlen($process->path) + 1)] = file_get_contents($file->getPathname());
        }

        foreach (['--config', '--gitleaks-ignore-path'] as $flag) {
            $i = array_search($flag, $command, true);
            $seen[$flag] = $i === false ? null : file_get_contents($command[$i + 1]);
        }

        file_put_contents($command[array_search('--report-path', $command, true) + 1], json_encode($hits));

        return Process::result('');
    });
}

function fakePullFiles(array $files, array $contents = []): void
{
    $fakes = ['api.github.com/repos/octocat/app/pulls/7/files*' => Http::response($files)];

    foreach ($contents as $path => $body) {
        $fakes["api.github.com/repos/octocat/app/contents/{$path}*"] = Http::response(['content' => base64_encode($body)]);
    }

    Http::fake($fakes + ['api.github.com/repos/octocat/app/contents/*' => Http::response([], 404)]);
}

function awsHit(string $file = 'config/app.php', int $line = 2): array
{
    return ['RuleID' => 'aws-access-token', 'Description' => 'AWS Access Key', 'File' => $file, 'StartLine' => $line, 'Match' => 'AWS_KEY=REDACTED'];
}

it('mirrors added lines at their real line numbers and maps hits back', function () {
    fakePullFiles([[
        'filename' => 'config/app.php', 'status' => 'modified',
        'patch' => "@@ -1,2 +1,3 @@\n <?php\n+AWS_KEY=AKIAABCDEFGHIJKLMNOP\n return [];",
    ]]);
    fakeGitleaksScan([awsHit()], $seen);

    $result = app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');

    expect($seen['files'])->toBe(['config/app.php' => "\nAWS_KEY=AKIAABCDEFGHIJKLMNOP"])
        ->and($result->filesScanned)->toBe(1)
        ->and($result->hits)->toHaveCount(1)
        ->and($result->hits[0]->file)->toBe('config/app.php')
        ->and($result->hits[0]->line)->toBe(2)
        ->and($result->hits[0]->contentHash)->toHaveLength(16)
        ->and($result->hits[0]->dedupeKey())->toStartWith('gitleaks:aws-access-token:config/app.php:');
});

it('keeps the same dedupe key when the secret moves to another line', function () {
    $scan = function (string $patch, int $line) {
        fakePullFiles([['filename' => 'config/app.php', 'status' => 'modified', 'patch' => $patch]]);
        fakeGitleaksScan([awsHit(line: $line)]);

        return app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main')->hits[0]->dedupeKey();
    };

    $first = $scan("@@ -1 +1,2 @@\n <?php\n+AWS_KEY=AKIAABCDEFGHIJKLMNOP", 2);
    $moved = $scan("@@ -1 +1,4 @@\n <?php\n+// a\n+// b\n+AWS_KEY=AKIAABCDEFGHIJKLMNOP", 4);

    expect($moved)->toBe($first);
});

it('reads config and ignore files from the target branch, not the pull request', function () {
    fakePullFiles(
        [['filename' => 'a.env', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+X=1"]],
        ['.gitleaks.toml' => "[extend]\nuseDefault = true", '.gitleaksignore' => 'a.env:generic:1'],
    );
    fakeGitleaksScan([], $seen);

    app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');

    expect($seen['--config'])->toBe("[extend]\nuseDefault = true")
        ->and($seen['--gitleaks-ignore-path'])->toBe('a.env:generic:1');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'contents/.gitleaks.toml') && str_contains($r->url(), 'ref=main'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'contents/') && ! str_contains($r->url(), 'ref=main'));
});

it('skips files without a patch, removed files and unsafe paths', function () {
    fakePullFiles([
        ['filename' => 'logo.png', 'status' => 'added'],
        ['filename' => 'old.php', 'status' => 'removed', 'patch' => "@@ -1 +0,0 @@\n-x"],
        ['filename' => '../../etc/evil', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+x"],
        ['filename' => 'ok.php', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+x"],
    ]);
    fakeGitleaksScan([], $seen);

    $result = app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');

    expect($result->filesScanned)->toBe(1)
        ->and($result->filesSkipped)->toBe(3)
        ->and(array_keys($seen['files']))->toBe(['ok.php']);
});

it('does not run gitleaks when nothing was added', function () {
    fakePullFiles([['filename' => 'a.php', 'status' => 'modified', 'patch' => "@@ -1 +0,0 @@\n-x"]]);
    Process::fake();

    $result = app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');

    expect($result->hits)->toBe([]);
    Process::assertNothingRan();
});

it('removes the workspace afterwards', function () {
    fakePullFiles([['filename' => 'a.php', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+x"]]);
    fakeGitleaksScan([], $seen);

    app(SecretScanner::class)->scan('token', 'octocat', 'app', 7, 'main');

    expect(is_dir($seen['path']))->toBeFalse();
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Feature/GIT/SecretScanning/SecretScannerTest.php`
Expected: FAIL with `Class "App\Services\Git\SecretScanning\SecretScanner" not found`.

- [ ] **Step 3: Implement**

`app/Services/Git/SecretScanning/SecretHit.php`:

```php
<?php

namespace App\Services\Git\SecretScanning;

/**
 * A redacted gitleaks hit placed in the pull request, with a stable identity.
 */
final readonly class SecretHit
{
    /**
     * Create the hit.
     */
    public function __construct(
        public string $ruleId,
        public string $description,
        public string $file,
        public int $line,
        public string $match,
        public string $contentHash,
    ) {}

    /**
     * Identity that survives the line moving: rule, file, and a keyed hash of the line.
     */
    public function dedupeKey(): string
    {
        return "gitleaks:{$this->ruleId}:{$this->file}:{$this->contentHash}";
    }
}
```

`app/Services/Git/SecretScanning/SecretScanResult.php`:

```php
<?php

namespace App\Services\Git\SecretScanning;

/**
 * Everything one scan of a pull request diff found.
 */
final readonly class SecretScanResult
{
    /**
     * Create the result.
     *
     * @param  list<SecretHit>  $hits
     */
    public function __construct(
        public array $hits,
        public int $filesScanned,
        public int $filesSkipped,
    ) {}
}
```

`app/Services/Git/SecretScanning/SecretScanner.php`:

```php
<?php

namespace App\Services\Git\SecretScanning;

use App\Models\GIT\GitAccount;
use App\Services\Git\GitHubApiClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Scans what a pull request adds for leaked credentials.
 *
 * Nothing is cloned. Each changed file is rebuilt as a "mirror" holding only its
 * added lines, each at its real line number with every other line left blank, so
 * gitleaks reports positions that map straight back onto the pull request.
 */
class SecretScanner
{
    private const CONFIG_FILE = '.gitleaks.toml';

    private const IGNORE_FILE = '.gitleaksignore';

    /**
     * Inject the collaborators this class delegates to.
     */
    public function __construct(
        private readonly GitHubApiClient $api,
        private readonly PatchAddedLinesExtractor $extractor,
        private readonly GitleaksRunner $runner,
    ) {}

    /**
     * Scan a pull request's added lines, reading gitleaks config from $configRef.
     *
     * $configRef must be the target branch: reading config from the pull request
     * itself would let it allowlist the very secret it adds.
     */
    public function scan(GitAccount|string $caller, string $owner, string $repo, int $number, string $configRef): SecretScanResult
    {
        $files = $this->api->pullRequestFiles($caller, $owner, $repo, $number);

        $workspace = storage_path('app/secret-scans/'.Str::uuid());
        $source = $workspace.'/src';

        File::ensureDirectoryExists($source);

        try {
            $addedByFile = [];
            $skipped = 0;

            foreach ($files as $file) {
                $path = (string) data_get($file, 'filename', '');
                $patch = data_get($file, 'patch');

                if (! $this->isSafePath($path) || data_get($file, 'status') === 'removed' || ! is_string($patch) || $patch === '') {
                    $skipped++;

                    continue;
                }

                $added = $this->extractor->addedLines($patch);

                if ($added === []) {
                    continue;
                }

                $addedByFile[$path] = $added;
                $this->writeMirror($source.'/'.$path, $added);
            }

            if ($addedByFile === []) {
                return new SecretScanResult([], 0, $skipped);
            }

            $config = $this->copyFromBranch($caller, $owner, $repo, $configRef, self::CONFIG_FILE, $workspace);
            $ignore = $this->copyFromBranch($caller, $owner, $repo, $configRef, self::IGNORE_FILE, $workspace);

            $hits = [];

            foreach ($this->runner->run($source, $config, $ignore) as $hit) {
                $content = $addedByFile[$hit->file][$hit->line] ?? null;

                if ($content === null) {
                    continue;
                }

                $hits[] = new SecretHit(
                    ruleId: $hit->ruleId,
                    description: $hit->description,
                    file: $hit->file,
                    line: $hit->line,
                    match: $hit->match,
                    contentHash: substr(hash_hmac('sha256', trim($content), (string) config('app.key')), 0, 16),
                );
            }

            return new SecretScanResult($hits, count($addedByFile), $skipped);
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    /**
     * Write a file whose line N holds added line N, blank everywhere else.
     *
     * @param  array<int, string>  $added
     */
    private function writeMirror(string $path, array $added): void
    {
        $lines = array_fill(1, max(array_keys($added)), '');

        foreach ($added as $number => $content) {
            $lines[$number] = $content;
        }

        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, implode("\n", $lines));
    }

    /**
     * Copy a repository file from a branch into the workspace, returning its path.
     */
    private function copyFromBranch(GitAccount|string $caller, string $owner, string $repo, string $ref, string $name, string $workspace): ?string
    {
        $content = $this->api->fetchFileContent($caller, $owner, $repo, $name, $ref);

        if ($content === null) {
            return null;
        }

        $path = $workspace.'/config/'.$name;
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Whether a provider-supplied path stays inside the workspace.
     */
    private function isSafePath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! in_array('..', explode('/', $path), true);
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Feature/GIT/SecretScanning/SecretScannerTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Git/SecretScanning tests/Feature/GIT/SecretScanning/SecretScannerTest.php
git commit -m "feat(secrets): scan a pull request's added lines with gitleaks"
```

---

### Task 7: Git Data API methods and the notes writer

**Files:**
- Modify: `app/Services/Git/GitHubApiClient.php` (add the methods after `updateCheckRun`)
- Create: `app/Services/Git/SecretScanning/GitHubNotesWriter.php`
- Test: `tests/Feature/GIT/SecretScanning/GitHubNotesWriterTest.php`

**Interfaces:**
- Produces:
  - `GitHubApiClient::gitRef(GitAccount|string $auth, string $owner, string $repo, string $ref): ?array`. It returns null on 404.
  - `GitHubApiClient::gitCommit(GitAccount|string $auth, string $owner, string $repo, string $sha): array`.
  - `GitHubApiClient::createGitBlob(GitAccount|string $auth, string $owner, string $repo, string $content): string`, returning the sha.
  - `GitHubApiClient::createGitTree(GitAccount|string $auth, string $owner, string $repo, ?string $baseTree, array $entries): string`, returning the sha.
  - `GitHubApiClient::createGitCommit(GitAccount|string $auth, string $owner, string $repo, string $message, string $tree, array $parents): string`, returning the sha.
  - `GitHubApiClient::createGitRef(GitAccount|string $auth, string $owner, string $repo, string $ref, string $sha): void`. `$ref` is a full `refs/...` name.
  - `GitHubApiClient::updateGitRef(GitAccount|string $auth, string $owner, string $repo, string $ref, string $sha): void`. `$ref` has no `refs/` prefix.
  - `GitHubNotesWriter::write(GitAccount|string $caller, string $owner, string $repo, string $commitSha, string $note): string`, returning the notes commit sha. `GitHubNotesWriter::REF = 'notes/gitleaks'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Git\SecretScanning\GitHubNotesWriter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

$base = 'api.github.com/repos/octocat/app/git';

it('creates the notes ref on the first note', function () use ($base) {
    Http::fake([
        "{$base}/ref/notes/gitleaks" => Http::response(['message' => 'Not Found'], 404),
        "{$base}/blobs" => Http::response(['sha' => 'blob1'], 201),
        "{$base}/trees" => Http::response(['sha' => 'tree1'], 201),
        "{$base}/commits" => Http::response(['sha' => 'notes1'], 201),
        "{$base}/refs" => Http::response(['ref' => 'refs/notes/gitleaks'], 201),
    ]);

    $sha = app(GitHubNotesWriter::class)->write('token', 'octocat', 'app', 'head-sha-1', 'note text');

    expect($sha)->toBe('notes1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/blobs') && base64_decode($r['content']) === 'note text' && $r['encoding'] === 'base64');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/trees') && ! isset($r['base_tree'])
        && $r['tree'] === [['path' => 'head-sha-1', 'mode' => '100644', 'type' => 'blob', 'sha' => 'blob1']]);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/commits') && $r['parents'] === [] && $r['tree'] === 'tree1');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/git/refs')
        && $r['ref'] === 'refs/notes/gitleaks' && $r['sha'] === 'notes1');
});

it('appends on top of the existing notes', function () use ($base) {
    Http::fake([
        "{$base}/ref/notes/gitleaks" => Http::response(['object' => ['sha' => 'notes0']]),
        "{$base}/commits/notes0" => Http::response(['sha' => 'notes0', 'tree' => ['sha' => 'tree0']]),
        "{$base}/blobs" => Http::response(['sha' => 'blob1'], 201),
        "{$base}/trees" => Http::response(['sha' => 'tree1'], 201),
        "{$base}/commits" => Http::response(['sha' => 'notes1'], 201),
        "{$base}/refs/notes/gitleaks" => Http::response(['ref' => 'refs/notes/gitleaks']),
    ]);

    app(GitHubNotesWriter::class)->write('token', 'octocat', 'app', 'head-sha-1', 'note text');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/trees') && $r['base_tree'] === 'tree0');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/git/commits') && $r['parents'] === ['notes0']);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/git/refs/notes/gitleaks')
        && $r['sha'] === 'notes1' && $r['force'] === false);
});

it('retries once when another writer moved the ref first', function () use ($base) {
    Http::fake([
        "{$base}/ref/notes/gitleaks" => Http::response(['object' => ['sha' => 'notes0']]),
        "{$base}/commits/notes0" => Http::response(['sha' => 'notes0', 'tree' => ['sha' => 'tree0']]),
        "{$base}/blobs" => Http::response(['sha' => 'blob1'], 201),
        "{$base}/trees" => Http::response(['sha' => 'tree1'], 201),
        "{$base}/commits" => Http::response(['sha' => 'notes1'], 201),
        "{$base}/refs/notes/gitleaks" => Http::sequence()
            ->push(['message' => 'Update is not a fast forward'], 422)
            ->push(['ref' => 'refs/notes/gitleaks']),
    ]);

    expect(app(GitHubNotesWriter::class)->write('token', 'octocat', 'app', 'head-sha-1', 'n'))->toBe('notes1');
    Http::assertSentCount(12);
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Feature/GIT/SecretScanning/GitHubNotesWriterTest.php`
Expected: FAIL with `Class "App\Services\Git\SecretScanning\GitHubNotesWriter" not found`.

- [ ] **Step 3: Add the API client methods** (in `GitHubApiClient`, after `updateCheckRun`)

```php
    /**
     * Read a git reference such as "notes/gitleaks", or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function gitRef(GitAccount|string $auth, string $owner, string $repo, string $ref): ?array
    {
        $response = $this->request($auth)
            ->get(self::API_BASE."/repos/{$owner}/{$repo}/git/ref/{$ref}");

        if ($response->status() === 404) {
            return null;
        }

        return (array) $response->throw()->json();
    }

    /**
     * Read a git commit object.
     *
     * @return array<string, mixed>
     */
    public function gitCommit(GitAccount|string $auth, string $owner, string $repo, string $sha): array
    {
        return (array) $this->request($auth)
            ->get(self::API_BASE."/repos/{$owner}/{$repo}/git/commits/{$sha}")
            ->throw()
            ->json();
    }

    /**
     * Store a blob and return its sha.
     */
    public function createGitBlob(GitAccount|string $auth, string $owner, string $repo, string $content): string
    {
        return (string) $this->request($auth)
            ->post(self::API_BASE."/repos/{$owner}/{$repo}/git/blobs", [
                'content' => base64_encode($content),
                'encoding' => 'base64',
            ])
            ->throw()
            ->json('sha');
    }

    /**
     * Create a tree, optionally on top of an existing one, and return its sha.
     *
     * @param  array<int, array<string, string>>  $entries
     */
    public function createGitTree(GitAccount|string $auth, string $owner, string $repo, ?string $baseTree, array $entries): string
    {
        $payload = ['tree' => $entries];

        if ($baseTree !== null) {
            $payload['base_tree'] = $baseTree;
        }

        return (string) $this->request($auth)
            ->post(self::API_BASE."/repos/{$owner}/{$repo}/git/trees", $payload)
            ->throw()
            ->json('sha');
    }

    /**
     * Create a commit object and return its sha.
     *
     * @param  array<int, string>  $parents
     */
    public function createGitCommit(GitAccount|string $auth, string $owner, string $repo, string $message, string $tree, array $parents): string
    {
        return (string) $this->request($auth)
            ->post(self::API_BASE."/repos/{$owner}/{$repo}/git/commits", [
                'message' => $message,
                'tree' => $tree,
                'parents' => $parents,
            ])
            ->throw()
            ->json('sha');
    }

    /**
     * Create a reference; $ref is the full name, e.g. "refs/notes/gitleaks".
     */
    public function createGitRef(GitAccount|string $auth, string $owner, string $repo, string $ref, string $sha): void
    {
        $this->request($auth)
            ->post(self::API_BASE."/repos/{$owner}/{$repo}/git/refs", ['ref' => $ref, 'sha' => $sha])
            ->throw();
    }

    /**
     * Fast-forward a reference; $ref omits "refs/", e.g. "notes/gitleaks".
     */
    public function updateGitRef(GitAccount|string $auth, string $owner, string $repo, string $ref, string $sha): void
    {
        $this->request($auth)
            ->patch(self::API_BASE."/repos/{$owner}/{$repo}/git/refs/{$ref}", ['sha' => $sha, 'force' => false])
            ->throw();
    }
```

- [ ] **Step 4: Implement the writer**

`app/Services/Git/SecretScanning/GitHubNotesWriter.php`:

```php
<?php

namespace App\Services\Git\SecretScanning;

use App\Models\GIT\GitAccount;
use App\Services\Git\GitHubApiClient;
use Illuminate\Http\Client\RequestException;

/**
 * Attaches a git note to a commit through the Git Data API, without a clone.
 *
 * A notes ref is an ordinary commit whose tree maps annotated commit shas to note
 * blobs. Writing one is: blob, tree on top of the previous notes tree, commit
 * parented on the previous notes commit, then a fast-forward of the ref.
 * Read the notes with:
 *   git fetch origin refs/notes/gitleaks:refs/notes/gitleaks && git log --notes=gitleaks
 */
class GitHubNotesWriter
{
    public const REF = 'notes/gitleaks';

    /**
     * Inject the API client this class delegates to.
     */
    public function __construct(private readonly GitHubApiClient $api) {}

    /**
     * Set the note for $commitSha and return the new notes commit sha.
     *
     * A concurrent writer makes the fast-forward fail with 422; the write is rebuilt
     * once on top of their notes commit.
     */
    public function write(GitAccount|string $caller, string $owner, string $repo, string $commitSha, string $note): string
    {
        try {
            return $this->attempt($caller, $owner, $repo, $commitSha, $note);
        } catch (RequestException $e) {
            if ($e->response->status() !== 422) {
                throw $e;
            }

            return $this->attempt($caller, $owner, $repo, $commitSha, $note);
        }
    }

    /**
     * One blob → tree → commit → ref pass.
     */
    private function attempt(GitAccount|string $caller, string $owner, string $repo, string $commitSha, string $note): string
    {
        $parent = data_get($this->api->gitRef($caller, $owner, $repo, self::REF), 'object.sha');
        $baseTree = $parent === null ? null : data_get($this->api->gitCommit($caller, $owner, $repo, $parent), 'tree.sha');

        $blob = $this->api->createGitBlob($caller, $owner, $repo, $note);
        $tree = $this->api->createGitTree($caller, $owner, $repo, $baseTree, [
            ['path' => $commitSha, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob],
        ]);
        $commit = $this->api->createGitCommit(
            $caller, $owner, $repo,
            "gitleaks: notes for {$commitSha}",
            $tree,
            $parent === null ? [] : [$parent],
        );

        if ($parent === null) {
            $this->api->createGitRef($caller, $owner, $repo, 'refs/'.self::REF, $commit);
        } else {
            $this->api->updateGitRef($caller, $owner, $repo, self::REF, $commit);
        }

        return $commit;
    }
}
```

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Feature/GIT/SecretScanning/GitHubNotesWriterTest.php`
Expected: PASS (3 tests). If `assertSentCount(12)` is off, count the requests made (6 per attempt: ref, commit, blob, tree, commit, ref update) and correct the number in the test. Do not change the writer to fit the number.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Git/GitHubApiClient.php app/Services/Git/SecretScanning/GitHubNotesWriter.php tests/Feature/GIT/SecretScanning/GitHubNotesWriterTest.php
git commit -m "feat(secrets): write git notes through the GitHub Git Data API"
```

---

### Task 8: The `ScanPullRequestSecrets` job

**Files:**
- Create: `app/Jobs/GIT/ScanPullRequestSecrets.php`
- Test: `tests/Feature/GIT/SecretScanning/ScanPullRequestSecretsTest.php`

**Interfaces:**
- Consumes:
  - `SecretScanner::scan()`, `SecretHit`, `SecretScanResult` (Task 6).
  - `GitleaksRunner::isAvailable()`, `GitleaksRunner::version()` (Task 5).
  - `GitHubNotesWriter::write()` (Task 7).
  - `GitHubCallerResolver::for()`, `GitHubApiClient::createCheckRun/updateCheckRun/postPullRequestReview/postReviewComment/replyToReviewComment` (existing).
  - The Task 1 models and enums.
- Produces: `new ScanPullRequestSecrets(string $pullRequestId, string $headSha)`, `ScanPullRequestSecrets::CHECK_NAME = 'PullLens / Secrets'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSource;
use App\Enums\GIT\SecretScanStatus;
use App\Jobs\GIT\ScanPullRequestSecrets;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\SecretScan;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Fake gitleaks returning $hits for every scan.
 */
function fakeGitleaks(array $hits, bool $installed = true): void
{
    Process::fake(function (PendingProcess $process) use ($hits, $installed) {
        $command = (array) $process->command;

        if (in_array('version', $command, true)) {
            return $installed ? Process::result('8.28.0') : Process::result('', 'not found', 127);
        }

        file_put_contents($command[array_search('--report-path', $command, true) + 1], json_encode($hits));

        return Process::result('');
    });
}

/**
 * Fake every GitHub call the job makes; $patch is config/app.php's diff.
 */
function fakeGitHubForScan(string $patch): void
{
    $base = 'api.github.com/repos/octocat/app';

    Http::fake([
        "{$base}/pulls/7/files*" => Http::response([['filename' => 'config/app.php', 'status' => 'modified', 'patch' => $patch]]),
        "{$base}/contents/*" => Http::response([], 404),
        "{$base}/check-runs/*" => Http::response([]),
        "{$base}/check-runs" => Http::response(['id' => 99], 201),
        "{$base}/pulls/7/reviews" => Http::response(['id' => 1], 200),
        "{$base}/pulls/7/comments" => Http::response(['id' => 555], 201),
        "{$base}/git/ref/notes/gitleaks" => Http::response([], 404),
        "{$base}/git/blobs" => Http::response(['sha' => 'blob1'], 201),
        "{$base}/git/trees" => Http::response(['sha' => 'tree1'], 201),
        "{$base}/git/commits" => Http::response(['sha' => 'notes1'], 201),
        "{$base}/git/refs" => Http::response([], 201),
    ]);
}

function runSecretScan(string $pullRequestId, string $headSha = 'head-sha-1'): void
{
    app()->call([new ScanPullRequestSecrets($pullRequestId, $headSha), 'handle']);
}

$leak = "@@ -1 +1,2 @@\n <?php\n+AWS_KEY=AKIAABCDEFGHIJKLMNOP";
$awsHit = fn (int $line = 2) => ['RuleID' => 'aws-access-token', 'Description' => 'AWS Access Key', 'File' => 'config/app.php', 'StartLine' => $line, 'Match' => 'AWS_KEY=REDACTED'];

it('records a redacted critical security finding for each hit', function () use ($leak, $awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);

    runSecretScan($pullRequest->id);

    $scan = SecretScan::query()->sole();
    $finding = PullRequestReviewFinding::query()->sole();

    expect($scan->status)->toBe(SecretScanStatus::Completed)
        ->and($scan->findings_count)->toBe(1)
        ->and($scan->check_run_id)->toBe(99)
        ->and($scan->notes_commit_sha)->toBe('notes1')
        ->and($scan->gitleaks_version)->toBe('8.28.0')
        ->and($finding->source)->toBe(FindingSource::Gitleaks)
        ->and($finding->secret_scan_id)->toBe($scan->id)
        ->and($finding->pull_request_review_id)->toBeNull()
        ->and($finding->severity->value)->toBe('critical')
        ->and($finding->category->value)->toBe('security')
        ->and($finding->line)->toBe(2)
        ->and($finding->is_posted)->toBeTrue()
        ->and($finding->provider_comment_id)->toBe(555)
        ->and($finding->explanation)->toContain('REDACTED')
        ->and($finding->explanation)->not->toContain('AKIAABCDEFGHIJKLMNOP');
});

it('never sends the raw secret to GitHub', function () use ($leak, $awsHit) {
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    Http::assertNotSent(fn (Request $r) => str_contains($r->body(), 'AKIAABCDEFGHIJKLMNOP')
        || str_contains(base64_decode((string) ($r['content'] ?? '')), 'AKIAABCDEFGHIJKLMNOP'));
});

it('fails the secrets check with one annotation per finding', function () use ($leak, $awsHit) {
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/check-runs')
        && $r['name'] === 'PullLens / Secrets' && $r['head_sha'] === 'head-sha-1');
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/check-runs/99')
        && $r['conclusion'] === 'failure'
        && $r['output']['annotations'][0]['path'] === 'config/app.php'
        && $r['output']['annotations'][0]['start_line'] === 2
        && $r['output']['annotations'][0]['annotation_level'] === 'failure');
});

it('posts a comment review with an inline comment on the secret line', function () use ($leak, $awsHit) {
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls/7/reviews') && $r['event'] === 'COMMENT');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pulls/7/comments')
        && $r['path'] === 'config/app.php' && $r['line'] === 2 && $r['commit_id'] === 'head-sha-1');
});

it('writes a git note on the head commit', function () use ($leak, $awsHit) {
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/trees') && $r['tree'][0]['path'] === 'head-sha-1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/git/blobs')
        && str_contains(base64_decode($r['content']), 'config/app.php:2 aws-access-token'));
});

it('passes the check and writes no note or comments when the diff is clean', function () use ($leak) {
    fakeGitHubForScan($leak);
    fakeGitleaks([]);

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    expect(PullRequestReviewFinding::query()->count())->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['conclusion'] === 'success');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/git/') || str_contains($r->url(), '/pulls/7/reviews'));
});

it('does not comment twice when the secret only moved', function () use ($awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());

    fakeGitHubForScan("@@ -1 +1,2 @@\n <?php\n+AWS_KEY=AKIAABCDEFGHIJKLMNOP");
    fakeGitleaks([$awsHit(2)]);
    runSecretScan($pullRequest->id, 'head-sha-1');

    fakeGitHubForScan("@@ -1 +1,3 @@\n <?php\n+// config\n+AWS_KEY=AKIAABCDEFGHIJKLMNOP");
    fakeGitleaks([$awsHit(3)]);
    runSecretScan($pullRequest->id, 'head-sha-2');

    $finding = PullRequestReviewFinding::query()->sole();
    $inlineComments = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/pulls/7/comments') && ! isset($r['in_reply_to']));

    expect($finding->resolved_at)->toBeNull()
        ->and($finding->line)->toBe(3)
        ->and($inlineComments)->toHaveCount(1);
});

it('resolves a secret that a later push took out of the diff and says to rotate it', function () use ($leak, $awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());

    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);
    runSecretScan($pullRequest->id, 'head-sha-1');

    fakeGitHubForScan("@@ -1 +1,2 @@\n <?php\n+AWS_KEY=env('AWS_KEY')");
    fakeGitleaks([]);
    runSecretScan($pullRequest->id, 'head-sha-2');

    $finding = PullRequestReviewFinding::query()->sole();

    expect($finding->resolved_at)->not->toBeNull()
        ->and($finding->resolution_type)->toBe(FindingResolutionType::SecretRemoved);
    Http::assertSent(fn (Request $r) => ($r['in_reply_to'] ?? null) === 555 && str_contains($r['body'], 'rotate'));
});

it('skips a head it already scanned', function () use ($leak, $awsHit) {
    $pullRequest = secretScanPullRequest(secretScanRepository());
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);

    runSecretScan($pullRequest->id);
    runSecretScan($pullRequest->id);

    expect(SecretScan::query()->count())->toBe(1);
});

it('skips without a check run when gitleaks is not installed', function () use ($leak) {
    fakeGitHubForScan($leak);
    fakeGitleaks([], installed: false);

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    expect(SecretScan::query()->sole()->status)->toBe(SecretScanStatus::Skipped);
    Http::assertNothingSent();
});

it('does nothing when the repository turned secret scanning off', function () use ($leak) {
    fakeGitHubForScan($leak);
    fakeGitleaks([]);

    runSecretScan(secretScanPullRequest(secretScanRepository(['secret_scanning_enabled' => false]))->id);

    expect(SecretScan::query()->count())->toBe(0);
});

it('records a failure, marks the check neutral and rethrows when gitleaks breaks', function () use ($leak) {
    fakeGitHubForScan($leak);
    Process::fake(function (PendingProcess $process) {
        return in_array('version', (array) $process->command, true)
            ? Process::result('8.28.0')
            : Process::result('', 'bad config', 1);
    });

    expect(fn () => runSecretScan(secretScanPullRequest(secretScanRepository())->id))
        ->toThrow(App\Services\Git\SecretScanning\GitleaksFailed::class);

    expect(SecretScan::query()->sole()->status)->toBe(SecretScanStatus::Failed);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['conclusion'] === 'neutral');
});

it('still completes the scan when writing the note fails', function () use ($leak, $awsHit) {
    // Registered first: Laravel checks fake stubs in the order they were added.
    Http::fake(['api.github.com/repos/octocat/app/git/*' => Http::response(['message' => 'forbidden'], 403)]);
    fakeGitHubForScan($leak);
    fakeGitleaks([$awsHit()]);

    runSecretScan(secretScanPullRequest(secretScanRepository())->id);

    expect(SecretScan::query()->sole()->status)->toBe(SecretScanStatus::Completed)
        ->and(SecretScan::query()->sole()->notes_commit_sha)->toBeNull();
});
```

Http fake precedence: a second `Http::fake([...])` call *adds* stubs, and Laravel checks stubs in the order they were registered. Recorded requests also build up across calls within one test. That is why the "note fails" test registers its 403 stub first, and why the "moved" test counts inline comments instead of asserting that none were sent.

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Feature/GIT/SecretScanning/ScanPullRequestSecretsTest.php`
Expected: FAIL with `Class "App\Jobs\GIT\ScanPullRequestSecrets" not found`.

- [ ] **Step 3: Implement**

`app/Jobs/GIT/ScanPullRequestSecrets.php`:

```php
<?php

namespace App\Jobs\GIT;

use App\Enums\GIT\FindingCategory;
use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\FindingSource;
use App\Enums\GIT\SecretScanStatus;
use App\Models\GIT\GitAccount;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\SecretScan;
use App\Services\Git\GitHubApiClient;
use App\Services\Git\GitHubCallerResolver;
use App\Services\Git\SecretScanning\GitHubNotesWriter;
use App\Services\Git\SecretScanning\GitleaksRunner;
use App\Services\Git\SecretScanning\SecretHit;
use App\Services\Git\SecretScanning\SecretScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scans a pull request head for leaked credentials with gitleaks and reports them.
 *
 * Independent of the AI review: it has its own check run, costs no AI tokens, and
 * runs even when reviews are off. Every output - finding, check annotation, inline
 * comment, git note - carries only gitleaks's redacted match.
 */
class ScanPullRequestSecrets implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHECK_NAME = 'PullLens / Secrets';

    /**
     * File listing, two config reads, one gitleaks run and the GitHub writes.
     * Held under the queue's retry_after so a slow run is never executed twice.
     */
    public int $timeout = 180;

    public int $tries = 2;

    public int $backoff = 30;

    /**
     * Release the lock after ten minutes even if the job dies without finishing.
     */
    public int $uniqueFor = 600;

    /**
     * Inject the pull request and head commit to scan.
     */
    public function __construct(
        public readonly string $pullRequestId,
        public readonly string $headSha,
    ) {}

    /**
     * One scan per pull request head may be queued at a time.
     */
    public function uniqueId(): string
    {
        return $this->pullRequestId.':'.$this->headSha;
    }

    /**
     * Execute the secret scan.
     */
    public function handle(
        GitHubApiClient $api,
        GitHubCallerResolver $callers,
        SecretScanner $scanner,
        GitleaksRunner $runner,
        GitHubNotesWriter $notes,
    ): void {
        $pullRequest = PullRequest::with('repository.account')->find($this->pullRequestId);

        if ($pullRequest === null || ! $pullRequest->repository->secret_scanning_enabled) {
            return;
        }

        $alreadyScanned = SecretScan::query()
            ->where('pull_request_id', $pullRequest->id)
            ->where('head_sha', $this->headSha)
            ->where('status', SecretScanStatus::Completed->value)
            ->exists();

        if ($alreadyScanned) {
            return;
        }

        $repository = $pullRequest->repository;
        [$owner, $name] = explode('/', $repository->full_name, 2);

        $scan = SecretScan::query()->create([
            'pull_request_id' => $pullRequest->id,
            'git_repository_id' => $repository->id,
            'head_sha' => $this->headSha,
            'status' => SecretScanStatus::Running,
        ]);

        if (! $runner->isAvailable()) {
            $scan->update(['status' => SecretScanStatus::Skipped, 'error' => 'gitleaks binary is not installed']);
            Log::warning('secret_scan.binary_missing', ['pull_request_id' => $pullRequest->id]);

            return;
        }

        $caller = $callers->for($repository);

        if ($caller === null) {
            $scan->update(['status' => SecretScanStatus::Skipped, 'error' => 'No GitHub credential for this repository']);

            return;
        }

        $started = hrtime(true);
        $checkRunId = (int) data_get($api->createCheckRun($caller, $owner, $name, $this->headSha, self::CHECK_NAME), 'id') ?: null;
        $scan->update(['check_run_id' => $checkRunId, 'gitleaks_version' => $runner->version()]);

        try {
            $result = $scanner->scan($caller, $owner, $name, $pullRequest->number, (string) $pullRequest->target_branch);
        } catch (Throwable $e) {
            $scan->update([
                'status' => SecretScanStatus::Failed,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'duration_ms' => $this->elapsedMs($started),
            ]);

            if ($checkRunId !== null) {
                $this->completeCheckRun($api, $caller, $owner, $name, $checkRunId, 'neutral',
                    'Secret scan could not run', 'gitleaks failed: '.mb_substr($e->getMessage(), 0, 500), collect());
            }

            throw $e;
        }

        $findings = $this->recordFindings($pullRequest, $scan, $result->hits);
        $resolved = $this->resolveRemoved($pullRequest, $findings->pluck('dedupe_key')->all());

        $scan->update([
            'status' => SecretScanStatus::Completed,
            'findings_count' => $findings->count(),
            'files_scanned' => $result->filesScanned,
            'files_skipped' => $result->filesSkipped,
            'duration_ms' => $this->elapsedMs($started),
        ]);

        if ($checkRunId !== null) {
            $count = $findings->count();

            $this->completeCheckRun(
                $api, $caller, $owner, $name, $checkRunId,
                $count > 0 ? 'failure' : 'success',
                $count > 0 ? "{$count} possible ".str('secret')->plural($count).' found' : 'No secrets found',
                $this->checkSummary($count, $result->filesScanned, $result->filesSkipped),
                $findings,
            );
        }

        $this->postComments($api, $caller, $owner, $name, $pullRequest, $findings);
        $this->replyToResolved($api, $caller, $owner, $name, $pullRequest, $resolved);

        if ($findings->isNotEmpty()) {
            $this->writeNote($notes, $caller, $owner, $name, $pullRequest, $scan, $findings, (string) $runner->version());
        }
    }

    /**
     * Create a finding per new hit and refresh the ones already open.
     *
     * @param  list<SecretHit>  $hits
     * @return Collection<int, PullRequestReviewFinding>
     */
    private function recordFindings(PullRequest $pullRequest, SecretScan $scan, array $hits): Collection
    {
        $open = PullRequestReviewFinding::query()
            ->where('pull_request_id', $pullRequest->id)
            ->where('source', FindingSource::Gitleaks->value)
            ->whereNull('resolved_at')
            ->get()
            ->keyBy('dedupe_key');

        $findings = collect();

        foreach ($hits as $hit) {
            $key = $hit->dedupeKey();

            if ($findings->has($key)) {
                continue;
            }

            $existing = $open->get($key);

            if ($existing !== null) {
                // The same secret, possibly moved: keep its thread, follow its line.
                $existing->update(['line' => $hit->line, 'secret_scan_id' => $scan->id]);
                $findings->put($key, $existing);

                continue;
            }

            $findings->put($key, PullRequestReviewFinding::query()->create([
                'pull_request_review_id' => null,
                'secret_scan_id' => $scan->id,
                'pull_request_id' => $pullRequest->id,
                'git_repository_id' => $pullRequest->git_repository_id,
                'source' => FindingSource::Gitleaks->value,
                'dedupe_key' => $key,
                'title' => mb_substr('Secret detected: '.($hit->description !== '' ? $hit->description : $hit->ruleId), 0, 255),
                'severity' => FindingSeverity::Critical->value,
                'category' => FindingCategory::Security->value,
                'file' => $hit->file,
                'line' => $hit->line,
                'confidence' => 1.0,
                'explanation' => "gitleaks rule `{$hit->ruleId}` matched `{$hit->match}` on an added line. "
                    .'The value is now part of this branch\'s git history, so deleting the line does not un-leak it.',
                'suggested_fix' => 'Rotate or revoke this credential first, then remove it from the code and load it from '
                    .'the environment or a secret store. If it is a test fixture, allowlist it in .gitleaks.toml on the target branch.',
            ]));
        }

        return $findings->values();
    }

    /**
     * Resolve open secret findings the new scan no longer sees.
     *
     * @param  array<int, string>  $currentKeys
     * @return Collection<int, PullRequestReviewFinding>
     */
    private function resolveRemoved(PullRequest $pullRequest, array $currentKeys): Collection
    {
        $gone = PullRequestReviewFinding::query()
            ->where('pull_request_id', $pullRequest->id)
            ->where('source', FindingSource::Gitleaks->value)
            ->whereNull('resolved_at')
            ->whereNotIn('dedupe_key', $currentKeys)
            ->get();

        foreach ($gone as $finding) {
            $finding->update([
                'resolved_at' => now(),
                'resolution_type' => FindingResolutionType::SecretRemoved,
            ]);
        }

        return $gone;
    }

    /**
     * Complete the secrets check run with one annotation per finding.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     */
    private function completeCheckRun(
        GitHubApiClient $api, GitAccount|string $caller, string $owner, string $name,
        int $checkRunId, string $conclusion, string $title, string $summary, Collection $findings,
    ): void {
        $annotations = $findings->map(fn (PullRequestReviewFinding $f) => [
            'path' => $f->file,
            'start_line' => (int) $f->line,
            'end_line' => (int) $f->line,
            'annotation_level' => 'failure',
            'title' => $f->title,
            'message' => $f->explanation,
        ])->values()->all();

        try {
            $api->updateCheckRun($caller, $owner, $name, $checkRunId, $conclusion, $title, $summary, $annotations);
        } catch (Throwable $e) {
            Log::warning('secret_scan.check_run_failed', ['check_run_id' => $checkRunId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Markdown summary shown on the check run.
     */
    private function checkSummary(int $count, int $scanned, int $skipped): string
    {
        $lines = [$count > 0
            ? "gitleaks found {$count} possible ".str('secret')->plural($count).' in the lines this pull request adds. '
                .'**Rotate them** - removing the line does not remove it from git history.'
            : 'gitleaks found no secrets in the lines this pull request adds.'];

        $lines[] = "Files scanned: {$scanned}".($skipped > 0 ? " - skipped (binary, removed or too large): {$skipped}" : '');

        return implode("\n\n", $lines);
    }

    /**
     * Post a COMMENT review and an inline comment for each finding not yet posted.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     */
    private function postComments(GitHubApiClient $api, GitAccount|string $caller, string $owner, string $name, PullRequest $pullRequest, Collection $findings): void
    {
        $unposted = $findings->reject(fn (PullRequestReviewFinding $f) => $f->is_posted);

        if ($unposted->isEmpty()) {
            return;
        }

        $count = $unposted->count();

        try {
            $api->postPullRequestReview($caller, $owner, $name, $pullRequest->number,
                "**PullLens secret scan:** {$count} possible ".str('secret')->plural($count).' added in this pull request. '
                .'Rotate each credential - removing it from the code is not enough once it has been pushed.',
                'COMMENT');
        } catch (Throwable $e) {
            Log::warning('secret_scan.review_post_failed', ['pull_request_id' => $pullRequest->id, 'error' => $e->getMessage()]);
        }

        foreach ($unposted as $finding) {
            try {
                $posted = $api->postReviewComment($caller, $owner, $name, $pullRequest->number, $this->headSha,
                    $finding->file, (int) $finding->line,
                    "**{$finding->title}**\n\n{$finding->explanation}\n\n{$finding->suggested_fix}");

                $commentId = (int) data_get($posted, 'id');

                if ($commentId > 0) {
                    $finding->update(['is_posted' => true, 'provider_comment_id' => $commentId]);
                }
            } catch (Throwable $e) {
                Log::warning('secret_scan.comment_post_failed', [
                    'pull_request_id' => $pullRequest->id, 'file' => $finding->file, 'line' => $finding->line, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Tell each resolved finding's thread the secret is gone from the diff but not from history.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $resolved
     */
    private function replyToResolved(GitHubApiClient $api, GitAccount|string $caller, string $owner, string $name, PullRequest $pullRequest, Collection $resolved): void
    {
        foreach ($resolved as $finding) {
            if (! $finding->provider_comment_id) {
                continue;
            }

            try {
                $api->replyToReviewComment($caller, $owner, $name, $pullRequest->number, (int) $finding->provider_comment_id,
                    'No longer in the diff, but still in this branch\'s git history - rotate this credential if you have not already.');
            } catch (Throwable $e) {
                Log::warning('secret_scan.resolve_reply_failed', ['finding_id' => $finding->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Attach the redacted findings to the head commit as a git note.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     */
    private function writeNote(GitHubNotesWriter $notes, GitAccount|string $caller, string $owner, string $name, PullRequest $pullRequest, SecretScan $scan, Collection $findings, string $version): void
    {
        $lines = [
            "PullLens secret scan {$scan->id}",
            "gitleaks {$version} - ".now()->toIso8601String(),
            "{$findings->count()} possible ".str('secret')->plural($findings->count())." in pull request #{$pullRequest->number}:",
        ];

        foreach ($findings as $finding) {
            $rule = str($finding->dedupe_key)->after('gitleaks:')->before(':');
            $lines[] = "- {$finding->file}:{$finding->line} {$rule}";
        }

        try {
            $scan->update(['notes_commit_sha' => $notes->write($caller, $owner, $name, $this->headSha, implode("\n", $lines)."\n")]);
        } catch (Throwable $e) {
            Log::warning('secret_scan.note_failed', ['secret_scan_id' => $scan->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Milliseconds since $started (an hrtime value).
     */
    private function elapsedMs(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
```

- [ ] **Step 4: Run the job tests, then the queue config test**

Run: `php artisan test tests/Feature/GIT/SecretScanning/ScanPullRequestSecretsTest.php tests/Feature/Queue/QueueConfigurationTest.php`
Expected: PASS. If one output assertion fails, fix the job, not the test. Each test pins a spec requirement.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/GIT/ScanPullRequestSecrets.php tests/Feature/GIT/SecretScanning/ScanPullRequestSecretsTest.php
git commit -m "feat(secrets): report gitleaks hits as findings, comments, a check and git notes"
```

---

### Task 9: Dispatch the scan from the pull request webhook

**Files:**
- Modify: `app/Enums/GIT/PullRequestWebhookAction.php` and `app/Services/Git/Webhooks/Handlers/PullRequestEventHandler.php:56-78`
- Test: `tests/Feature/GIT/SecretScanning/SecretScanDispatchTest.php`

**Interfaces:**
- Produces: `PullRequestWebhookAction::introducesCode(): bool`, true for Opened, Synchronize and Reopened.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\GIT\ScanPullRequestSecrets;
use App\Services\Git\Webhooks\Handlers\PullRequestEventHandler;
use Illuminate\Support\Facades\Queue;

function pullRequestPayload(string $action, string $sha = 'abc123'): array
{
    return [
        'action' => $action,
        'pull_request' => [
            'id' => 9001, 'number' => 7, 'title' => 'Add config', 'body' => '', 'state' => 'open', 'draft' => false,
            'user' => ['login' => 'octocat', 'type' => 'User'],
            'head' => ['ref' => 'feature/config', 'sha' => $sha],
            'base' => ['ref' => 'main'],
            'html_url' => 'https://github.com/octocat/app/pull/7',
            'additions' => 1, 'deletions' => 0, 'changed_files' => 1, 'commits' => 1, 'labels' => [],
            'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            'closed_at' => null, 'merged_at' => null,
        ],
    ];
}

it('queues a secret scan when a pull request brings new code', function (string $action) {
    Queue::fake();

    app(PullRequestEventHandler::class)->handle(secretScanRepository(), pullRequestPayload($action));

    Queue::assertPushed(ScanPullRequestSecrets::class, fn ($job) => $job->headSha === 'abc123');
})->with(['opened', 'synchronize', 'reopened']);

it('scans even when AI reviews are off', function () {
    Queue::fake();

    app(PullRequestEventHandler::class)->handle(secretScanRepository(['reviews_enabled' => false]), pullRequestPayload('opened'));

    Queue::assertPushed(ScanPullRequestSecrets::class);
});

it('does not scan when the repository turned it off', function () {
    Queue::fake();

    app(PullRequestEventHandler::class)->handle(secretScanRepository(['secret_scanning_enabled' => false]), pullRequestPayload('opened'));

    Queue::assertNotPushed(ScanPullRequestSecrets::class);
});

it('does not scan for actions that bring no new code', function (string $action) {
    Queue::fake();

    app(PullRequestEventHandler::class)->handle(secretScanRepository(), pullRequestPayload($action));

    Queue::assertNotPushed(ScanPullRequestSecrets::class);
})->with(['closed', 'edited', 'labeled']);
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `php artisan test tests/Feature/GIT/SecretScanning/SecretScanDispatchTest.php`
Expected: FAIL. The job is never pushed.

- [ ] **Step 3: Implement**

In `PullRequestWebhookAction`, add after `changesHeadCommit()`:

```php
    /**
     * Whether this action puts new code in front of us: a new pull request, a push
     * to it, or one reopened with whatever it now carries.
     */
    public function introducesCode(): bool
    {
        return in_array($this, [self::Opened, self::Synchronize, self::Reopened], true);
    }
```

In `PullRequestEventHandler::handle()`, before the `if ($this->reviewPolicy->shouldReview(...))` block:

```php
        // Secret scanning runs on its own, without AI and whether or not reviews are on.
        if ($action->introducesCode() && $repository->secret_scanning_enabled) {
            $headSha = (string) data_get($prPayload, 'head.sha', '');

            if ($headSha !== '') {
                ScanPullRequestSecrets::dispatch($pullRequest->id, $headSha);
            }
        }
```

Import `App\Jobs\GIT\ScanPullRequestSecrets`, and update the class docblock to "Keeps the local pull request record in step with GitHub and queues AI reviews and secret scans."

- [ ] **Step 4: Run the tests**

Run: `php artisan test tests/Feature/GIT/SecretScanning/SecretScanDispatchTest.php`
Expected: PASS (8 cases).

- [ ] **Step 5: Commit**

```bash
git add app/Enums/GIT/PullRequestWebhookAction.php app/Services/Git/Webhooks/Handlers/PullRequestEventHandler.php tests/Feature/GIT/SecretScanning/SecretScanDispatchTest.php
git commit -m "feat(secrets): scan pull requests when they bring new code"
```

---

### Task 10: Repository setting toggle

**Files:**
- Modify: `app/Http/Requests/Settings/GIT/UpdateGitRepositorySettingsRequest.php:32`, `app/Http/Controllers/Settings/GIT/GitRepositorySettingsController.php:49` and `resources/js/pages/settings/git-repository-settings.tsx` (type ~l.36, field union ~l.87, form init ~l.129, new Card before "Reviews" ~l.280)
- Test: `tests/Feature/Settings/GitRepositorySettingsTest.php` (append)

- [ ] **Step 1: Write the failing tests** (append to `GitRepositorySettingsTest.php`; `trackedRepository()` and `User` already exist there)

```php
test('the settings page shows whether secret scanning is on', function () {
    $user = User::factory()->admin()->create();
    $repository = trackedRepository();

    $this->actingAs($user)
        ->get(route('integrations.repositories.settings.edit', $repository->id))
        ->assertInertia(fn (Assert $page) => $page->where('repository.secret_scanning_enabled', true));
});

test('secret scanning can be turned off for a repository', function () {
    $user = User::factory()->admin()->create();
    $repository = trackedRepository();

    $this->actingAs($user)
        ->put(route('integrations.repositories.settings.update', $repository->id), [
            'reviews_enabled' => true,
            'record_all_activity' => false,
            'secret_scanning_enabled' => false,
            'auto_review_on_open' => true,
            'auto_approve' => false,
            'auto_apply_labels' => false,
            'auto_fill_pr_description' => false,
            'auto_enhance_pr_title' => false,
            'allow_comment_replies' => true,
            'auto_merge' => false,
            'auto_merge_method' => 'merge',
            'review_language' => 'en',
            'review_tone' => 'balanced',
            'use_emoji' => true,
            'base_branches' => [],
            'tracked_branches' => [],
            'review_intensity' => 'balanced',
        ])
        ->assertRedirect();

    expect($repository->fresh()->secret_scanning_enabled)->toBeFalse();
});

test('secret scanning must be a boolean', function () {
    $user = User::factory()->admin()->create();
    $repository = trackedRepository();

    $this->actingAs($user)
        ->put(route('integrations.repositories.settings.update', $repository->id), ['secret_scanning_enabled' => 'maybe'])
        ->assertSessionHasErrors('secret_scanning_enabled');
});
```

Copy the full valid payload from the existing "authenticated users can update repository settings" test in the same file (line ~80), so that every required field matches the real rules, and add `'secret_scanning_enabled' => false`. If the payload above differs from it, the existing test wins.

- [ ] **Step 2: Run to confirm the failure**

Run: `php artisan test tests/Feature/Settings/GitRepositorySettingsTest.php`
Expected: the three new tests FAIL (the prop is missing, the value is not saved, there is no validation error).

- [ ] **Step 3: Implement the backend**

- In `UpdateGitRepositorySettingsRequest::rules()`, after `record_all_activity`, add `'secret_scanning_enabled' => ['sometimes', 'boolean'],`. It is `sometimes` so older clients that don't send it keep the current value.
- In `GitRepositorySettingsController::edit()`, add `'secret_scanning_enabled' => $gitRepository->secret_scanning_enabled,` after `'record_all_activity'`.

- [ ] **Step 4: Implement the UI** in `git-repository-settings.tsx`

- In the repository props type, add `secret_scanning_enabled: boolean;` after `record_all_activity: boolean;`.
- In the toggle field union, add `| 'secret_scanning_enabled'` after `| 'record_all_activity'`.
- In `useForm` initial data, add `secret_scanning_enabled: repository.secret_scanning_enabled,` after `record_all_activity`.
- Add this card between the "Activity Tracking" card and the "Reviews" card:

```tsx
                    <Card>
                        <CardHeader>
                            <CardTitle>Security</CardTitle>
                            <CardDescription>
                                Catch leaked credentials before they are merged.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-1">
                            <ToggleRow
                                field="secret_scanning_enabled"
                                label="Secret scanning"
                                description="Scan every pull request diff for leaked credentials with gitleaks. Runs without AI and without AI cost, even when reviews are off."
                                checked={data.secret_scanning_enabled}
                                onChange={(checked) =>
                                    setData('secret_scanning_enabled', checked)
                                }
                            />
                        </CardContent>
                    </Card>
```

- [ ] **Step 5: Run the tests and the type check**

Run: `php artisan test tests/Feature/Settings/GitRepositorySettingsTest.php && npm run types && npm run lint`
Expected: PASS. If the scripts are named differently, check `package.json` for its type-check and lint scripts.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/Settings/GIT/UpdateGitRepositorySettingsRequest.php app/Http/Controllers/Settings/GIT/GitRepositorySettingsController.php resources/js/pages/settings/git-repository-settings.tsx tests/Feature/Settings/GitRepositorySettingsTest.php
git commit -m "feat(settings): let each repository turn secret scanning on or off"
```

---

### Task 11: Findings page source filter and badge

**Files:**
- Modify: `app/Http/Requests/Findings/IndexFindingsRequest.php` (rules ~l.44, accessor after `category()` ~l.89, `filterState()` ~l.143), `app/Support/Queries/GIT/FindingQuery.php` (after `inCategory`), `app/Http/Controllers/Findings/FindingsController.php:51`, `app/Support/Presenters/GIT/FindingPresenter.php` (`toArray` and `summary`) and `resources/js/pages/findings/index.tsx`
- Test: `tests/Feature/GIT/SecretScanning/FindingsSourceFilterTest.php`

**Interfaces:**
- Produces:
  - `IndexFindingsRequest::source(): ?string`.
  - `FindingQuery::fromSource(?string $source): self`.
  - A `source` key in `FindingPresenter::toArray()` and `summary()`.
  - A `source` key in the findings page `filters`.
  - A `sources` prop, `array<{value: string, label: string}>`.

- [ ] **Step 1: Write the failing test**

```php
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
            ->has('findings.data', 1)
            ->where('findings.data.0.source', 'gitleaks')
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
        ->assertInertia(fn (Assert $page) => $page->has('findings.data', 2)->where('filters.source', '')->etc());
});

it('rejects an unknown source', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('findings.index', ['source' => 'nope']))
        ->assertSessionHasErrors('source');
});
```

`aiFinding()` fills the `pull_request_reviews` columns that are not nullable (see `2026_06_12_120004`). If `PullRequestReview`'s `#[Fillable]` is missing one of them, add that field to the test data, not to the model. Check how `FindingPresenter::collection()` paginates. If the prop is `findings` without `.data`, change `findings.data` to `findings` in both tests. Look at the controller's `Inertia::render` call first.

- [ ] **Step 2: Run to confirm the failure**

Run: `php artisan test tests/Feature/GIT/SecretScanning/FindingsSourceFilterTest.php`
Expected: FAIL, because the `source` filter is ignored.

- [ ] **Step 3: Implement the backend**

- In `IndexFindingsRequest::rules()`, add `'source' => ['nullable', 'string', Rule::in(FindingSource::values())],`.
- Add the accessor:

```php
    /**
     * The finding source to narrow to (AI review or secret scan), if any.
     */
    public function source(): ?string
    {
        return $this->filledString('source');
    }
```

- In `filterState()`, add `'source' => $this->source() ?? '',` and import `App\Enums\GIT\FindingSource`.

In `FindingQuery`, after `inCategory()`:

```php
    /**
     * Restrict to findings from one source: the AI review or the secret scan.
     */
    public function fromSource(?string $source): self
    {
        if (filled($source)) {
            $this->query->where('source', $source);
        }

        return $this;
    }
```

In `FindingsController`:
- Chain `->fromSource($request->source())` after `->inCategory($request->category())`.
- Add this prop to `Inertia::render`: `'sources' => array_map(fn (FindingSource $s) => ['value' => $s->value, 'label' => $s->label()], FindingSource::cases()),`.
- Import `FindingSource`.

In `FindingPresenter::toArray()` and `summary()`, add `'source' => $finding->source?->value,` after `'category'`.

- [ ] **Step 4: Implement the UI** in `resources/js/pages/findings/index.tsx`

- In `type Finding`, add `source: 'ai' | 'gitleaks';`.
- In the props type, add `sources: { value: string; label: string }[];`, and add `source: string;` inside `filters`.
- Destructure `sources` from the props.
- Next to the category chip in the row (~l.270), add:

```tsx
                    {finding.source === 'gitleaks' && (
                        <span className="rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive">
                            Secret
                        </span>
                    )}
```

- After the Category `<select>` (~l.887), add:

```tsx
                        {/* Source */}
                        <select
                            value={filters.source}
                            onChange={(e) =>
                                push({ source: e.target.value, page: 1 })
                            }
                            className="h-8 rounded-md border border-border bg-background px-2 text-sm focus:ring-1 focus:ring-ring focus:outline-none"
                        >
                            <option value="">All sources</option>
                            {sources.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </select>
```

- In the "Clear filters" condition, add `filters.source ||`, and add `source: '',` to its `push({...})` call.

- [ ] **Step 5: Run the tests, types and lint**

Run: `php artisan test tests/Feature/GIT/SecretScanning/FindingsSourceFilterTest.php tests/Feature/Performance/QueryCountTest.php && npm run types && npm run lint`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/Findings/IndexFindingsRequest.php app/Support/Queries/GIT/FindingQuery.php app/Http/Controllers/Findings/FindingsController.php app/Support/Presenters/GIT/FindingPresenter.php resources/js/pages/findings/index.tsx tests/Feature/GIT/SecretScanning/FindingsSourceFilterTest.php
git commit -m "feat(findings): filter and label findings by source"
```

---

### Task 12: Install the binary (Docker, install.sh, docs) and the real-binary test

**Files:**
- Modify: `docker/services/laravel/Dockerfile` (base stage, after the apt `RUN`, ~l.38), `install.sh`, `INSTALL.md` and `DOCKER.md`
- Test: `tests/Feature/GIT/SecretScanning/GitleaksBinaryTest.php`

- [ ] **Step 1: Get the release checksums**

Run:

```bash
curl -fsSL https://github.com/gitleaks/gitleaks/releases/download/v8.28.0/gitleaks_8.28.0_checksums.txt \
  | grep -E 'linux_(x64|arm64)|darwin_(x64|arm64)'
```

Expected: four `<sha256>  gitleaks_8.28.0_<os>_<arch>.tar.gz` lines. Use these exact values below. If v8.28.0 does not exist, use the newest v8 release that has the `dir` subcommand (8.19.0 or later) and change the version everywhere in this task.

- [ ] **Step 2: Add the binary to the Dockerfile base stage** (right after the apt-get `RUN` block)

```dockerfile
# gitleaks: the free, MIT-licensed secret scanner behind "PullLens / Secrets".
# Pinned and checksum-verified so a compromised release asset cannot slip in.
ARG GITLEAKS_VERSION=8.28.0
ARG GITLEAKS_SHA256_X64=<linux_x64 sha256 from step 1>
ARG GITLEAKS_SHA256_ARM64=<linux_arm64 sha256 from step 1>
ARG TARGETARCH
RUN set -eux; \
    case "${TARGETARCH:-amd64}" in \
        amd64) arch=x64; sum="${GITLEAKS_SHA256_X64}" ;; \
        arm64) arch=arm64; sum="${GITLEAKS_SHA256_ARM64}" ;; \
        *) echo "unsupported arch ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /tmp/gitleaks.tar.gz \
        "https://github.com/gitleaks/gitleaks/releases/download/v${GITLEAKS_VERSION}/gitleaks_${GITLEAKS_VERSION}_linux_${arch}.tar.gz"; \
    echo "${sum}  /tmp/gitleaks.tar.gz" | sha256sum -c -; \
    tar -xzf /tmp/gitleaks.tar.gz -C /usr/local/bin gitleaks; \
    rm /tmp/gitleaks.tar.gz; \
    gitleaks version
```

The two `<... from step 1>` markers are values the engineer pastes from step 1. They are not placeholders to leave in: the build fails if they are left unchanged.

- [ ] **Step 3: Add the binary to `install.sh`**

Find the function in `install.sh` that checks prerequisites (search for where it verifies `php` or `composer`). Add a `install_gitleaks` function next to it and call it from the same place:

```bash
GITLEAKS_VERSION="8.28.0"

# gitleaks powers the "PullLens / Secrets" check. Installed only when it is missing,
# into ~/.local/bin, checksum-verified against the pinned release.
install_gitleaks() {
    if command -v gitleaks >/dev/null 2>&1; then
        echo "gitleaks already installed: $(gitleaks version)"
        return
    fi

    local os arch sum url tmp
    case "$(uname -s)" in
        Linux) os=linux ;;
        Darwin) os=darwin ;;
        *) echo "Skipping gitleaks: unsupported OS $(uname -s). Secret scanning will be skipped." ; return ;;
    esac
    case "$(uname -m)" in
        x86_64|amd64) arch=x64 ;;
        arm64|aarch64) arch=arm64 ;;
        *) echo "Skipping gitleaks: unsupported CPU $(uname -m). Secret scanning will be skipped." ; return ;;
    esac
    case "${os}_${arch}" in
        linux_x64) sum="<linux_x64 sha256>" ;;
        linux_arm64) sum="<linux_arm64 sha256>" ;;
        darwin_x64) sum="<darwin_x64 sha256>" ;;
        darwin_arm64) sum="<darwin_arm64 sha256>" ;;
    esac

    url="https://github.com/gitleaks/gitleaks/releases/download/v${GITLEAKS_VERSION}/gitleaks_${GITLEAKS_VERSION}_${os}_${arch}.tar.gz"
    tmp="$(mktemp -d)"
    curl -fsSL -o "${tmp}/gitleaks.tar.gz" "${url}"

    if command -v sha256sum >/dev/null 2>&1; then
        echo "${sum}  ${tmp}/gitleaks.tar.gz" | sha256sum -c -
    else
        echo "${sum}  ${tmp}/gitleaks.tar.gz" | shasum -a 256 -c -
    fi

    mkdir -p "${HOME}/.local/bin"
    tar -xzf "${tmp}/gitleaks.tar.gz" -C "${HOME}/.local/bin" gitleaks
    rm -rf "${tmp}"
    echo "Installed gitleaks ${GITLEAKS_VERSION} to ~/.local/bin. Make sure it is on PATH, or set GITLEAKS_BINARY in .env."
}
```

Paste the four sums from step 1 in place of the `<... sha256>` markers.

- [ ] **Step 4: Document it**

In `INSTALL.md` and `DOCKER.md`, add a "Secret scanning" section:

```markdown
## Secret scanning

PullLens scans every pull request diff for leaked credentials with
[gitleaks](https://github.com/gitleaks/gitleaks) (free, MIT). The Docker image and
`install.sh` install a pinned, checksum-verified binary. To use another build, set
`GITLEAKS_BINARY` in `.env`. Without the binary, scans are skipped and logged.

Each repository can turn it off under *Settings → Repository → Security*.

Findings appear in four places: an inline comment on the PR, the Findings page
(filter *Source: Secrets*), the **PullLens / Secrets** check (fails when anything is
found), and a git note on the scanned commit. GitHub's web UI does not show notes;
read them with:

    git fetch origin refs/notes/gitleaks:refs/notes/gitleaks
    git log --notes=gitleaks

To allowlist test fixtures, commit a `.gitleaks.toml` or `.gitleaksignore` to the
**target** branch. Files added by the pull request itself are ignored for this, so
a pull request cannot allowlist its own secret.

Limitation: only the pull request's final diff is scanned. A secret added and then
removed inside the same pull request stays in its commit history undetected.
```

- [ ] **Step 5: Add the real-binary integration test**

`tests/Feature/GIT/SecretScanning/GitleaksBinaryTest.php`:

```php
<?php

use App\Services\Git\SecretScanning\GitleaksRunner;
use Illuminate\Support\Facades\File;

it('finds and redacts a real AWS key with the installed gitleaks', function () {
    $runner = app(GitleaksRunner::class);

    if (! $runner->isAvailable()) {
        $this->markTestSkipped('gitleaks is not installed.');
    }

    $dir = storage_path('app/secret-scans/binary-test-'.uniqid());
    File::ensureDirectoryExists($dir.'/config');
    // A well-known documentation key split so this file itself does not trip scanners.
    file_put_contents($dir.'/config/aws.php', "\n\n\$key = '".'AKIA'.'IOSFODNN7'.'REALKEY'."';\n");

    try {
        $hits = $runner->run($dir);
    } finally {
        File::deleteDirectory($dir);
    }

    expect($hits)->not->toBeEmpty()
        ->and($hits[0]->file)->toBe('config/aws.php')
        ->and($hits[0]->line)->toBe(3)
        ->and($hits[0]->match)->not->toContain('IOSFODNN7');
});
```

If gitleaks' default rules allowlist `AKIAIOSFODNN7EXAMPLE`-style keys, the fixture already avoids the `EXAMPLE` suffix. If it still reports nothing, switch the fixture to a GitHub token shape: `'ghp_'.str_repeat('a1B2', 9)`.

- [ ] **Step 6: Verify**

Run:
- `docker build -f docker/services/laravel/Dockerfile --target base -t pulllens-gitleaks-check .` Expected: the build succeeds and the last line prints `8.28.0`. If the Dockerfile's stage names differ, use the stage that contains the new `RUN`.
- `bash -n install.sh`. Expected: no output.
- `php artisan test tests/Feature/GIT/SecretScanning/GitleaksBinaryTest.php`. Expected: PASS where gitleaks is installed, SKIPPED otherwise.

- [ ] **Step 7: Commit**

```bash
git add docker/services/laravel/Dockerfile install.sh INSTALL.md DOCKER.md tests/Feature/GIT/SecretScanning/GitleaksBinaryTest.php
git commit -m "build(secrets): install a pinned, verified gitleaks binary"
```

---

### Task 13: Full verification

- [ ] **Step 1: Run the whole suite, types and lint**

Run: `php artisan test && npm run types && npm run lint && npm run build`
Expected: everything green. Fix any regression in the task that owns the file, not here.

- [ ] **Step 2: Manual smoke test (optional, needs a GitHub App test repo)**
  1. Open a PR that adds `config/leak.php` containing a GitHub-token-shaped string.
  2. Expect the following:
     - `PullLens / Secrets` fails.
     - An inline comment shows `REDACTED`.
     - The Findings page, filtered to *Source: Secrets*, shows one row.
     - `git fetch origin refs/notes/gitleaks:refs/notes/gitleaks && git log --notes=gitleaks -1 <head>` prints the note.
  3. Push a commit that replaces the value with `env(...)`. Expect the check to pass, and the thread to get the "rotate" reply.
