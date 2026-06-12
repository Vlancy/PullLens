<?php

namespace App\Jobs\GIT;

use App\Enums\GIT\FindingResolutionType;
use App\Models\GIT\GitProviderApp;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReviewFinding;
use App\Services\Git\GitHubApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After a new commit is pushed, resolves findings whose files were changed,
 * replies to the original inline comment thread, and marks them in the database.
 * The subsequent ReviewPullRequest job will verify any remaining issues.
 */
class CheckFindingResolutions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public readonly string $pullRequestId,
        public readonly string $headSha,
    ) {}

    public function handle(GitHubApiClient $api): void
    {
        $pullRequest = PullRequest::with([
            'repository.account',
        ])->findOrFail($this->pullRequestId);

        $repository = $pullRequest->repository;
        $account = $repository->account;
        [$owner, $name] = explode('/', $repository->full_name, 2);

        // Only findings that were actually posted as inline comments can be replied to.
        $findings = PullRequestReviewFinding::where('pull_request_id', $pullRequest->id)
            ->whereNull('resolved_at')
            ->whereNotNull('provider_comment_id')
            ->where('is_posted', true)
            ->get();

        if ($findings->isEmpty()) {
            return;
        }

        try {
            $changedFiles = $api->pullRequestFiles($account, $owner, $name, $pullRequest->number);
        } catch (Throwable $e) {
            Log::warning('finding_resolution.files_fetch_failed', [
                'pull_request_id' => $pullRequest->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Build a set of changed file paths for O(1) lookup.
        $changedPaths = collect($changedFiles)
            ->pluck('filename')
            ->map(fn (string $f) => strtolower($f))
            ->flip();

        if ($changedPaths->isEmpty()) {
            return;
        }

        $app = GitProviderApp::where('provider', 'github')->first();
        $poster = $account;

        if ($app?->private_key && $repository->installation_id) {
            $token = $api->installationToken($app, (int) $repository->installation_id);
            if ($token !== '') {
                $poster = $token;
            }
        }

        $shortSha = substr($this->headSha, 0, 7);

        foreach ($findings as $finding) {
            if (! $changedPaths->has(strtolower($finding->file))) {
                continue;
            }

            // Reply to the original inline comment thread so the fix is acknowledged.
            try {
                $api->replyToReviewComment(
                    $poster,
                    $owner,
                    $name,
                    $pullRequest->number,
                    (int) $finding->provider_comment_id,
                    $this->buildReplyBody($finding, $shortSha),
                );
            } catch (Throwable $e) {
                Log::warning('finding_resolution.reply_failed', [
                    'finding_id' => $finding->id,
                    'comment_id' => $finding->provider_comment_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $finding->update([
                'resolved_at' => now(),
                'resolution_type' => FindingResolutionType::FixSubmitted->value,
            ]);

            Log::info('finding_resolution.resolved', [
                'pull_request_id' => $pullRequest->id,
                'finding_id' => $finding->id,
                'file' => $finding->file,
                'sha' => $shortSha,
            ]);
        }
    }

    private function buildReplyBody(PullRequestReviewFinding $finding, string $shortSha): string
    {
        return implode("\n", [
            "**`{$finding->file}`** was updated in commit `{$shortSha}`.",
            '',
            'This finding is marked as resolved. The next review will verify the fix.',
            '',
            '<!-- pullens -->',
        ]);
    }
}
