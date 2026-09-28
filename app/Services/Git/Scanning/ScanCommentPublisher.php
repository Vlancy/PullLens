<?php

namespace App\Services\Git\Scanning;

use App\Models\GIT\GitAccount;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReviewFinding;
use App\Services\Git\GitHubApiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a scanner's findings to the pull request and answers resolved threads.
 */
class ScanCommentPublisher
{
    /**
     * Inject the API client this class delegates to.
     */
    public function __construct(private readonly GitHubApiClient $api) {}

    /**
     * Post one COMMENT review for the findings not yet posted, plus an inline comment
     * for each of them that has a line.
     *
     * A finding without a line can only be named in the summary, so it counts as
     * posted once that summary is up, and is not listed again on the next push.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $findings
     * @param  callable(Collection<int, PullRequestReviewFinding>): string  $summary
     */
    public function publish(
        GitAccount|string $caller, string $owner, string $repo, PullRequest $pullRequest, string $headSha,
        Collection $findings, callable $summary, string $logPrefix,
    ): void {
        $unposted = $findings->reject(fn (PullRequestReviewFinding $f) => $f->is_posted)->values();

        if ($unposted->isEmpty()) {
            return;
        }

        $reviewPosted = false;

        try {
            $this->api->postPullRequestReview($caller, $owner, $repo, $pullRequest->number, $summary($unposted), 'COMMENT');
            $reviewPosted = true;
        } catch (Throwable $e) {
            Log::warning($logPrefix.'.review_post_failed', ['pull_request_id' => $pullRequest->id, 'error' => $e->getMessage()]);
        }

        foreach ($unposted as $finding) {
            if ($finding->line === null) {
                if ($reviewPosted) {
                    $finding->update(['is_posted' => true]);
                }

                continue;
            }

            try {
                $posted = $this->api->postReviewComment($caller, $owner, $repo, $pullRequest->number, $headSha,
                    $finding->file, (int) $finding->line,
                    "**{$finding->title}**\n\n{$finding->explanation}\n\n{$finding->suggested_fix}");

                $commentId = (int) data_get($posted, 'id');

                if ($commentId > 0) {
                    $finding->update(['is_posted' => true, 'provider_comment_id' => $commentId]);
                }
            } catch (Throwable $e) {
                Log::warning($logPrefix.'.comment_post_failed', [
                    'pull_request_id' => $pullRequest->id, 'file' => $finding->file, 'line' => $finding->line, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Reply in each resolved finding's thread.
     *
     * @param  Collection<int, PullRequestReviewFinding>  $resolved
     */
    public function replyResolved(
        GitAccount|string $caller, string $owner, string $repo, PullRequest $pullRequest,
        Collection $resolved, string $reply, string $logPrefix,
    ): void {
        foreach ($resolved as $finding) {
            if (! $finding->provider_comment_id) {
                continue;
            }

            try {
                $this->api->replyToReviewComment($caller, $owner, $repo, $pullRequest->number, (int) $finding->provider_comment_id, $reply);
            } catch (Throwable $e) {
                Log::warning($logPrefix.'.resolve_reply_failed', ['finding_id' => $finding->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
