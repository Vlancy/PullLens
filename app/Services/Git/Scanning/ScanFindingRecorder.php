<?php

namespace App\Services\Git\Scanning;

use App\Enums\GIT\FindingCategory;
use App\Enums\GIT\FindingResolutionType;
use App\Enums\GIT\Scanner;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReviewFinding;
use App\Models\GIT\SecurityScan;
use Illuminate\Support\Collection;

/**
 * Turns a scanner's issues into findings and closes the ones a rescan no longer sees.
 */
class ScanFindingRecorder
{
    /**
     * Create a finding per new issue and refresh the ones already open.
     *
     * An issue whose latest finding a human dismissed (false positive, won't fix,
     * acknowledged) stays resolved and is left out entirely. Every other resolution
     * reopens as a new finding.
     *
     * @param  list<ScanIssue>  $issues
     * @return Collection<int, PullRequestReviewFinding>
     */
    public function record(PullRequest $pullRequest, SecurityScan $scan, array $issues): Collection
    {
        $source = $scan->scanner->findingSource();

        $known = PullRequestReviewFinding::query()
            ->where('pull_request_id', $pullRequest->id)
            ->where('source', $source->value)
            ->whereIn('dedupe_key', array_map(fn (ScanIssue $issue) => $issue->dedupeKey, $issues))
            ->get()
            ->groupBy('dedupe_key');

        $findings = collect();

        foreach ($issues as $issue) {
            $key = $issue->dedupeKey;

            if ($findings->has($key)) {
                continue;
            }

            $previous = $known->get($key, collect());
            $existing = $previous->first(fn (PullRequestReviewFinding $f) => $f->resolved_at === null);

            if ($existing !== null) {
                // The same problem, possibly moved: keep its thread, follow its line, and take the
                // scanner's current wording and severity (an advisory can be re-rated or fixed).
                $existing->update([
                    'line' => $issue->line,
                    'security_scan_id' => $scan->id,
                    'metadata' => $issue->metadata,
                    'severity' => $issue->severity->value,
                    'title' => mb_substr($issue->title, 0, 255),
                    'explanation' => $issue->explanation,
                    'suggested_fix' => $issue->suggestedFix,
                ]);
                $findings->put($key, $existing);

                continue;
            }

            $latest = $previous->sortByDesc(fn (PullRequestReviewFinding $f) => $f->resolved_at?->getTimestamp())->first();

            if ($latest !== null && $latest->resolution_type?->staysDismissed() === true) {
                continue;
            }

            $findings->put($key, PullRequestReviewFinding::query()->create([
                'pull_request_review_id' => null,
                'security_scan_id' => $scan->id,
                'pull_request_id' => $pullRequest->id,
                'git_repository_id' => $pullRequest->git_repository_id,
                'source' => $source->value,
                'dedupe_key' => $key,
                'title' => mb_substr($issue->title, 0, 255),
                'severity' => $issue->severity->value,
                'category' => FindingCategory::Security->value,
                'file' => $issue->file,
                'line' => $issue->line,
                'confidence' => 1.0,
                'explanation' => $issue->explanation,
                'suggested_fix' => $issue->suggestedFix,
                'metadata' => $issue->metadata,
            ]));
        }

        return $findings->values();
    }

    /**
     * Resolve this scanner's open findings the new scan no longer sees.
     *
     * Findings in files the scan had to skip are left open: not seeing them is not
     * the same as them being gone.
     *
     * @param  array<int, string>  $currentKeys
     * @param  list<string>  $skippedPaths
     * @return Collection<int, PullRequestReviewFinding>
     */
    public function resolveMissing(PullRequest $pullRequest, Scanner $scanner, array $currentKeys, array $skippedPaths, FindingResolutionType $resolution): Collection
    {
        $gone = PullRequestReviewFinding::query()
            ->where('pull_request_id', $pullRequest->id)
            ->where('source', $scanner->findingSource()->value)
            ->whereNull('resolved_at')
            ->whereNotIn('dedupe_key', $currentKeys)
            ->when($skippedPaths !== [], fn ($query) => $query->whereNotIn('file', $skippedPaths))
            ->get();

        foreach ($gone as $finding) {
            $finding->update(['resolved_at' => now(), 'resolution_type' => $resolution]);
        }

        return $gone;
    }
}
