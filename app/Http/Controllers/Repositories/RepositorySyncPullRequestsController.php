<?php

namespace App\Http\Controllers\Repositories;

use App\Http\Controllers\Controller;
use App\Jobs\GIT\DiscoverPullRequests;
use App\Models\GIT\GitRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sweeps every repository for open pull requests PullLens never recorded.
 *
 * The existing per-repository sync only re-reads pull requests already in the
 * database, so one opened while the webhook was down stays invisible to it. This
 * endpoint goes out to the provider and asks what is actually open.
 */
class RepositorySyncPullRequestsController extends Controller
{
    /**
     * Queue a discovery pass for each repository the operator may manage.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        $repositories = GitRepository::query()
            ->visibleTo($user)
            ->orderBy('full_name')
            ->get()
            // Discovery can queue reviews, which spends AI credit, so a scoped user
            // needs an explicit `manage` grant on the repository - the same bar the
            // per-repository sync applies.
            ->filter(fn (GitRepository $repository): bool => $user->can('manage', $repository));

        // Reviews being switched off does not make a repository's pull requests
        // uninteresting - they still belong on the board. Whether any of them earns
        // a review is decided per pull request by ReviewTriggerPolicy inside the job.
        foreach ($repositories as $repository) {
            DiscoverPullRequests::dispatch($repository->id);
        }

        return back()->with('discovery_queued', $repositories->count());
    }
}
