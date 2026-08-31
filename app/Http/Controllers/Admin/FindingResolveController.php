<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Findings\ResolveFindingRequest;
use App\Models\GIT\PullRequestReviewFinding;
use App\Services\Findings\FindingResolutionService;
use Illuminate\Http\RedirectResponse;

/**
 * Closes a single review finding with a stated resolution reason.
 */
class FindingResolveController extends Controller
{
    /**
     * Inject the finding resolution service this class delegates to.
     */
    public function __construct(private readonly FindingResolutionService $resolutions) {}

    /**
     * Handle the request and redirect back to the caller.
     */
    public function __invoke(ResolveFindingRequest $request, PullRequestReviewFinding $finding): RedirectResponse
    {
        // The permission says "may resolve findings"; the policy says "may touch this
        // repository". A scoped user needs both.
        $this->authorize('resolveFindings', $finding->repository);

        $resolved = $this->resolutions->resolve($finding, $request->resolutionType());

        return back()->with(
            'status',
            $resolved ? 'Finding resolved.' : 'Finding was already resolved.',
        );
    }
}
