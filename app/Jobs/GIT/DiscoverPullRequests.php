<?php

namespace App\Jobs\GIT;

use App\Enums\GIT\PullRequestWebhookAction;
use App\Enums\GIT\ReviewTrigger;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Services\Git\GitHubApiClient;
use App\Services\Git\GitHubCallerResolver;
use App\Services\Git\PullRequestSynchronizer;
use App\Services\Git\Webhooks\ReviewTriggerPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds open pull requests that exist on the provider but were never recorded here.
 *
 * Webhooks are the normal way a pull request reaches PullLens, so anything opened
 * while the hook was down, before the repository was tracked, or while the app was
 * offline is invisible to us and to the manual review sync - that one only walks
 * pull requests already in the database. This pass closes the gap by reading the
 * provider's own list of open pull requests and ingesting whatever is missing.
 */
class DiscoverPullRequests implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A list call plus one detail call per missing pull request.
     *
     * Held under the queue's retry_after: a job still running when the queue
     * decides to retry it would be executed twice at once, and both copies would
     * see the same pull requests as missing.
     */
    public int $timeout = 240;

    public int $tries = 2;

    public int $backoff = 30;

    /**
     * Inject the repository id this class delegates to.
     */
    public function __construct(public readonly string $gitRepositoryId) {}

    /**
     * Only one scan per repository may be queued at a time.
     *
     * Without this, an impatient second click would put two scans of the same
     * repository on the queue, and two workers reading the same provider list
     * would both see the same pull request as missing.
     */
    public function uniqueId(): string
    {
        return $this->gitRepositoryId;
    }

    /**
     * Release the lock after ten minutes even if the job dies without finishing.
     */
    public int $uniqueFor = 600;

    /**
     * Execute the discovery pass.
     */
    public function handle(
        GitHubApiClient $api,
        GitHubCallerResolver $callers,
        PullRequestSynchronizer $synchronizer,
        ReviewTriggerPolicy $policy,
    ): void {
        $repository = GitRepository::with('account')->find($this->gitRepositoryId);

        if ($repository === null) {
            return;
        }

        [$owner, $name] = explode('/', $repository->full_name, 2);

        try {
            $caller = $callers->for($repository);
            $openPullRequests = $caller === null ? [] : $api->openPullRequests($caller, $owner, $name);
        } catch (Throwable $e) {
            // One unreachable repository must not fail the whole sweep, and a retry
            // would only hammer an API that is already refusing us.
            Log::warning('discover_prs.list_failed', [
                'git_repository_id' => $repository->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $known = PullRequest::query()
            ->where('git_repository_id', $repository->id)
            ->pluck('provider_pr_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $discovered = 0;
        $queued = 0;

        foreach ($openPullRequests as $summary) {
            $providerPrId = (int) data_get($summary, 'id');
            $number = (int) data_get($summary, 'number');

            // Skipping what we already hold is what keeps a second click from
            // writing a second event row for a pull request we ingested before.
            if ($providerPrId === 0 || in_array($providerPrId, $known, true)) {
                continue;
            }

            try {
                // The list endpoint omits additions, deletions, changed_files and
                // commits, so ingesting straight from it would record a pull request
                // whose size is zero. The detail call is what makes the row usable.
                $payload = $api->pullRequest($caller, $owner, $name, $number);
            } catch (Throwable $e) {
                Log::warning('discover_prs.detail_failed', [
                    'git_repository_id' => $repository->id,
                    'pr' => $number,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($payload === []) {
                continue;
            }

            $pullRequest = $synchronizer->syncFromPayload($repository, $payload, 'discovered');
            $known[] = $providerPrId;
            $discovered++;

            if ($this->shouldReview($policy, $repository, $pullRequest)) {
                ReviewPullRequest::dispatch($pullRequest->id, ReviewTrigger::Manual);
                $queued++;
            }
        }

        Log::info('discover_prs.completed', [
            'git_repository_id' => $repository->id,
            'open' => count($openPullRequests),
            'discovered' => $discovered,
            'queued' => $queued,
        ]);
    }

    /**
     * Whether a freshly discovered pull request earns a review.
     *
     * Asked of the same policy the webhook uses, as the action GitHub would have
     * sent had we been listening, so a repository that does not review on open or
     * does not track the target branch is treated here exactly as it is there.
     */
    private function shouldReview(
        ReviewTriggerPolicy $policy,
        GitRepository $repository,
        PullRequest $pullRequest,
    ): bool {
        if (! $policy->shouldReview($repository, PullRequestWebhookAction::Opened, $pullRequest->target_branch)) {
            return false;
        }

        // ReviewPullRequest is unique per pull request, which stops two review jobs
        // racing. It says nothing about a review that already finished, so the head
        // commit is checked too - otherwise a later sweep would pay for the same
        // review a second time.
        return ! $policy->hasReviewedCurrentHead($pullRequest);
    }
}
