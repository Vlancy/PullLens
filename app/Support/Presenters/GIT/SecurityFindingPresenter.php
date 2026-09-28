<?php

namespace App\Support\Presenters\GIT;

use App\Models\GIT\PullRequestReviewFinding;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Serializes scanner findings for the Security page, with each kind's own columns.
 *
 * Both the stored columns and the metadata keys are whitelisted: only stored,
 * already-redacted text is sent, and a secret finding's explanation carries
 * gitleaks's redacted match, never the value.
 */
final class SecurityFindingPresenter
{
    /** Metadata keys the page may show; anything else stored there stays on the server. */
    private const METADATA_KEYS = ['kind', 'rule_id', 'package', 'installed_version', 'fixed_version', 'resource', 'url'];

    /** Columns of the review-list row the Security page shows, in order after the metadata. */
    private const FINDING_KEYS = [
        'file', 'line', 'explanation', 'suggested_fix', 'resolved_at',
        'resolution_type', 'created_at', 'repository', 'pull_request',
    ];

    /**
     * One row of the Security page.
     *
     * @return array<string, mixed>
     */
    public static function toArray(PullRequestReviewFinding $finding): array
    {
        $row = FindingPresenter::toArray($finding);
        $metadata = (array) ($finding->metadata ?? []);

        $columns = [];
        foreach (self::METADATA_KEYS as $key) {
            $columns[$key] = isset($metadata[$key]) && is_scalar($metadata[$key]) ? (string) $metadata[$key] : null;
        }

        return [
            ...Arr::only($row, ['id', 'title', 'severity']),
            ...$columns,
            ...Arr::only($row, self::FINDING_KEYS),
        ];
    }

    /**
     * Serialize a page of rows.
     *
     * @param  iterable<int, PullRequestReviewFinding>  $findings
     * @return array<int, array<string, mixed>>
     */
    public static function collection(iterable $findings): array
    {
        return Collection::make($findings)
            ->map(static fn (PullRequestReviewFinding $finding): array => self::toArray($finding))
            ->values()
            ->all();
    }
}
