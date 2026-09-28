<?php

namespace App\Jobs\GIT;

use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\Scanner;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReviewFinding;
use App\Services\Git\Scanning\ScanIssue;
use App\Services\Git\Scanning\ScannerBinary;
use App\Services\Git\Scanning\ScanOutcome;
use App\Services\Git\SecretScanning\GitleaksRunner;
use App\Services\Git\SecretScanning\SecretHit;
use App\Services\Git\SecretScanning\SecretScanner;
use Illuminate\Support\Collection;

/**
 * Scans a pull request head for leaked credentials with gitleaks and reports them.
 *
 * Independent of the AI review: it has its own check run, costs no AI tokens, and
 * runs even when reviews are off. Every output - finding, check annotation, inline
 * comment, git note - carries only gitleaks's redacted match.
 */
class ScanPullRequestSecrets extends SecurityScanJob
{
    /**
     * The scanner this job runs.
     */
    protected function scanner(): Scanner
    {
        return Scanner::Gitleaks;
    }

    /**
     * The overlap lock shared by every secret scan of this pull request.
     */
    protected function lockKey(): string
    {
        return $this->pullRequestId;
    }

    /**
     * Whether the repository wants secret scanning.
     */
    protected function isEnabled(GitRepository $repository): bool
    {
        return (bool) $repository->secret_scanning_enabled;
    }

    /**
     * The gitleaks binary.
     */
    protected function binary(): ScannerBinary
    {
        return app(GitleaksRunner::class);
    }

    /**
     * The scan's name in prose.
     */
    protected function scanName(): string
    {
        return 'secret scan';
    }

    /**
     * The tool's name as shown when it fails.
     */
    protected function toolName(): string
    {
        return 'gitleaks';
    }

    /**
     * Scan the lines the pull request adds, with gitleaks config read from the target branch.
     */
    protected function runScan(GitAccount|string $caller, string $owner, string $name, PullRequest $pullRequest): ScanOutcome
    {
        $result = app(SecretScanner::class)->scan($caller, $owner, $name, $pullRequest->number, (string) $pullRequest->target_branch);

        return new ScanOutcome(
            array_map(fn (SecretHit $hit) => $this->issue($hit), $result->hits),
            $result->filesScanned,
            $result->filesSkipped,
            $result->skippedPaths,
            $result->filesScanned + $result->filesSkipped,
        );
    }

    /**
     * A secret no longer in the diff is still in git history, so it is only "removed".
     */
    protected function resolution(): FindingResolutionType
    {
        return FindingResolutionType::SecretRemoved;
    }

    /**
     * Any secret fails the check.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     * @return array{0: string, 1: string}
     */
    protected function verdict(Collection $findings, ScanOutcome $outcome): array
    {
        $count = $findings->count();

        return $count > 0
            ? ['failure', "{$count} possible ".str('secret')->plural($count).' found']
            : ['success', 'No secrets found'];
    }

    /**
     * Markdown summary shown on the check run.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     */
    protected function checkSummary(Collection $findings, ScanOutcome $outcome): string
    {
        $count = $findings->count();

        $lines = [$count > 0
            ? "gitleaks found {$count} possible ".str('secret')->plural($count).' in the lines this pull request adds. '
                .'**Rotate them** - removing the line does not remove it from git history.'
            : 'gitleaks found no secrets in the lines this pull request adds.'];

        $lines[] = "Files scanned: {$outcome->filesScanned}".($outcome->filesSkipped > 0 ? " - skipped (binary, removed or too large): {$outcome->filesSkipped}" : '');

        return implode("\n\n", $lines);
    }

    /**
     * The summary review, which reminds the author to rotate rather than just delete.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $unposted
     */
    protected function reviewBody(Collection $unposted): string
    {
        return "**PullLens secret scan:** {$unposted->count()} possible ".str('secret')->plural($unposted->count()).' added in this pull request. '
            .'Rotate each credential - removing it from the code is not enough once it has been pushed.';
    }

    /**
     * Reply posted when a secret leaves the diff.
     */
    protected function resolvedReply(): string
    {
        return 'No longer in the diff, but still in this branch\'s git history - rotate this credential if you have not already.';
    }

    /**
     * One line per redacted finding: file, line and gitleaks rule.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     * @return list<string>
     */
    protected function noteLines(PullRequest $pullRequest, Collection $findings): array
    {
        $lines = ["{$findings->count()} possible ".str('secret')->plural($findings->count())." in pull request #{$pullRequest->number}:"];

        foreach ($findings as $finding) {
            $rule = str($finding->dedupe_key)->after('gitleaks:')->before(':');
            $lines[] = "- {$finding->file}:{$finding->line} {$rule}";
        }

        return $lines;
    }

    /**
     * Shape one redacted gitleaks hit as a finding.
     */
    private function issue(SecretHit $hit): ScanIssue
    {
        return new ScanIssue(
            dedupeKey: $hit->dedupeKey(),
            title: 'Secret detected: '.($hit->description !== '' ? $hit->description : $hit->ruleId),
            severity: FindingSeverity::Critical,
            file: $hit->file,
            line: $hit->line,
            explanation: "gitleaks rule `{$hit->ruleId}` matched `{$hit->match}` on an added line. "
                .'The value is now part of this branch\'s git history, so deleting the line does not un-leak it.',
            suggestedFix: 'Rotate or revoke this credential first, then remove it from the code and load it from '
                .'the environment or a secret store. If it is a test fixture, allowlist it in .gitleaks.toml on the target branch.',
            metadata: ['kind' => 'secret', 'rule_id' => $hit->ruleId],
        );
    }
}
