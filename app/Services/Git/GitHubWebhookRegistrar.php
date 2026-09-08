<?php

namespace App\Services\Git;

use App\Models\GIT\GitProviderApp;
use App\Models\GIT\GitRepository;
use Throwable;

/**
 * Registers or updates a per-repository webhook on GitHub for OAuth-only connections.
 *
 * GitHub App repositories receive events through the app-level webhook that is
 * configured automatically via the manifest (hook_attributes.url). Those repos
 * have an installation_id set and do not need a separate per-repo hook.
 *
 * OAuth-only repos (installation_id = null) have no app-level coverage. This
 * registrar creates a repo webhook for them using the same App webhook_secret
 * so the existing signature verifier works without changes.
 */
class GitHubWebhookRegistrar
{
    /**
     * Inject the git hub api client this class delegates to.
     */
    public function __construct(private readonly GitHubApiClient $api) {}

    /**
     * Ensure a webhook exists and is current for the given repository.
     * Silently does nothing for App-installation repos or when no secret is configured.
     */
    public function ensureWebhook(GitRepository $repository): void
    {
        // GitHub App installations are covered by the app-level webhook - skip.
        if ($repository->installation_id !== null) {
            return;
        }

        $app = GitProviderApp::where('provider', 'github')->first();

        // Without a webhook_secret we cannot verify incoming payloads; skip registration.
        if (! $app?->webhook_secret) {
            return;
        }

        $account = $repository->account;

        if (! $account) {
            return;
        }

        [$owner, $name] = explode('/', $repository->full_name, 2);
        $webhookUrl = route('webhooks.github');
        $secret = $app->webhook_secret;

        try {
            if ($repository->webhook_hook_id) {
                $this->updateExisting($repository, $account, $owner, $name, $webhookUrl, $secret);
            } else {
                $this->createOrAdopt($repository, $account, $owner, $name, $webhookUrl, $secret);
            }
        } catch (Throwable) {
            // Webhook registration is best-effort. If the token lacks admin scope,
            // the repo owner can add the webhook manually from the integrations settings page.
        }
    }

    /**
     * Remove the per-repo webhook when a repository is untracked.
     */
    public function removeWebhook(GitRepository $repository): void
    {
        if (! $repository->webhook_hook_id || $repository->installation_id !== null) {
            return;
        }

        $account = $repository->account;

        if (! $account) {
            return;
        }

        [$owner, $name] = explode('/', $repository->full_name, 2);

        try {
            $this->api->deleteRepoWebhook($account, $owner, $name, $repository->webhook_hook_id);
            $repository->forceFill(['webhook_hook_id' => null])->save();
        } catch (Throwable) {
            // Best-effort cleanup.
        }
    }

    /**
     * Update an already-registered webhook to match the current URL and events.
     */
    private function updateExisting(
        GitRepository $repository,
        $account,
        string $owner,
        string $name,
        string $webhookUrl,
        string $secret,
    ): void {
        $this->api->updateRepoWebhook($account, $owner, $name, $repository->webhook_hook_id, $webhookUrl, $secret);
    }

    /**
     * Create a new webhook, or adopt an existing one that already points to our URL.
     */
    private function createOrAdopt(
        GitRepository $repository,
        $account,
        string $owner,
        string $name,
        string $webhookUrl,
        string $secret,
    ): void {
        // Check for an existing hook pointing at our URL to avoid duplicates.
        $existing = collect($this->api->repoWebhooks($account, $owner, $name))
            ->first(fn (array $hook) => data_get($hook, 'config.url') === $webhookUrl);

        if ($existing) {
            $hookId = (int) data_get($existing, 'id');
            $this->api->updateRepoWebhook($account, $owner, $name, $hookId, $webhookUrl, $secret);
            $repository->forceFill(['webhook_hook_id' => $hookId])->save();

            return;
        }

        $created = $this->api->createRepoWebhook($account, $owner, $name, $webhookUrl, $secret);
        $repository->forceFill(['webhook_hook_id' => (int) data_get($created, 'id')])->save();
    }
}
