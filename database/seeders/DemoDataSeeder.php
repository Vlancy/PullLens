<?php

namespace Database\Seeders;

use App\Enums\AI\AiOperation;
use App\Enums\GIT\FindingCategory;
use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\PullRequestState;
use App\Enums\GIT\ReviewVerdict;
use App\Enums\GIT\TaskRelation;
use App\Enums\GIT\TaskStatus;
use App\Enums\GIT\TaskType;
use App\Models\AI\AiProvider;
use App\Models\AI\AiUsageRecord;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitProviderApp;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestCommit;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\PullRequestTask;
use App\Models\GIT\PullRequestTaskLink;
use App\Models\GIT\RepositoryCommit;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Populates an installation with realistic, entirely fictional data.
 *
 * Intended for local evaluation, screenshots and UI work — never for a real
 * installation. Everything it writes is invented: the repositories, the people and
 * the findings do not correspond to anything real.
 *
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    /** Fictional contributors. */
    private const DEVELOPERS = [
        ['login' => 'maya-r', 'name' => 'Maya Rodriguez'],
        ['login' => 'jonas-k', 'name' => 'Jonas Keller'],
        ['login' => 'priya-n', 'name' => 'Priya Nair'],
        ['login' => 'tomasz-w', 'name' => 'Tomasz Wójcik'],
        ['login' => 'amara-o', 'name' => 'Amara Okafor'],
    ];

    private const REPOSITORIES = [
        ['name' => 'checkout-api', 'owner' => 'northwind'],
        ['name' => 'billing-service', 'owner' => 'northwind'],
        ['name' => 'web-dashboard', 'owner' => 'northwind'],
    ];

    /** Realistic finding templates: [title, severity, category, file, explanation]. */
    private const FINDINGS = [
        ['SQL injection via unescaped order filter', 'critical', 'security', 'app/Http/Controllers/OrderController.php', 'The `sort` query parameter is interpolated directly into an ORDER BY clause. A crafted value can append arbitrary SQL.'],
        ['Missing authorization check on refund endpoint', 'critical', 'security', 'app/Http/Controllers/RefundController.php', 'The route is behind `auth` but never verifies the refund belongs to the authenticated customer.'],
        ['Webhook signature verification is optional', 'high', 'security', 'app/Http/Controllers/WebhookController.php', 'Signature checking is skipped when no secret is configured, so an unauthenticated caller can post forged events.'],
        ['N+1 query loading invoice line items', 'high', 'performance', 'app/Services/InvoiceRenderer.php', 'Each invoice triggers a separate query for its lines. A 200-invoice export issues 201 queries.'],
        ['Unbounded result set in export', 'high', 'reliability', 'app/Jobs/ExportTransactions.php', 'The query has no limit; a large tenant will exhaust memory before the job completes.'],
        ['Race condition on concurrent stock decrement', 'high', 'correctness', 'app/Services/StockManager.php', 'Read-then-write without a lock lets two orders decrement the same unit.'],
        ['Money handled as float', 'medium', 'correctness', 'app/Support/Money.php', 'Totals accumulate binary rounding error. Use integer minor units.'],
        ['Retry has no backoff', 'medium', 'reliability', 'app/Services/PaymentGateway.php', 'Immediate retries amplify load during a provider outage.'],
        ['Timezone assumed to be UTC', 'medium', 'correctness', 'app/Reports/DailySummary.php', 'Day boundaries use the server timezone, so the report shifts for non-UTC tenants.'],
        ['Duplicated validation rules', 'low', 'maintainability', 'app/Http/Requests/StoreOrderRequest.php', 'The same rule set is repeated in three request classes.'],
        ['Missing test for the discount edge case', 'low', 'testing', 'tests/Feature/DiscountTest.php', 'The 100%-discount path is untested and previously regressed.'],
        ['Public method lacks a return type', 'low', 'maintainability', 'app/Services/CartService.php', 'Adding the return type would let static analysis catch callers.'],
    ];

    /** Task templates: [title, type, hours, description]. */
    private const TASKS = [
        ['Added multi-currency support to checkout', 'feature', 12.0, 'Introduced currency-aware pricing across the cart, checkout and receipt flows.'],
        ['Fixed refund rounding on partial returns', 'bugfix', 3.5, 'Partial refunds lost a cent per line due to premature rounding.'],
        ['Split the monolithic OrderService', 'refactor', 8.0, 'Extracted pricing, stock and fulfilment into separate services.'],
        ['Cached the product catalogue lookup', 'performance', 5.0, 'Catalogue reads now hit Redis, cutting median checkout latency.'],
        ['Hardened the payment webhook endpoint', 'security', 4.0, 'Added HMAC verification and replay protection.'],
        ['Covered the discount engine with tests', 'test', 6.0, 'Added cases for stacked, expired and 100% discounts.'],
        ['Documented the deployment runbook', 'documentation', 2.0, 'Wrote the step-by-step release and rollback procedure.'],
        ['Upgraded the framework to the current LTS', 'chore', 7.0, 'Bumped dependencies and resolved deprecations.'],
        ['Added idempotency keys to the payments API', 'feature', 9.0, 'Duplicate submissions now resolve to the original charge.'],
        ['Fixed the stock race on concurrent orders', 'bugfix', 4.5, 'Wrapped the decrement in a row-level lock.'],
        ['Rebuilt the invoice export as a queued job', 'performance', 6.5, 'Large exports no longer time out the request.'],
        ['Added rate limiting to the public API', 'security', 3.0, 'Per-token limits with a clear retry-after header.'],
    ];

    /**
     * Run the seeder.
     */
    public function run(): void
    {
        $this->configureIntegrations();

        $account = GitAccount::factory()->create([
            'nickname' => 'northwind-bot',
            'name' => 'Northwind Automation',
        ]);

        foreach (self::REPOSITORIES as $index => $definition) {
            $repository = $this->createRepository($account, $definition, $index);

            $this->seedRepository($repository, $index);
        }

        $this->command?->info('Demo data seeded. Everything in it is fictional.');
    }

    /**
     * Provide a configured AI provider and git app so the dashboard shows an
     * operational system rather than a setup checklist. The credentials are
     * placeholders and cannot authenticate anywhere.
     */
    private function configureIntegrations(): void
    {
        AiProvider::query()->create([
            'provider_driver' => 'anthropic',
            'name' => 'Anthropic (demo)',
            'credentials' => ['api_key' => 'demo-not-a-real-key'],
            'default_model' => 'claude-sonnet-5',
            'is_default' => true,
            'is_enabled' => true,
        ]);

        GitProviderApp::query()->create([
            'provider' => 'github',
            'name' => 'PullLens (demo)',
            'app_id' => '000000',
            'client_id' => 'demo-client-id',
            'client_secret' => 'demo-client-secret',
            'webhook_secret' => 'demo-webhook-secret',
            'private_key' => 'demo-private-key',
            'slug' => 'pulllens-demo',
            'configured_at' => now(),
        ]);
    }

    /**
     * Create one fictional tracked repository.
     */
    private function createRepository(GitAccount $account, array $definition, int $index): GitRepository
    {
        return GitRepository::factory()->create([
            'git_account_id' => $account->id,
            'owner_login' => $definition['owner'],
            'name' => $definition['name'],
            'full_name' => "{$definition['owner']}/{$definition['name']}",
            'provider_repo_id' => 900_000 + $index,
            'web_url' => "https://github.com/{$definition['owner']}/{$definition['name']}",
            'reviews_enabled' => true,
        ]);
    }

    /**
     * Build a plausible history: pull requests over the last 70 days, each with a
     * review, findings, tasks and commits.
     */
    private function seedRepository(GitRepository $repository, int $repoIndex): void
    {
        $prCount = [14, 10, 8][$repoIndex] ?? 8;
        $deliveredTasks = [];

        for ($i = 0; $i < $prCount; $i++) {
            $developer = self::DEVELOPERS[($i + $repoIndex) % count(self::DEVELOPERS)];
            $openedAt = now()->subDays(70 - ($i * 4))->addHours(9 + ($i % 6));

            // Most work merges; a couple stay open so the board is not uniformly green.
            $isOpen = $i >= $prCount - 2;
            $mergedAt = $isOpen ? null : $openedAt->copy()->addHours(6 + ($i % 40));

            $pullRequest = $this->createPullRequest($repository, $developer, $i, $repoIndex, $openedAt, $mergedAt, $isOpen);
            $review = $this->createReview($pullRequest, $i);

            $this->createFindings($repository, $pullRequest, $review, $i);
            $this->createCommits($repository, $pullRequest, $developer, $openedAt, $i);

            $task = $this->createTask($repository, $pullRequest, $review, $developer, $i, $repoIndex, $mergedAt);

            // Link a later bug fix back to the feature that introduced it, so the
            // "came back" signal on the tasks board has something real to show.
            if ($task->type === TaskType::BugFix && $deliveredTasks !== []) {
                $target = $deliveredTasks[array_key_first($deliveredTasks)];
                unset($deliveredTasks[array_key_first($deliveredTasks)]);

                PullRequestTaskLink::query()->create([
                    'task_id' => $task->id,
                    'related_task_id' => $target->id,
                    'relation' => TaskRelation::Fixes->value,
                    'reason' => 'Repairs behaviour introduced by that change.',
                    'confidence' => 0.88,
                    'source' => PullRequestTaskLink::SOURCE_AI,
                ]);

                $target->forceFill([
                    'status' => TaskStatus::Reworked->value,
                    'rework_count' => 1,
                ])->save();
            } elseif ($task->type === TaskType::Feature && $mergedAt !== null) {
                $deliveredTasks[$task->id] = $task;
            }

            $this->createUsageRecord($repository, $pullRequest, $review, $openedAt, $i);
        }
    }

    /**
     * Create one fictional pull request, merged unless it is one of the two left open.
     */
    private function createPullRequest(
        GitRepository $repository,
        array $developer,
        int $index,
        int $repoIndex,
        CarbonInterface $openedAt,
        ?CarbonInterface $mergedAt,
        bool $isOpen,
    ): PullRequest {
        $task = self::TASKS[($index + $repoIndex) % count(self::TASKS)];

        return PullRequest::query()->create([
            'git_repository_id' => $repository->id,
            'provider_pr_id' => random_int(10_000, 99_999),
            'number' => 100 + $index,
            'title' => $task[0],
            'description' => $task[3],
            'state' => $isOpen ? PullRequestState::Open->value : PullRequestState::Merged->value,
            'is_draft' => false,
            'author_login' => $developer['login'],
            'author_name' => $developer['name'],
            'source_branch' => 'feature/'.Str::slug(Str::words($task[0], 3, '')),
            'target_branch' => 'main',
            'web_url' => "{$repository->web_url}/pull/".(100 + $index),
            'additions' => 40 + ($index * 37) % 480,
            'deletions' => 8 + ($index * 13) % 120,
            'changed_files_count' => 2 + ($index % 9),
            'commits_count' => 1 + ($index % 5),
            'labels' => [['feature', 'security', 'performance', 'bug'][$index % 4]],
            'opened_at' => $openedAt,
            'merged_at' => $mergedAt,
            'head_sha' => sha1("pr-{$repository->id}-{$index}"),
        ]);
    }

    /**
     * Create the AI review record for a pull request, including its token counts.
     */
    private function createReview(PullRequest $pullRequest, int $index): PullRequestReview
    {
        $verdicts = [ReviewVerdict::Approve, ReviewVerdict::Comment, ReviewVerdict::RequestChanges];

        return PullRequestReview::query()->create([
            'pull_request_id' => $pullRequest->id,
            'head_sha' => $pullRequest->head_sha,
            'ai_model' => 'claude-sonnet-5',
            'walkthrough' => 'This change '.lcfirst($pullRequest->title).'. It touches the service layer and its tests, and adjusts the public contract for callers.',
            'detected_stack' => ['PHP', 'Laravel', 'TypeScript'],
            'suggested_labels' => $pullRequest->labels ?? [],
            'skipped_files' => [],
            'summary' => 'Well-scoped change. The issues below are worth addressing before merge.',
            'verdict' => $verdicts[$index % 3]->value,
            'risk_level' => ['low', 'medium', 'high'][$index % 3],
            'review_intensity' => 'balanced',
            'follow_up_questions' => [],
            'triggered_by' => 'auto',
            'posted_to_provider' => true,
            'review_duration_ms' => 7_000 + ($index * 900) % 22_000,
            'estimated_hours' => 2.0 + ($index % 9),
            'reviewed_at' => $pullRequest->opened_at->copy()->addMinutes(4),
            'prompt_tokens' => 18_000 + ($index * 1_700) % 26_000,
            'completion_tokens' => 1_800 + ($index * 220) % 3_000,
        ]);
    }

    /**
     * Create the findings for a review, resolving the older ones so the backlog looks lived-in.
     */
    private function createFindings(
        GitRepository $repository,
        PullRequest $pullRequest,
        PullRequestReview $review,
        int $index,
    ): void {
        $count = [4, 2, 3, 1, 5, 2][$index % 6];

        for ($f = 0; $f < $count; $f++) {
            $template = self::FINDINGS[($index * 3 + $f) % count(self::FINDINGS)];

            // Older findings are mostly resolved; recent ones are still open.
            $resolved = $index < 8 && $f % 3 !== 0;

            PullRequestReviewFinding::query()->create([
                'pull_request_review_id' => $review->id,
                'pull_request_id' => $pullRequest->id,
                'git_repository_id' => $repository->id,
                'dedupe_key' => Str::slug($template[0]).'-'.$index.'-'.$f,
                'title' => $template[0],
                'severity' => FindingSeverity::from($template[1])->value,
                'category' => FindingCategory::from($template[2])->value,
                'file' => $template[3],
                'file_language' => 'PHP',
                'line' => 20 + ($f * 17),
                'confidence' => 0.72 + ($f % 3) * 0.09,
                'explanation' => $template[4],
                'suggested_fix' => 'Bind the value as a parameter and validate it against an allow-list before use.',
                'is_posted' => true,
                'resolved_at' => $resolved ? $pullRequest->opened_at->copy()->addDays(1) : null,
                'resolution_type' => $resolved ? FindingResolutionType::FixConfirmed->value : null,
                'created_at' => $pullRequest->opened_at->copy()->addMinutes(5),
                'updated_at' => $pullRequest->opened_at->copy()->addMinutes(5),
            ]);
        }
    }

    /**
     * Create the commits behind a pull request, in both the PR and repository commit tables.
     */
    private function createCommits(
        GitRepository $repository,
        PullRequest $pullRequest,
        array $developer,
        CarbonInterface $openedAt,
        int $index,
    ): void {
        $messages = [
            'Add currency resolution to the cart',
            'Extract the pricing calculator',
            'Cover the rounding edge case',
            'Fix the failing integration test',
            'Address review feedback',
        ];

        for ($c = 0; $c <= $index % 4; $c++) {
            $sha = sha1("commit-{$pullRequest->id}-{$c}");
            $committedAt = $openedAt->copy()->subHours(6 - $c);

            PullRequestCommit::query()->create([
                'pull_request_id' => $pullRequest->id,
                'sha' => $sha,
                'short_sha' => substr($sha, 0, 7),
                'message' => $messages[($index + $c) % count($messages)],
                'author_login' => $developer['login'],
                'author_name' => $developer['name'],
                'author_email' => $developer['login'].'@example.com',
                'committed_at' => $committedAt,
                'additions' => 20 + ($c * 31) % 180,
                'deletions' => 4 + ($c * 7) % 40,
                'changed_files_count' => 1 + $c,
            ]);

            RepositoryCommit::query()->create([
                'git_repository_id' => $repository->id,
                'pull_request_id' => $pullRequest->id,
                'sha' => $sha,
                'branch' => $pullRequest->source_branch,
                'author_login' => $developer['login'],
                'author_name' => $developer['name'],
                'author_email' => $developer['login'].'@example.com',
                'message' => $messages[($index + $c) % count($messages)],
                'committed_at' => $committedAt,
                'additions' => 20 + ($c * 31) % 180,
                'deletions' => 4 + ($c * 7) % 40,
                'changed_files_count' => 1 + $c,
                'stats_synced' => true,
            ]);
        }
    }

    /**
     * Create the unit of work the review identified for this pull request.
     */
    private function createTask(
        GitRepository $repository,
        PullRequest $pullRequest,
        PullRequestReview $review,
        array $developer,
        int $index,
        int $repoIndex,
        ?CarbonInterface $mergedAt,
    ): PullRequestTask {
        $template = self::TASKS[($index + $repoIndex) % count(self::TASKS)];
        $type = TaskType::from($template[1]);

        return PullRequestTask::query()->create([
            'pull_request_review_id' => $review->id,
            'pull_request_id' => $pullRequest->id,
            'git_repository_id' => $repository->id,
            'dedupe_key' => Str::slug($template[0]),
            'title' => $template[0],
            'type' => $type->value,
            'status' => ($mergedAt !== null ? TaskStatus::Delivered : TaskStatus::InProgress)->value,
            'description' => $template[3],
            'estimated_hours' => $template[2],
            'files' => ['app/Services/OrderService.php', 'tests/Feature/OrderTest.php'],
            'author_login' => $developer['login'],
            'author_name' => $developer['name'],
            'delivered_at' => $mergedAt,
            'first_delivered_at' => $mergedAt,
            'external_provider' => 'jira',
            'external_key' => 'NW-'.(400 + $index + $repoIndex * 20),
            'created_at' => $pullRequest->opened_at,
            'updated_at' => $pullRequest->opened_at,
        ]);
    }

    /**
     * Record what the review cost, so the AI usage report has data.
     */
    private function createUsageRecord(
        GitRepository $repository,
        PullRequest $pullRequest,
        PullRequestReview $review,
        CarbonInterface $openedAt,
        int $index,
    ): void {
        $prompt = (int) $review->prompt_tokens;
        $completion = (int) $review->completion_tokens;

        AiUsageRecord::query()->create([
            'operation' => AiOperation::PullRequestReview->value,
            'model' => 'claude-sonnet-5',
            'git_repository_id' => $repository->id,
            'pull_request_id' => $pullRequest->id,
            'pull_request_review_id' => $review->id,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            // Sonnet 5: $2 per million input, $10 per million output.
            'cost_usd' => round(($prompt * 2 + $completion * 10) / 1_000_000, 8),
            'duration_ms' => $review->review_duration_ms,
            'created_at' => $openedAt,
            'updated_at' => $openedAt,
        ]);
    }
}
