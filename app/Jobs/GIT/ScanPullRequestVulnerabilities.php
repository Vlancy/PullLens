<?php

namespace App\Jobs\GIT;

use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\Scanner;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReviewFinding;
use App\Services\Git\GitHubApiClient;
use App\Services\Git\Scanning\ScanIssue;
use App\Services\Git\Scanning\ScannerBinary;
use App\Services\Git\Scanning\ScanOutcome;
use App\Services\Git\VulnerabilityScanning\TrivyDatabase;
use App\Services\Git\VulnerabilityScanning\TrivyMisconfiguration;
use App\Services\Git\VulnerabilityScanning\TrivyRunner;
use App\Services\Git\VulnerabilityScanning\TrivyScanner;
use App\Services\Git\VulnerabilityScanning\TrivyVulnerability;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Checks the dependency and infrastructure files a pull request changes for known
 * vulnerabilities and insecure settings with Trivy, and reports only what it introduces.
 *
 * Independent of the AI review and of the secret scan: its own check run, no AI
 * cost, its own overlap lock. New high or critical problems fail the check; new
 * medium ones leave it neutral.
 */
class ScanPullRequestVulnerabilities extends SecurityScanJob
{
    /** Most inline comments one scan posts; the rest are listed in its summary review. */
    public const INLINE_COMMENT_LIMIT = 20;

    /** Most unread files the check summary names before it only counts the rest. */
    private const LISTED_UNREAD_FILES = 20;

    /** Longest dedupe key kept readable; a longer identity is hashed to fit the column. */
    private const MAX_DEDUPE_KEY = 200;

    /**
     * The scanner this job runs.
     */
    protected function scanner(): Scanner
    {
        return Scanner::Trivy;
    }

    /**
     * A lock of its own, so a vulnerability scan never waits behind the secret scan.
     */
    protected function lockKey(): string
    {
        return 'trivy:'.$this->pullRequestId;
    }

    /**
     * Whether the repository wants vulnerability scanning.
     */
    protected function isEnabled(GitRepository $repository): bool
    {
        return (bool) $repository->vulnerability_scanning_enabled;
    }

    /**
     * The Trivy binary.
     */
    protected function binary(): ScannerBinary
    {
        return app(TrivyRunner::class);
    }

    /**
     * The scan's name in prose.
     */
    protected function scanName(): string
    {
        return 'vulnerability scan';
    }

    /**
     * The tool's name as shown when it fails.
     */
    protected function toolName(): string
    {
        return 'Trivy';
    }

    /**
     * A missing or stale vulnerability database would miss recent advisories, so the scan waits for a refresh.
     */
    protected function blockedReason(PullRequest $pullRequest): ?string
    {
        $database = app(TrivyDatabase::class);

        if ($database->isFresh()) {
            return null;
        }

        Log::warning(Scanner::Trivy->logPrefix().'.database_stale', [
            'pull_request_id' => $pullRequest->id,
            'updated_at' => $database->updatedAt()?->toIso8601String(),
        ]);

        return TrivyDatabase::STALE_MESSAGE;
    }

    /**
     * Scan the changed files at the head and at the merge base, keeping what the head adds.
     *
     * The merge base is what the pull request actually changed from; the tip of the
     * target branch may have moved on and fixed or added problems of its own. It is
     * only looked up once there is a file to scan.
     */
    protected function runScan(GitAccount|string $caller, string $owner, string $name, PullRequest $pullRequest): ScanOutcome
    {
        $branch = (string) $pullRequest->target_branch;
        $notes = [];

        $baseRef = function () use ($caller, $owner, $name, $branch, $pullRequest, &$notes): string {
            $mergeBase = app(GitHubApiClient::class)->mergeBase($caller, $owner, $name, $branch, $this->headSha);

            if ($mergeBase === null) {
                Log::warning(Scanner::Trivy->logPrefix().'.merge_base_unknown', ['pull_request_id' => $pullRequest->id]);
                $notes[] = "Compared against the tip of {$branch} because the merge base could not be determined.";
            }

            return $mergeBase ?? $branch;
        };

        $result = app(TrivyScanner::class)->scan($caller, $owner, $name, $pullRequest->number, $this->headSha, $baseRef);

        if ($result->limitedPaths !== []) {
            $notes[] = sprintf(
                'Only the first %d of %d dependency and infrastructure files were scanned; the other %d were not, and their earlier findings were left open.',
                TrivyScanner::MAX_TARGETS, $result->targets, count($result->limitedPaths),
            );
        }

        return new ScanOutcome(
            array_values(array_filter(array_map(fn ($issue) => $this->issue($issue), $result->issues))),
            $result->filesScanned,
            $result->filesSkipped,
            $result->skippedPaths,
            $result->targets,
            $notes,
            $result->limitedPaths,
        );
    }

    /**
     * A problem a later push no longer introduces was fixed by that push.
     */
    protected function resolution(): FindingResolutionType
    {
        return FindingResolutionType::FixedInLaterPush;
    }

    /**
     * Most inline comments one scan posts.
     */
    protected function inlineCommentLimit(): ?int
    {
        return self::INLINE_COMMENT_LIMIT;
    }

    /**
     * New high or critical problems fail the check. Otherwise files that could not be
     * read or were over the file limit leave it neutral, since nothing is known about
     * them, and so do new medium problems.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     * @return array{0: string, 1: string}
     */
    protected function verdict(Collection $findings, ScanOutcome $outcome): array
    {
        $blocking = $findings->filter(fn (PullRequestReviewFinding $f) => in_array($f->severity, [FindingSeverity::Critical, FindingSeverity::High], true))->count();

        if ($blocking > 0) {
            return ['failure', "{$blocking} new high or critical ".str('problem')->plural($blocking)];
        }

        if ($outcome->filesSkipped > 0) {
            return ['neutral', "{$outcome->filesSkipped} ".str('file')->plural($outcome->filesSkipped).' could not be read'];
        }

        if ($outcome->limitedPaths !== []) {
            $limited = count($outcome->limitedPaths);

            return ['neutral', "{$limited} ".str('file')->plural($limited).' not scanned: over the '.TrivyScanner::MAX_TARGETS.'-file limit'];
        }

        if ($findings->isNotEmpty()) {
            return ['neutral', "{$findings->count()} new medium-severity ".str('problem')->plural($findings->count())];
        }

        return $outcome->targets === 0
            ? ['success', 'No dependency or infrastructure files changed']
            : ['success', 'No new vulnerabilities or misconfigurations'];
    }

    /**
     * Markdown summary shown on the check run: counts by severity and files scanned.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     */
    protected function checkSummary(Collection $findings, ScanOutcome $outcome): string
    {
        $count = fn (FindingSeverity $s) => $findings->filter(fn (PullRequestReviewFinding $f) => $f->severity === $s)->count();

        $lines = [$findings->isNotEmpty()
            ? sprintf('New in this pull request: %d critical, %d high, %d medium.', $count(FindingSeverity::Critical), $count(FindingSeverity::High), $count(FindingSeverity::Medium))
            : 'Trivy found nothing new in the dependency and infrastructure files this pull request changes.'];

        $lines[] = "Files scanned: {$outcome->filesScanned}".($outcome->filesSkipped > 0 ? " - could not be read: {$outcome->filesSkipped}" : '');

        if ($outcome->skippedPaths !== []) {
            $listed = array_map(fn (string $path) => "- `{$path}`", array_slice($outcome->skippedPaths, 0, self::LISTED_UNREAD_FILES));
            $more = count($outcome->skippedPaths) - count($listed);

            $lines[] = implode("\n", [
                'Could not be read, so they were not checked and their earlier findings were left open:',
                ...$listed,
                ...($more > 0 ? ["- and {$more} more"] : []),
            ]);
        }

        return implode("\n\n", $lines);
    }

    /**
     * The summary review listing every new problem, including those without a line.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $unposted
     */
    protected function reviewBody(Collection $unposted): string
    {
        $lines = ["**PullLens vulnerability scan:** {$unposted->count()} new ".str('problem')->plural($unposted->count()).' introduced by this pull request.', ''];

        foreach ($unposted as $finding) {
            $where = $finding->line !== null ? "{$finding->file}:{$finding->line}" : $finding->file;
            $lines[] = '- **'.strtoupper($finding->severity->value)."** {$finding->title} — `{$where}`";
        }

        if ($unposted->whereNotNull('line')->count() > self::INLINE_COMMENT_LIMIT) {
            $lines[] = '';
            $lines[] = 'Inline comments are limited to '.self::INLINE_COMMENT_LIMIT.' per scan; the rest are listed here only.';
        }

        return implode("\n", $lines);
    }

    /**
     * Reply posted when a later push no longer introduces the problem.
     */
    protected function resolvedReply(): string
    {
        return 'Fixed in a later push - this pull request no longer introduces this problem.';
    }

    /**
     * One line per new problem: file, line, severity, rule and package.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     * @return list<string>
     */
    protected function noteLines(PullRequest $pullRequest, Collection $findings): array
    {
        $lines = ["{$findings->count()} new ".str('problem')->plural($findings->count())." in pull request #{$pullRequest->number}:"];

        foreach ($findings as $finding) {
            $metadata = (array) $finding->metadata;
            $package = isset($metadata['package']) ? " {$metadata['package']}@".($metadata['installed_version'] ?? '') : '';
            $lines[] = "- {$finding->file}:".($finding->line ?? '-')." {$finding->severity->value} ".($metadata['rule_id'] ?? '').$package;
        }

        return $lines;
    }

    /**
     * Shape a Trivy result as a finding, or null for a severity PullLens ignores.
     */
    private function issue(TrivyVulnerability|TrivyMisconfiguration $issue): ?ScanIssue
    {
        $severity = match ($issue->severity) {
            'CRITICAL' => FindingSeverity::Critical,
            'HIGH' => FindingSeverity::High,
            'MEDIUM' => FindingSeverity::Medium,
            default => null,
        };

        if ($severity === null) {
            return null;
        }

        return $issue instanceof TrivyVulnerability
            ? $this->vulnerabilityIssue($issue, $severity)
            : $this->misconfigurationIssue($issue, $severity);
    }

    /**
     * Shape a vulnerable package as a finding.
     */
    private function vulnerabilityIssue(TrivyVulnerability $issue, FindingSeverity $severity): ScanIssue
    {
        $fixed = $issue->fixedVersion;
        $url = $this->webUrl($issue->url);

        return new ScanIssue(
            dedupeKey: $this->dedupeKey($issue->identity()),
            title: "{$issue->vulnerabilityId} in {$issue->packageName}@{$issue->installedVersion}",
            severity: $severity,
            file: $issue->file,
            line: $issue->line,
            explanation: implode("\n\n", array_filter([
                $issue->title !== '' ? $issue->title : $issue->vulnerabilityId,
                "Installed: `{$issue->packageName}@{$issue->installedVersion}`. Fixed in: ".($fixed !== '' ? $fixed : 'no fixed version yet').'.',
                $url !== null ? "Advisory: {$url}" : null,
            ])),
            suggestedFix: $fixed !== ''
                ? "Upgrade {$issue->packageName} to {$fixed} or later."
                : 'No fix has been published yet — consider an alternative package or accept the risk.',
            metadata: array_filter([
                'kind' => 'vulnerability',
                'rule_id' => $issue->vulnerabilityId,
                'package' => $issue->packageName,
                'installed_version' => $issue->installedVersion,
                'fixed_version' => $fixed !== '' ? $fixed : null,
                'url' => $url,
            ], fn ($value) => $value !== null),
        );
    }

    /**
     * Shape a failed misconfiguration check as a finding.
     */
    private function misconfigurationIssue(TrivyMisconfiguration $issue, FindingSeverity $severity): ScanIssue
    {
        $url = $this->webUrl($issue->url);

        return new ScanIssue(
            dedupeKey: $this->dedupeKey($issue->identity()),
            title: $issue->title !== '' ? $issue->title : $issue->checkId,
            severity: $severity,
            file: $issue->file,
            line: $issue->line,
            explanation: implode("\n\n", array_filter([
                $issue->message !== '' ? $issue->message : null,
                $url !== null ? "Check: {$url}" : null,
            ])) ?: $issue->checkId,
            suggestedFix: $issue->resolution !== '' ? $issue->resolution : 'See the linked check for how to fix this setting.',
            metadata: array_filter([
                'kind' => 'misconfiguration',
                'rule_id' => $issue->checkId,
                'resource' => $issue->resource !== '' ? $issue->resource : null,
                'url' => $url,
            ], fn ($value) => $value !== null),
        );
    }

    /**
     * The finding's dedupe key: the identity itself, or its hash when it would not fit the column.
     */
    private function dedupeKey(string $identity): string
    {
        $key = 'trivy:'.$identity;

        return strlen($key) > self::MAX_DEDUPE_KEY ? 'trivy:'.sha1($identity) : $key;
    }

    /**
     * An advisory or check link from Trivy's report, kept only when it is an http(s) address.
     */
    private function webUrl(string $url): ?string
    {
        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
