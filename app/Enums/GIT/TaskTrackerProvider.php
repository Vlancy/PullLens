<?php

namespace App\Enums\GIT;

/**
 * External issue trackers a task can be tied to.
 *
 * PullLens does not integrate with any of these yet — it only detects references
 * that developers already put in branch names, PR titles and descriptions. Storing
 * the provider alongside the key now means a later Jira (or Linear) integration has
 * somewhere to attach without a migration of historical rows.
 */
enum TaskTrackerProvider: string implements \JsonSerializable
{
    case Jira = 'jira';
    case Linear = 'linear';
    case GitHub = 'github';
    case AzureDevOps = 'azure_devops';

    public function label(): string
    {
        return match ($this) {
            self::Jira => 'Jira',
            self::Linear => 'Linear',
            self::GitHub => 'GitHub Issues',
            self::AzureDevOps => 'Azure DevOps',
        };
    }

    /**
     * Build a browsable URL for an issue key, when the provider's base URL is known.
     *
     * Returns null rather than guessing: a wrong link is worse than no link.
     */
    public function urlFor(string $key, ?string $baseUrl): ?string
    {
        if (blank($baseUrl)) {
            return null;
        }

        $base = rtrim($baseUrl, '/');

        return match ($this) {
            self::Jira => "{$base}/browse/{$key}",
            self::Linear => "{$base}/issue/{$key}",
            self::GitHub => "{$base}/issues/".ltrim($key, '#'),
            self::AzureDevOps => "{$base}/_workitems/edit/".ltrim($key, '#'),
        };
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
