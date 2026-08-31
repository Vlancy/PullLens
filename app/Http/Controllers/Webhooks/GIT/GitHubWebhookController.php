<?php

namespace App\Http\Controllers\Webhooks\GIT;

use App\Enums\GIT\GitHubWebhookEvent;
use App\Http\Controllers\Controller;
use App\Repositories\Contracts\GIT\GitRepositoryRepositoryInterface;
use App\Services\Git\Webhooks\GitHubEventDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entry point for GitHub webhook deliveries.
 *
 * Deliberately thin: the HMAC signature is verified by middleware before this runs,
 * and each event's behaviour lives in its own handler behind GitHubEventDispatcher.
 * The controller only decodes the envelope, resolves the repository and delegates.
 *
 * Every outcome returns 200. GitHub retries non-2xx responses, and none of the
 * conditions below (unknown event, untracked repository) would succeed on a retry.
 */
class GitHubWebhookController extends Controller
{
    public function __construct(
        private readonly GitHubEventDispatcher $events,
        private readonly GitRepositoryRepositoryInterface $repositories,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $event = GitHubWebhookEvent::tryFrom((string) $request->header('X-GitHub-Event', ''));

        // `ping` (and any event without a handler) is acknowledged and dropped.
        if ($event === null || ! $this->events->handles($event)) {
            return $this->acknowledge();
        }

        $payload = (array) $request->json()->all();

        $repository = $this->repositories->findByProviderRepoId(
            'github',
            (int) data_get($payload, 'repository.id'),
        );

        if ($repository === null) {
            return $this->acknowledge();
        }

        $this->events->dispatch($event, $repository, $payload);

        return $this->acknowledge();
    }

    /**
     * The single response shape this endpoint ever returns.
     */
    private function acknowledge(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
