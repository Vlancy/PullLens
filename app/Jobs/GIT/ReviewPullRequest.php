<?php

namespace App\Jobs\GIT;

use App\Ai\Agents\PullRequestReviewAgent;
use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\ReviewTrigger;
use App\Enums\GIT\ReviewVerdict;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitProviderApp;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestEvent;
use App\Models\GIT\PullRequestReview;
use App\Models\GIT\PullRequestReviewFinding;
use App\Services\AI\AiProviderConfigResolver;
use App\Services\Git\GitHubApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the PullRequestReviewAgent on a pull request, persists the structured
 * review + findings, and optionally posts the review back to GitHub.
 */
class ReviewPullRequest implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public readonly string $pullRequestId,
        public readonly ReviewTrigger $trigger = ReviewTrigger::Auto,
    ) {}

    /**
     * Unique key prevents duplicate review jobs for the same PR from queuing.
     */
    public function uniqueId(): string
    {
        return $this->pullRequestId;
    }

    public function handle(
        GitHubApiClient $api,
        AiProviderConfigResolver $configResolver,
    ): void {
        $pullRequest = PullRequest::with([
            'repository.account',
            'repository.aiProvider',
        ])->findOrFail($this->pullRequestId);

        // Idempotency guard — skip only when this commit has already been reviewed
        // AND the review was successfully posted to GitHub. A review record that
        // was written to the DB but never posted (e.g. the job failed mid-flight)
        // is treated as a partial run and replaced by a fresh attempt.
        $headSha = (string) ($pullRequest->head_sha ?? '');

        if ($headSha !== '' && PullRequestReview::where('pull_request_id', $pullRequest->id)
            ->where('head_sha', $headSha)
            ->where('posted_to_provider', true)
            ->exists()) {
            return;
        }

        $repository = $pullRequest->repository;
        $account    = $repository->account;
        [$owner, $name] = explode('/', $repository->full_name, 2);

        // Use an installation token so all posts appear as "{app}[bot]", not the connected user.
        // Fall back to the connected OAuth account if the token exchange fails.
        $app    = GitProviderApp::where('provider', 'github')->first();
        $poster = $account;

        if ($app?->private_key && $repository->installation_id) {
            $token = $api->installationToken($app, (int) $repository->installation_id);
            if ($token !== '') {
                $poster = $token;
            } else {
                Log::warning('review.installation_token_empty', [
                    'pull_request_id' => $pullRequest->id,
                    'installation_id' => $repository->installation_id,
                ]);
            }
        }

        $files = $api->pullRequestFiles($account, $owner, $name, $pullRequest->number);

        if (empty($files)) {
            return;
        }

        // ── PULLENS.md calibration ───────────────────────────────────────────
        // Fetch the repo's optional review configuration file. Null when absent.
        $calibration = null;
        try {
            $calibration = $api->fetchFileContent($account, $owner, $name, 'PULLENS.md');
        } catch (Throwable) {}

        // ── Previous review dedup ────────────────────────────────────────────
        // Load the most recent review for a different commit so the agent can
        // produce consistent dedupe_keys and we can detect confirmed fixes.
        $previousReview = PullRequestReview::where('pull_request_id', $pullRequest->id)
            ->whereNotNull('head_sha')
            ->where('head_sha', '!=', $headSha)
            ->latest()
            ->first();

        $previousDedupeKeys = $previousReview
            ? $previousReview->findings()->pluck('dedupe_key')->filter()->values()->toArray()
            : [];

        // ── GitHub Check Run ─────────────────────────────────────────────────
        // Create an in-progress check run on the commit so CI gates can watch it.
        // Best-effort: silently skipped on failure (e.g. missing checks:write permission).
        $checkRunId        = null;
        $checkRunCompleted = false;
        if ($headSha !== '') {
            try {
                $checkRun   = $api->createCheckRun($poster, $owner, $name, $headSha);
                $checkRunId = (int) data_get($checkRun, 'id') ?: null;
            } catch (Throwable) {}
        }

        // Post a "working" indicator so the author knows PullLens is active.
        // Deleted in the finally block whether the review succeeds or fails.
        $workingCommentId = null;

        if ($repository->reviews_enabled) {
            try {
                $comment = $api->postIssueComment(
                    $poster,
                    $owner,
                    $name,
                    $pullRequest->number,
                    '**PullLens** is reviewing this pull request. Findings will be posted shortly.'."\n<!-- pullens -->",
                );
                $workingCommentId = (int) data_get($comment, 'id') ?: null;
            } catch (Throwable $e) {
                Log::warning('review.indicator_failed', [
                    'pull_request_id' => $pullRequest->id,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        try {
            $resolved = $configResolver->forRepository($repository);
            $agent = new PullRequestReviewAgent;

            $pullRequestContent = $this->buildContent($pullRequest, $files);
            $metadata = [
                'repository'       => $repository->full_name,
                'pr_number'        => $pullRequest->number,
                'target_branch'    => $pullRequest->target_branch,
                'source_branch'    => $pullRequest->source_branch,
                'author'           => $pullRequest->author_login,
                'review_language'  => $repository->review_language,
                'review_intensity' => $repository->review_intensity->value,
                'review_tone'      => $repository->review_tone?->value ?? 'professional',
                'use_emoji'        => $repository->use_emoji,
            ];

            $startedAt = microtime(true);

            $result = $agent->prompt(
                $agent->buildPrompt($pullRequestContent, $metadata, $calibration, $previousDedupeKeys),
                provider: $resolved->configName,
                model: $resolved->model,
            );

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            // Remove any partial review left by a previous failed attempt for this
            // commit so the DB never accumulates unposted duplicate records.
            if ($headSha !== '') {
                PullRequestReview::where('pull_request_id', $pullRequest->id)
                    ->where('head_sha', $headSha)
                    ->whereNot('posted_to_provider', true)
                    ->delete();
            }

            $review = PullRequestReview::create([
                'pull_request_id'     => $pullRequest->id,
                'head_sha'            => $headSha ?: null,
                'ai_provider_id'      => $resolved->provider->id,
                'ai_model'            => $resolved->model,
                'schema_version'      => data_get($result, 'schema_version', 'pull_lens.pr_review.v2'),
                'walkthrough'         => (string) data_get($result, 'walkthrough', ''),
                'diagram'             => data_get($result, 'diagram'),
                'detected_stack'      => data_get($result, 'detected_stack', []),
                'suggested_labels'    => data_get($result, 'suggested_labels', []),
                'skipped_files'       => data_get($result, 'skipped_files', []),
                'summary'             => (string) data_get($result, 'summary', ''),
                'verdict'             => (string) data_get($result, 'verdict', ReviewVerdict::Comment->value),
                'risk_level'          => (string) data_get($result, 'risk_level', 'medium'),
                'review_intensity'    => $repository->review_intensity->value,
                'follow_up_questions' => data_get($result, 'follow_up_questions', []),
                'triggered_by'        => $this->trigger->value,
                'review_duration_ms'  => $durationMs,
                'reviewed_at'         => now(),
            ]);

            $findings = (array) data_get($result, 'findings', []);

            foreach ($findings as $finding) {
                PullRequestReviewFinding::create([
                    'pull_request_review_id' => $review->id,
                    'pull_request_id'        => $pullRequest->id,
                    'git_repository_id'      => $repository->id,
                    'dedupe_key'             => (string) data_get($finding, 'dedupe_key'),
                    'title'                  => (string) data_get($finding, 'title'),
                    'severity'               => (string) data_get($finding, 'severity'),
                    'category'               => (string) data_get($finding, 'category'),
                    'file'                   => (string) data_get($finding, 'file'),
                    'file_language'          => data_get($finding, 'file_language'),
                    'line'                   => data_get($finding, 'line'),
                    'confidence'             => (float) data_get($finding, 'confidence', 0.5),
                    'explanation'            => (string) data_get($finding, 'explanation'),
                    'suggested_fix'          => (string) data_get($finding, 'suggested_fix'),
                ]);
            }

            $this->maybeApplyLabels($pullRequest, $review, $api, $account, $owner, $name);

            // ── Confirmed-fix resolution ─────────────────────────────────────
            // Any finding from the previous review whose dedupe_key does NOT appear
            // in the new review is considered confirmed-fixed by the AI.
            $this->maybeResolveConfirmedFixes(
                $pullRequest, $previousReview, $previousDedupeKeys, $findings,
                $api, $poster, $owner, $name,
            );

            $this->maybePostToGitHub(
                $pullRequest, $review, $result, $api, $poster, $account,
                $owner, $name, (bool) $repository->use_emoji, $previousDedupeKeys,
            );

            // ── Complete the GitHub Check Run ────────────────────────────────
            if ($checkRunId !== null) {
                $this->maybeCompleteCheckRun($api, $poster, $owner, $name, $checkRunId, $review, $findings);
                $checkRunCompleted = true;
            }

            if ($repository->auto_merge && $review->verdict === ReviewVerdict::Approve) {
                MergePullRequest::dispatch($pullRequest->id);
            }

            PullRequestEvent::create([
                'pull_request_id'   => $pullRequest->id,
                'git_repository_id' => $repository->id,
                'event_type'        => 'pull_lens_review_completed',
                'actor_type'        => 'bot',
                'payload'           => [
                    'review_id'   => $review->id,
                    'verdict'     => $review->verdict,
                    'risk_level'  => $review->risk_level,
                    'findings'    => $review->findings()->count(),
                    'duration_ms' => $durationMs,
                ],
                'occurred_at' => now(),
            ]);
        } finally {
            // Always remove the working indicator, whether the review succeeded or failed.
            if ($workingCommentId !== null) {
                try {
                    $api->deleteIssueComment($poster, $owner, $name, $workingCommentId);
                } catch (Throwable) {}
            }

            // If the check run was created but the job threw before completing it,
            // mark it failed so it never stays stuck in "in_progress" on the commit.
            if ($checkRunId !== null && ! $checkRunCompleted) {
                try {
                    $api->updateCheckRun(
                        $poster, $owner, $name, $checkRunId,
                        'failure',
                        'PullLens — Review failed',
                        'An error occurred during the review. Check Horizon logs for details.',
                    );
                } catch (Throwable) {}
            }
        }
    }

    /**
     * Apply the AI-suggested labels to the PR when auto_apply_labels is enabled.
     * Failures are logged and swallowed — labels are best-effort.
     */
    private function maybeApplyLabels(
        PullRequest $pullRequest,
        PullRequestReview $review,
        GitHubApiClient $api,
        $account,
        string $owner,
        string $name,
    ): void {
        $repository = $pullRequest->repository;

        if (! $repository->auto_apply_labels) {
            return;
        }

        $labels = array_values(array_filter((array) $review->suggested_labels));

        if (empty($labels)) {
            return;
        }

        try {
            $api->applyLabels($account, $owner, $name, $pullRequest->number, $labels);
        } catch (Throwable $e) {
            Log::warning('labels.apply_failed', [
                'pull_request_id' => $pullRequest->id,
                'labels'          => $labels,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    /**
     * Post the review to GitHub and follow up with per-finding inline comments.
     *
     * Flow:
     *   1. Assign the account as a requested reviewer (skipped for self-reviews).
     *   2. Submit the main review with a rich body (walkthrough + per-file finding list).
     *   3. Post an inline comment on each finding that has a line number.
     *
     * "Auto-approve and submit" controls whether PullLens submits real APPROVE /
     * REQUEST_CHANGES reviews or only leaves a COMMENT:
     *   - ON  → honour the AI verdict; also promote APPROVE → REQUEST_CHANGES when
     *            critical or high findings are present despite the AI approving.
     *   - OFF → always post as COMMENT so PullLens never blocks the PR.
     */
    private function maybePostToGitHub(
        PullRequest $pullRequest,
        PullRequestReview $review,
        mixed $result,
        GitHubApiClient $api,
        GitAccount|string $poster,
        GitAccount $account,
        string $owner,
        string $name,
        bool $useEmoji = true,
        array $previousDedupeKeys = [],
    ): void {
        $repository = $pullRequest->repository;

        if (! $repository->reviews_enabled) {
            return;
        }

        $findings = (array) data_get($result, 'findings', []);

        $verdictMap = [
            ReviewVerdict::Approve->value        => 'APPROVE',
            ReviewVerdict::Comment->value        => 'COMMENT',
            ReviewVerdict::RequestChanges->value => 'REQUEST_CHANGES',
        ];

        $gitHubEvent = $verdictMap[$review->verdict->value] ?? 'COMMENT';

        $hasBlockers = collect($findings)->contains(
            fn ($f) => in_array(data_get($f, 'severity'), ['critical', 'high'], true)
        );

        if ($hasBlockers) {
            // Critical/high findings always block the PR regardless of the auto_approve setting.
            $gitHubEvent = 'REQUEST_CHANGES';
        } elseif (! $repository->auto_approve && $gitHubEvent === 'APPROVE') {
            // auto_approve controls only whether PullLens can approve — never prevents blocking.
            $gitHubEvent = 'COMMENT';
        }

        $accountLogin  = strtolower((string) ($account->nickname ?? ''));
        $prAuthorLogin = strtolower((string) ($pullRequest->author_login ?? ''));

        // GitHub rejects REQUEST_CHANGES / APPROVE when the reviewer IS the PR author.
        // This only applies when posting as the connected OAuth account — the App bot
        // identity (installation token) is never a PR author, so no downgrade is needed.
        if ($poster instanceof GitAccount
            && in_array($gitHubEvent, ['REQUEST_CHANGES', 'APPROVE'], true)
            && $accountLogin !== ''
            && $accountLogin === $prAuthorLogin
        ) {
            $gitHubEvent = 'COMMENT';
        }

        // When posting as the GitHub App bot (installation token), skip requesting the human
        // account as reviewer — the bot appears automatically in the Reviewers panel once it
        // submits its review via postPullRequestReview. Only request the human when posting
        // as the connected OAuth account directly.
        if ($poster instanceof GitAccount && $accountLogin !== $prAuthorLogin && $accountLogin !== '') {
            try {
                $api->requestReviewers($account, $owner, $name, $pullRequest->number, [$account->nickname]);
            } catch (Throwable $e) {
                Log::warning('reviewer.assign_failed', [
                    'pull_request_id' => $pullRequest->id,
                    'login'           => $account->nickname,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        // Submit the main review with a rich body.
        try {
            $posted = $api->postPullRequestReview(
                $poster,
                $owner,
                $name,
                $pullRequest->number,
                $this->buildReviewBody($review, $findings, $useEmoji),
                $gitHubEvent,
            );

            $review->update([
                'posted_to_provider' => true,
                'provider_review_id' => data_get($posted, 'id'),
            ]);
        } catch (Throwable $e) {
            Log::warning('review.post_failed', [
                'pull_request_id' => $pullRequest->id,
                'review_id'       => $review->id,
                'verdict'         => $review->verdict->value,
                'poster_type'     => $poster instanceof GitAccount ? 'oauth' : 'installation_token',
                'error'           => $e->getMessage(),
            ]);

            // Fallback: post the review as a plain issue comment when the PR review
            // endpoint rejects the request (e.g. GitHub App lacks pull-requests:write permission).
            try {
                $api->postIssueComment(
                    $poster,
                    $owner,
                    $name,
                    $pullRequest->number,
                    $this->buildReviewBody($review, $findings, $useEmoji)."\n<!-- pullens -->",
                );
                $review->update(['posted_to_provider' => true]);
            } catch (Throwable $e2) {
                Log::warning('review.post_fallback_failed', [
                    'pull_request_id' => $pullRequest->id,
                    'review_id'       => $review->id,
                    'error'           => $e2->getMessage(),
                ]);

                return;
            }
        }

        // Post an inline comment for each finding that has a specific line number.
        $headSha = (string) ($pullRequest->head_sha ?? '');

        if ($headSha === '') {
            return;
        }

        foreach ($findings as $finding) {
            $line      = data_get($finding, 'line');
            $file      = (string) data_get($finding, 'file', '');
            $dedupeKey = (string) data_get($finding, 'dedupe_key', '');

            if ($line === null || $file === '') {
                continue;
            }

            // Skip inline comments for findings already posted in a previous review —
            // the original thread is still open and re-commenting would be noise.
            if ($dedupeKey !== '' && in_array($dedupeKey, $previousDedupeKeys, true)) {
                continue;
            }

            try {
                $posted = $api->postReviewComment(
                    $poster,
                    $owner,
                    $name,
                    $pullRequest->number,
                    $headSha,
                    $file,
                    (int) $line,
                    $this->buildFindingComment($finding, $useEmoji),
                );

                $postedCommentId = (int) data_get($posted, 'id');

                if ($postedCommentId) {
                    $review->findings()
                        ->where('dedupe_key', (string) data_get($finding, 'dedupe_key'))
                        ->update([
                            'provider_comment_id' => $postedCommentId,
                            'is_posted'           => true,
                        ]);
                }
            } catch (Throwable $e) {
                Log::warning('review_comment.post_failed', [
                    'pull_request_id' => $pullRequest->id,
                    'file'            => $file,
                    'line'            => $line,
                    'error'           => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Reply to and mark as FixConfirmed any finding from the previous review that
     * the new AI review did not reproduce — indicating the issue is now resolved.
     *
     * @param  string[]  $previousDedupeKeys
     * @param  array<int, array<string, mixed>>  $newFindings
     */
    private function maybeResolveConfirmedFixes(
        PullRequest $pullRequest,
        ?PullRequestReview $previousReview,
        array $previousDedupeKeys,
        array $newFindings,
        GitHubApiClient $api,
        GitAccount|string $poster,
        string $owner,
        string $name,
    ): void {
        if ($previousReview === null || empty($previousDedupeKeys)) {
            return;
        }

        $newKeys       = collect($newFindings)->pluck('dedupe_key')->filter()->values()->toArray();
        $confirmedFixed = array_diff($previousDedupeKeys, $newKeys);

        if (empty($confirmedFixed)) {
            return;
        }

        $headSha  = (string) ($pullRequest->head_sha ?? '');
        $shortSha = $headSha !== '' ? substr($headSha, 0, 7) : 'this commit';

        $previousReview->findings()
            ->whereIn('dedupe_key', $confirmedFixed)
            ->whereNull('resolved_at')
            ->where('is_posted', true)
            ->whereNotNull('provider_comment_id')
            ->each(function (PullRequestReviewFinding $finding) use ($api, $poster, $owner, $name, $pullRequest, $shortSha): void {
                try {
                    $api->replyToReviewComment(
                        $poster,
                        $owner,
                        $name,
                        $pullRequest->number,
                        (int) $finding->provider_comment_id,
                        "✅ PullLens did not reproduce this finding in commit `{$shortSha}` — marking as confirmed fixed.\n\n<!-- pullens -->",
                    );
                } catch (Throwable) {}

                $finding->update([
                    'resolved_at'     => now(),
                    'resolution_type' => FindingResolutionType::FixConfirmed->value,
                ]);
            });
    }

    /**
     * Complete the GitHub Check Run with a conclusion, summary table, and per-finding annotations.
     */
    private function maybeCompleteCheckRun(
        GitHubApiClient $api,
        GitAccount|string $poster,
        string $owner,
        string $name,
        int $checkRunId,
        PullRequestReview $review,
        array $findings,
    ): void {
        $blockerCount = collect($findings)
            ->filter(fn ($f) => in_array(strtolower((string) data_get($f, 'severity')), ['critical', 'high'], true))
            ->count();

        $totalCount = count($findings);

        $conclusion = $blockerCount > 0 ? 'failure' : ($totalCount > 0 ? 'neutral' : 'success');

        $title = $totalCount === 0
            ? 'PullLens — No issues found'
            : "PullLens — {$totalCount} ".($totalCount === 1 ? 'finding' : 'findings')
                .($blockerCount > 0 ? " ({$blockerCount} blocker".($blockerCount > 1 ? 's' : '').')' : '');

        $risk    = strtolower((string) ($review->risk_level ?? 'medium'));
        $verdict = str_replace('_', ' ', $review->verdict?->value ?? 'comment');

        $sevCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'informational' => 0];
        foreach ($findings as $f) {
            $sev = strtolower((string) data_get($f, 'severity', 'medium'));
            $sevCounts[$sev] = ($sevCounts[$sev] ?? 0) + 1;
        }

        $summary = implode("\n", [
            "**Risk:** ".ucfirst($risk)."  ",
            "**Verdict:** ".ucfirst($verdict)."  ",
            "**Findings:** {$totalCount} total",
            '',
            '| Severity | Count |',
            '|---|---|',
            "| 🔴 Critical | {$sevCounts['critical']} |",
            "| 🟠 High | {$sevCounts['high']} |",
            "| 🟡 Medium | {$sevCounts['medium']} |",
            "| 🟢 Low | {$sevCounts['low']} |",
            "| ℹ️ Informational | {$sevCounts['informational']} |",
            '',
            (string) $review->summary,
        ]);

        $annotations = [];
        foreach ($findings as $f) {
            $file = (string) data_get($f, 'file', '');
            $line = max(1, (int) (data_get($f, 'line') ?? 1));
            $sev  = strtolower((string) data_get($f, 'severity', 'medium'));

            if ($file === '') {
                continue;
            }

            $annotations[] = [
                'path'             => $file,
                'start_line'       => $line,
                'end_line'         => $line,
                'annotation_level' => match ($sev) {
                    'critical', 'high' => 'failure',
                    'medium'           => 'warning',
                    default            => 'notice',
                },
                'title'   => (string) data_get($f, 'title', ''),
                'message' => (string) data_get($f, 'explanation', ''),
            ];
        }

        try {
            $api->updateCheckRun($poster, $owner, $name, $checkRunId, $conclusion, $title, $summary, $annotations);
        } catch (Throwable $e) {
            Log::warning('check_run.update_failed', [
                'check_run_id' => $checkRunId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sanitize AI-generated Mermaid source so it doesn't break GitHub's renderer.
     *
     * The main failure mode: the AI puts code expressions with | || && () [] {} in
     * node label text. Mermaid treats | as an edge-label delimiter, causing parse
     * errors. We fix labels in-place by quoting any unquoted bracket content that
     * contains a pipe and by replacing || / && with OR / AND inside labels.
     */
    private function sanitizeMermaid(string $diagram): string
    {
        $lines = explode("\n", $diagram);

        $sanitized = array_map(function (string $line): string {
            // Replace || and && inside square-bracket or parenthesis node labels.
            // Pattern: [...] or (...) or {...} — replace operator symbols in their content.
            $line = preg_replace_callback(
                '/(\[|\()([^\]\)]+)(\]|\))/',
                function (array $m): string {
                    $inner = $m[2];
                    $inner = str_replace(['||', '&&', '->'], ['OR', 'AND', 'to'], $inner);
                    // If the inner text still has a bare pipe, wrap in quotes.
                    if (str_contains($inner, '|') && ! preg_match('/^".*"$/', $inner)) {
                        $inner = '"'.str_replace('"', "'", $inner).'"';
                    }

                    return $m[1].$inner.$m[3];
                },
                $line,
            ) ?? $line;

            return $line;
        }, $lines);

        return implode("\n", $sanitized);
    }

    private function stripEmoji(string $text): string
    {
        return (string) preg_replace(
            '/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27FF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FEFF}' .
            '\x{1F300}-\x{1F9FF}\x{1FA00}-\x{1FA9F}\x{200D}\x{FE0F}]+/u',
            '',
            $text,
        );
    }

    private function buildReviewBody(PullRequestReview $review, array $findings, bool $useEmoji = true): string
    {
        $maybeStrip = fn (string $s): string => $useEmoji ? $s : $this->stripEmoji($s);

        $risk = strtolower((string) ($review->risk_level ?? 'medium'));

        $riskLabel = $useEmoji
            ? match ($risk) {
                'critical' => '🔴 Critical',
                'high'     => '🟠 High',
                'medium'   => '🟡 Medium',
                'low'      => '🟢 Low',
                default    => ucfirst($risk),
            }
            : ucfirst($risk);

        // GitHub colored alert box keyed to risk level.
        $alertType = match ($risk) {
            'critical' => 'CAUTION',
            'high'     => 'WARNING',
            'medium'   => 'IMPORTANT',
            default    => 'NOTE',
        };

        $blockerCount = collect($findings)
            ->filter(fn ($f) => in_array(strtolower((string) data_get($f, 'severity')), ['critical', 'high'], true))
            ->count();

        $totalCount  = count($findings);
        $countLine   = $totalCount === 0
            ? 'No issues found'
            : "{$totalCount} ".($totalCount === 1 ? 'finding' : 'findings').($blockerCount > 0 ? " · **{$blockerCount} blocker".($blockerCount > 1 ? 's' : '').'**' : '');

        $lines = [];

        // ── Header ──────────────────────────────────────────────────────────
        $lines[] = '## PullLens Review';
        $lines[] = '';
        $lines[] = "> [{$alertType}]";
        $lines[] = "> **Risk: {$riskLabel}** · {$countLine}";
        $lines[] = '';

        // ── Walkthrough + optional diagram ──────────────────────────────────
        if ($review->walkthrough) {
            $lines[] = '### What changed';
            $lines[] = '';
            $lines[] = $maybeStrip((string) $review->walkthrough);
            $lines[] = '';

            $diagram = trim((string) ($review->diagram ?? ''));
            if ($diagram !== '') {
                $lines[] = '```mermaid';
                $lines[] = $this->sanitizeMermaid($diagram);
                $lines[] = '```';
                $lines[] = '';
            }
        }

        // ── Findings ─────────────────────────────────────────────────────────
        if (! empty($findings)) {
            // Sort: critical → high → medium → low → informational
            $sevOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'informational' => 4];
            usort($findings, function ($a, $b) use ($sevOrder): int {
                $sa = $sevOrder[strtolower((string) data_get($a, 'severity', 'medium'))] ?? 5;
                $sb = $sevOrder[strtolower((string) data_get($b, 'severity', 'medium'))] ?? 5;

                return $sa <=> $sb;
            });

            $lines[] = '---';
            $lines[] = '';
            $lines[] = '### Findings';
            $lines[] = '';

            // Overview table
            $lines[] = '| Severity | File | Issue |';
            $lines[] = '|:--------:|------|-------|';

            foreach ($findings as $f) {
                $sev   = strtolower((string) data_get($f, 'severity', 'medium'));
                $file  = (string) data_get($f, 'file', '');
                $line  = data_get($f, 'line');
                $title = $maybeStrip((string) data_get($f, 'title', ''));

                $fileCell = $line !== null ? "`{$file}:{$line}`" : "`{$file}`";

                $sevCell = $useEmoji
                    ? match ($sev) {
                        'critical'      => '🔴 Critical',
                        'high'          => '🟠 High',
                        'medium'        => '🟡 Medium',
                        'low'           => '🟢 Low',
                        'informational' => 'ℹ️ Info',
                        default         => ucfirst($sev),
                    }
                    : ucfirst($sev);

                $lines[] = "| {$sevCell} | {$fileCell} | {$title} |";
            }

            $lines[] = '';

            // Collapsible detail block per finding
            foreach ($findings as $f) {
                $sev         = strtolower((string) data_get($f, 'severity', 'medium'));
                $title       = $maybeStrip((string) data_get($f, 'title', ''));
                $file        = (string) data_get($f, 'file', '');
                $line        = data_get($f, 'line');
                $explanation = $maybeStrip((string) data_get($f, 'explanation', ''));
                $fix         = $maybeStrip((string) data_get($f, 'suggested_fix', ''));
                $category    = ucfirst((string) data_get($f, 'category', ''));
                $isBlocker   = in_array($sev, ['critical', 'high'], true);

                $sevIcon  = $useEmoji
                    ? match ($sev) {
                        'critical' => '🔴',
                        'high'     => '🟠',
                        'medium'   => '🟡',
                        'low'      => '🟢',
                        default    => 'ℹ️',
                    }
                    : '';

                // <summary> does not render markdown — use plain text only.
                $blockerTag  = $isBlocker ? ' [BLOCKER]' : '';
                $fileDisplay = $line !== null ? "{$file}:{$line}" : $file;
                $summaryLine = trim("{$sevIcon}{$blockerTag} {$title} — {$fileDisplay}");

                $lines[] = '<details>';
                $lines[] = "<summary>{$summaryLine}</summary>";
                $lines[] = '';
                $lines[] = "**Category:** {$category}";
                $lines[] = '';
                if ($explanation !== '') {
                    $lines[] = $explanation;
                    $lines[] = '';
                }
                if ($fix !== '') {
                    $lines[] = '**Suggested fix:**';
                    $lines[] = '';
                    $lines[] = $fix;
                    $lines[] = '';
                }
                $lines[] = '</details>';
                $lines[] = '';
            }
        }

        // ── Summary ──────────────────────────────────────────────────────────
        if ($review->summary) {
            $lines[] = '---';
            $lines[] = '';
            $lines[] = '> '.str_replace("\n", "\n> ", $maybeStrip(trim((string) $review->summary)));
        }

        return implode("\n", $lines);
    }

    /**
     * Build the body for a single inline review comment on a finding's line.
     *
     * @param  array<string, mixed>  $finding
     */
    private function buildFindingComment(array $finding, bool $useEmoji = true): string
    {
        $maybeStrip = fn (string $s): string => $useEmoji ? $s : $this->stripEmoji($s);

        $sev         = strtolower((string) data_get($finding, 'severity', 'medium'));
        $title       = $maybeStrip((string) data_get($finding, 'title', ''));
        $explanation = $maybeStrip((string) data_get($finding, 'explanation', ''));
        $fix         = $maybeStrip((string) data_get($finding, 'suggested_fix', ''));
        $category    = ucfirst((string) data_get($finding, 'category', ''));
        $isBlocker   = in_array($sev, ['critical', 'high'], true);

        $alertType = match ($sev) {
            'critical' => 'CAUTION',
            'high'     => 'WARNING',
            'medium'   => 'IMPORTANT',
            default    => 'NOTE',
        };

        $blockerPrefix = $isBlocker
            ? ($useEmoji ? '🚫 [BLOCKER] ' : '[BLOCKER] ')
            : '';

        $parts = [
            "> [!{$alertType}]",
            "> **{$blockerPrefix}{$title}** · ".ucfirst($sev)." · {$category}",
            '',
        ];

        if ($explanation !== '') {
            $parts[] = $explanation;
            $parts[] = '';
        }

        if ($fix !== '') {
            $parts[] = '**Suggested fix:**';
            $parts[] = '';
            $parts[] = $fix;
            $parts[] = '';
        }

        return implode("\n", $parts)."<!-- pullens -->";
    }

    /**
     * Build the PR content string fed to the AI agent as untrusted input.
     * Each file's patch is included inline, truncated per-file to avoid token exhaustion.
     *
     * @param  array<int, array<string, mixed>>  $files
     */
    private function buildContent(PullRequest $pullRequest, array $files): string
    {
        $lines = [
            "PR #{$pullRequest->number}: {$pullRequest->title}",
            "Author: {$pullRequest->author_login}",
            "Target: {$pullRequest->target_branch} ← {$pullRequest->source_branch}",
            '',
            '## Description',
            (string) ($pullRequest->description ?? '(no description)'),
            '',
            '## Changed Files ('.count($files).' files, +'.$pullRequest->additions.' -'.$pullRequest->deletions.')',
        ];

        $totalPatchBytes = 0;
        $maxPatchBytesPerFile = 30_000;
        $maxTotalPatchBytes = 200_000;

        foreach ($files as $file) {
            $filename = (string) data_get($file, 'filename');
            $status = (string) data_get($file, 'status', 'modified');
            $additions = (int) data_get($file, 'additions', 0);
            $deletions = (int) data_get($file, 'deletions', 0);

            $lines[] = '';
            $lines[] = "### {$filename} [{$status}] (+{$additions} -{$deletions})";

            $patch = (string) data_get($file, 'patch', '');

            if ($patch !== '' && $totalPatchBytes < $maxTotalPatchBytes) {
                if (strlen($patch) > $maxPatchBytesPerFile) {
                    $patch = substr($patch, 0, $maxPatchBytesPerFile)."\n[patch truncated]";
                }

                $totalPatchBytes += strlen($patch);
                $lines[] = '```diff';
                $lines[] = $patch;
                $lines[] = '```';
            } elseif ($totalPatchBytes >= $maxTotalPatchBytes) {
                $lines[] = '[patch omitted — total diff size limit reached]';
            }
        }

        return implode("\n", $lines);
    }
}
