<?php

use App\Enums\GIT\PullRequestState;
use App\Enums\GIT\ReviewTrigger;
use App\Jobs\GIT\ReviewPullRequest;
use App\Jobs\GIT\SyncFindingReactions;
use App\Jobs\GIT\SyncPullRequestState;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\Users\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pulllens:users-exist', function () {
    $this->line(User::query()->exists() ? 'yes' : 'no');
})->purpose('Check whether PullLens has any users');

Artisan::command('pulllens:sync-open-prs', function () {
    $prs = PullRequest::with('repository')
        ->whereIn('state', [PullRequestState::Open->value, PullRequestState::Draft->value])
        ->get(['id', 'head_sha', 'target_branch', 'git_repository_id']);

    $queued = 0;

    foreach ($prs as $pr) {
        SyncPullRequestState::dispatch($pr->id);

        $repo = $pr->repository;

        if (! $repo || ! $repo->reviews_enabled) {
            continue;
        }

        $tracked = (array) ($repo->tracked_branches ?? []);

        if (! empty($tracked) && ! in_array($pr->target_branch, $tracked, true)) {
            continue;
        }

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

    $this->line("Synced {$prs->count()} PRs, queued {$queued} reviews.");
})->purpose('Sync all open/draft PRs and queue missing reviews');

Schedule::command('telescope:prune --hours=168')->weekly();
Schedule::job(new SyncFindingReactions)->hourly();
Schedule::command('pulllens:sync-open-prs')->hourly();
