<?php

namespace App\Services\Git;

use App\Enums\GIT\GitProvider;
use Illuminate\Support\Str;

class GitHubAppManifest
{
    /**
     * Build the GitHub App Manifest payload used for near-one-click setup.
     *
     * @return array<string, mixed>
     */
    public function make(string $state): array
    {
        $name = $this->appName();

        return [
            'name' => $name,
            'url' => config('app.url'),
            'hook_attributes' => [
                'url' => route('webhooks.github'),
                'active' => true,
            ],
            'redirect_url' => route('integrations.github.manifest.callback', $state),
            'callback_urls' => [
                route('integrations.callback', GitProvider::Github->value),
            ],
            'public' => true,
            'request_oauth_on_install' => false,
            'default_permissions' => [
                'metadata' => 'read',
                'contents' => 'read',
                'pull_requests' => 'write',
                'issues' => 'write',
                'checks' => 'write',
            ],
            'default_events' => [
                'pull_request',
                'pull_request_review',
                'pull_request_review_comment',
            ],
        ];
    }

    /**
     * Generate a GitHub-compatible app name scoped to this PullLens instance.
     */
    private function appName(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: Str::slug((string) config('app.name'));

        return Str::limit(config('app.name').' - '.$host, 34, '');
    }
}
