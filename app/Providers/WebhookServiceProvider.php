<?php

namespace App\Providers;

use App\Services\Git\Webhooks\Contracts\GitHubEventHandler;
use App\Services\Git\Webhooks\GitHubEventDispatcher;
use App\Services\Git\Webhooks\Handlers\IssueCommentEventHandler;
use App\Services\Git\Webhooks\Handlers\PullRequestEventHandler;
use App\Services\Git\Webhooks\Handlers\PushEventHandler;
use App\Services\Git\Webhooks\Handlers\ReviewCommentEventHandler;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the webhook event handlers into the dispatcher.
 *
 * Registering the list here — rather than inside the dispatcher — is what keeps the
 * dispatcher closed for modification: supporting a new GitHub event means writing a
 * handler and adding one line to HANDLERS.
 */
class WebhookServiceProvider extends ServiceProvider
{
    /**
     * Handler implementations, in no particular order; the dispatcher indexes them by
     * the event each one declares it supports.
     *
     * @var array<int, class-string<GitHubEventHandler>>
     */
    private const HANDLERS = [
        PushEventHandler::class,
        PullRequestEventHandler::class,
        ReviewCommentEventHandler::class,
        IssueCommentEventHandler::class,
    ];

    /**
     * Bind the event dispatcher with every handler registered against it.
     */
    public function register(): void
    {
        $this->app->singleton(GitHubEventDispatcher::class, function ($app): GitHubEventDispatcher {
            return new GitHubEventDispatcher(
                array_map(static fn (string $handler) => $app->make($handler), self::HANDLERS),
            );
        });
    }

    /**
     * The container bindings this provider defers.
     *
     * @return array<int, class-string>
     */
    public function provides(): array
    {
        return [GitHubEventDispatcher::class];
    }
}
