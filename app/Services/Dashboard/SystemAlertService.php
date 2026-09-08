<?php

namespace App\Services\Dashboard;

use App\Enums\Users\UserPermission;
use App\Jobs\GIT\ReviewPullRequest;
use App\Models\AI\AiProvider;
use App\Models\GIT\GitProviderApp;
use App\Models\System\FailedJob;
use App\Models\Users\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Surfaces misconfiguration and outage conditions on the dashboard.
 *
 * Each check answers one question: is the integration configured at all, and if it
 * is, has it been failing recently? Reporting "not configured" and "failing" as
 * distinct alerts matters - the fix is different for each.
 *
 * An alert is only raised for a user who can actually act on it: every alert links to
 * a settings page, and showing someone a call to action they will be refused at is
 * worse than showing them nothing.
 */
class SystemAlertService
{
    /** How far back a failure still counts as "recent". */
    private const LOOKBACK_HOURS = 2;

    /**
     * Exception fragments indicating the AI provider rejected us or throttled us.
     *
     * @var array<int, string>
     */
    private const AI_FAILURE_SIGNATURES = [
        'rate limit', '429', 'Incorrect API key', 'Invalid API key', 'Authentication',
    ];

    /**
     * Exception fragments indicating the git provider rejected our credentials.
     *
     * @var array<int, string>
     */
    private const GIT_FAILURE_SIGNATURES = ['Bad credentials', 'status code 401'];

    /**
     * Execute the system alert service job.
     *
     * @return array<int, array{type: string, message: string, action_url: string}>
     */
    public function handle(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_filter([
            $user->hasPermission(UserPermission::ManageAiProviders) ? $this->aiProviderAlert() : null,
            $user->hasPermission(UserPermission::ManageIntegrations) ? $this->gitProviderAlert() : null,
        ]));
    }

    /**
     * The alert to raise about the AI provider, if any.
     *
     * @return array{type: string, message: string, action_url: string}|null
     */
    private function aiProviderAlert(): ?array
    {
        if (! AiProvider::query()->where('is_enabled', true)->exists()) {
            return $this->alert(
                'ai_provider',
                'No AI provider is configured or enabled. PR reviews will not run.',
                route('ai-providers.edit'),
            );
        }

        $failing = $this->recentFailures()
            ->forJob(ReviewPullRequest::class)
            ->exceptionContainsAny(self::AI_FAILURE_SIGNATURES)
            ->exists();

        return $failing
            ? $this->alert(
                'ai_provider',
                'AI provider is returning errors. Recent PR reviews have failed - check your API key or quota.',
                route('ai-providers.edit'),
            )
            : null;
    }

    /**
     * The alert to raise about the git provider, if any.
     *
     * @return array{type: string, message: string, action_url: string}|null
     */
    private function gitProviderAlert(): ?array
    {
        $configured = GitProviderApp::query()
            ->whereNotNull('private_key')
            ->whereNotNull('app_id')
            ->exists();

        if (! $configured) {
            return $this->alert(
                'git_provider',
                'GitHub App is not configured. Repository syncing and reviews will not work.',
                route('integrations.edit'),
            );
        }

        $failing = $this->recentFailures()
            ->exceptionContainsAny(self::GIT_FAILURE_SIGNATURES)
            ->exists();

        return $failing
            ? $this->alert(
                'git_provider',
                'GitHub authentication is failing. Background jobs are returning 401 - reconnect your GitHub account.',
                route('integrations.edit'),
            )
            : null;
    }

    /**
     * Failed jobs inside the lookback window.
     *
     * @return Builder<FailedJob>
     */
    private function recentFailures(): Builder
    {
        return FailedJob::query()->failedSince(now()->subHours(self::LOOKBACK_HOURS));
    }

    /**
     * Shape one dashboard alert.
     *
     * @return array{type: string, message: string, action_url: string}
     */
    private function alert(string $type, string $message, string $actionUrl): array
    {
        return ['type' => $type, 'message' => $message, 'action_url' => $actionUrl];
    }
}
