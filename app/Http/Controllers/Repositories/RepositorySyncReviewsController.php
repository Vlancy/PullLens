<?php

namespace App\Http\Controllers\Repositories;

use App\Http\Controllers\Controller;
use App\Models\GIT\GitRepository;
use App\Services\Repositories\PullRequestReviewQueuer;
use Illuminate\Http\RedirectResponse;

/**
 * Manually re-syncs a repository's open pull requests and queues any missing reviews.
 */
class RepositorySyncReviewsController extends Controller
{
    /**
     * Inject the pull request review queuer this class delegates to.
     */
    public function __construct(private readonly PullRequestReviewQueuer $queuer) {}

    /**
     * Handle the request and redirect back to the caller.
     */
    public function __invoke(GitRepository $gitRepository): RedirectResponse
    {
        // Queueing reviews spends AI credit against this repository, so a scoped user
        // needs an explicit `manage` grant on it — a `view` grant is not enough.
        $this->authorize('manage', $gitRepository);

        return back()->with('sync_queued', $this->queuer->queueFor($gitRepository));
    }
}
