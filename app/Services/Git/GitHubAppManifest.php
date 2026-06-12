<?php

namespace App\Services\Git;

use App\Enums\GIT\GitProvider;

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
            'redirect_url' => route('git-providers.github.manifest.callback', $state),
            'callback_urls' => [
                route('git-providers.callback', GitProvider::Github->value),
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
                'issue_comment',
            ],
        ];
    }

    /**
     * Generate a GitHub-unique app name for this PullLens instance.
     * The suffix is derived from the app key so it is stable across requests
     * but unique per installation.
     */
    private function appName(): string
    {
        $suffix = substr(md5((string) config('app.key', '')), 0, 6);

        return config('app.name', 'PullLens') . ' - ' . $suffix;
    }
}
