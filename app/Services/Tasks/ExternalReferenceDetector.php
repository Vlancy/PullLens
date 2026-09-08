<?php

namespace App\Services\Tasks;

use App\Enums\GIT\TaskTrackerProvider;
use App\Models\GIT\PullRequest;

/**
 * Finds the issue key a pull request already refers to.
 *
 * Teams put tracker keys in branch names, PR titles and descriptions long before any
 * integration exists. Capturing them now means the tasks table is already joined to
 * Jira (or Linear, or GitHub Issues) by key on the day an integration is added -
 * no backfill, no migration of historical rows.
 *
 * Detection is deliberately conservative: a wrong key silently mis-files work, which
 * is worse than no key at all.
 */
class ExternalReferenceDetector
{
    /**
     * Jira and Linear style keys: PROJ-123, ABC-4567.
     *
     * Anchored on a word boundary and requiring at least two letters, so a bare
     * "v2-3" or a date fragment does not match.
     */
    private const ISSUE_KEY_PATTERN = '/\b([A-Z][A-Z0-9]{1,9})-(\d{1,6})\b/';

    /**
     * Project keys that look like issue keys but never are.
     *
     * @var array<int, string>
     */
    private const IGNORED_PREFIXES = ['UTF', 'ISO', 'RFC', 'SHA', 'MD5', 'HTTP', 'IPV4', 'IPV6', 'AES', 'RSA'];

    /**
     * Resolve the tracker reference for a pull request, or null when there is none.
     *
     * @return array{provider: TaskTrackerProvider, key: string, url: string|null}|null
     */
    public function detect(PullRequest $pullRequest): ?array
    {
        // Branch name first: it is the least likely of the three to contain prose that
        // happens to look like a key.
        $haystacks = [
            (string) $pullRequest->source_branch,
            (string) $pullRequest->title,
            (string) $pullRequest->description,
        ];

        foreach ($haystacks as $haystack) {
            $key = $this->firstIssueKey($haystack);

            if ($key !== null) {
                $provider = $this->configuredProvider();

                return [
                    'provider' => $provider,
                    'key' => $key,
                    'url' => $provider->urlFor($key, config('pulllens.tasks.tracker_base_url')),
                ];
            }
        }

        return null;
    }

    /**
     * The first plausible issue key in a string.
     */
    private function firstIssueKey(string $subject): ?string
    {
        if ($subject === '') {
            return null;
        }

        // Branch names lowercase the key; normalise before matching.
        if (! preg_match_all(self::ISSUE_KEY_PATTERN, strtoupper($subject), $matches, PREG_SET_ORDER)) {
            return null;
        }

        foreach ($matches as $match) {
            if (! in_array($match[1], self::IGNORED_PREFIXES, true)) {
                return $match[1].'-'.$match[2];
            }
        }

        return null;
    }

    /**
     * Which tracker the detected keys belong to.
     *
     * Jira and Linear share the PROJ-123 shape, so the format alone cannot tell them
     * apart - the installation says which one it uses.
     */
    private function configuredProvider(): TaskTrackerProvider
    {
        return TaskTrackerProvider::tryFrom((string) config('pulllens.tasks.tracker'))
            ?? TaskTrackerProvider::Jira;
    }
}
