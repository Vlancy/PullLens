<?php

use App\Enums\GIT\PullRequestState;
use App\Enums\GIT\ReviewTrigger;
use App\Jobs\GIT\ReviewPullRequest;
use App\Jobs\GIT\SyncFindingReactions;
use App\Jobs\GIT\SyncPullRequestState;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReview;
use App\Models\Users\User;
use App\Services\Git\SecretScanning\SecretScanWorkspaceSweeper;
use App\Services\Git\VulnerabilityScanning\TrivyDatabase;
use App\Services\Git\VulnerabilityScanning\TrivyFailed;
use App\Services\Git\VulnerabilityScanning\TrivyRunner;
use App\Services\Git\VulnerabilityScanning\TrivyScanWorkspaceSweeper;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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

Artisan::command('pulllens:sweep-secret-scans', function (SecretScanWorkspaceSweeper $secrets, TrivyScanWorkspaceSweeper $vulnerabilities) {
    $removed = $secrets->sweep() + $vulnerabilities->sweep();

    $this->line("Removed {$removed} leftover scan workspaces.");
})->purpose('Delete leftover secret and vulnerability scan workspaces a killed or crashed scan left behind');

Artisan::command('pulllens:update-trivy-db', function (TrivyRunner $runner, TrivyDatabase $database) {
    if (! $runner->isAvailable()) {
        $this->warn('Trivy is not installed, so there is no vulnerability database to update.');

        return 0;
    }

    try {
        $database->refresh();
    } catch (TrivyFailed $e) {
        Log::warning('vulnerability_scan.database_refresh_failed', ['error' => $e->getMessage()]);
        $this->error('Could not update the vulnerability database: '.$e->getMessage());

        return 1;
    }

    $this->line('Vulnerability database updated ('.($database->updatedAt()?->toIso8601String() ?? 'build time unknown').').');

    return 0;
})->purpose('Download the latest Trivy vulnerability database for pull request scans');

Schedule::command('telescope:prune --hours=168')->weekly();
Schedule::job(new SyncFindingReactions)->hourly();
Schedule::command('pulllens:sync-open-prs')->hourly();
Schedule::command('pulllens:sweep-secret-scans')->hourly();
Schedule::command('pulllens:update-trivy-db')->everySixHours()->withoutOverlapping();
