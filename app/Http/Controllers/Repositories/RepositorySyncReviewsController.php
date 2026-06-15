<?php

namespace App\Http\Controllers\Repositories;

use App\Enums\GIT\PullRequestState;
use App\Enums\GIT\ReviewTrigger;
use App\Http\Controllers\Controller;
use App\Jobs\GIT\ReviewPullRequest;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use Illuminate\Http\RedirectResponse;

class RepositorySyncReviewsController extends Controller
{
    public function __invoke(GitRepository $gitRepository): RedirectResponse
    {
        $prs = PullRequest::where('git_repository_id', $gitRepository->id)
            ->whereIn('state', [PullRequestState::Open->value, PullRequestState::Draft->value])
            ->get(['id', 'head_sha']);

        $queued = 0;

        foreach ($prs as $pr) {
            $headSha = (string) ($pr->head_sha ?? '');

            if ($headSha !== '' && PullRequestReview::where('pull_request_id', $pr->id)
                ->where('head_sha', $headSha)
                ->where('posted_to_provider', true)
                ->exists()) {
                continue;
            }

            ReviewPullRequest::dispatch($pr->id, ReviewTrigger::Manual);
            $queued++;
        }

        return back()->with('sync_queued', $queued);
    }
}
