<?php

namespace App\Services\Git\Webhooks;

use App\Enums\GIT\GitHubWebhookEvent;
use App\Models\GIT\GitRepository;
use App\Services\Git\Webhooks\Contracts\GitHubEventHandler;

/**
 * Routes a verified webhook delivery to the handler registered for its event.
 *
 * Handlers are injected as a list (see WebhookServiceProvider), so this class
 * depends on the GitHubEventHandler abstraction rather than on any concrete
 * handler - new events are supported by registering another implementation.
 */
class GitHubEventDispatcher
{
    /** @var array<string, GitHubEventHandler> */
    private array $handlers = [];

    /**
     * Create the instance.
     *
     * @param  iterable<int, GitHubEventHandler>  $handlers
     */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->supports()->value] = $handler;
        }
    }

    /**
     * Whether any handler claims this event.
     */
    public function handles(GitHubWebhookEvent $event): bool
    {
        return isset($this->handlers[$event->value]);
    }

    /**
     * Dispatch the delivery. Unknown events are a no-op, not an error: GitHub sends
     * a wide set of events for an installation and we acknowledge them all.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(GitHubWebhookEvent $event, GitRepository $repository, array $payload): void
    {
        ($this->handlers[$event->value] ?? null)?->handle($repository, $payload);
    }
}
