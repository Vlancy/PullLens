<?php

namespace App\Services\Git\Webhooks\Contracts;

use App\Enums\GIT\GitHubWebhookEvent;
use App\Models\GIT\GitRepository;

/**
 * Strategy contract for processing one kind of GitHub webhook event.
 *
 * Adding support for a new event means adding a handler and registering it - no
 * existing class changes (Open/Closed). The dispatcher selects the implementation
 * by {@see supports()}, so the controller never branches on event names.
 */
interface GitHubEventHandler
{
    /**
     * The event this handler is responsible for.
     */
    public function supports(): GitHubWebhookEvent;

    /**
     * Process the delivery for an already-resolved, tracked repository.
     *
     * Implementations must be side-effect safe under duplicate delivery: GitHub
     * retries and delivers from multiple hosts, so the same payload can arrive twice.
     *
     * @param  array<string, mixed>  $payload  The decoded webhook body.
     */
    public function handle(GitRepository $repository, array $payload): void;
}
