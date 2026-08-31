<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Findings\BulkResolveFindingsRequest;
use App\Services\Findings\FindingResolutionService;
use Illuminate\Http\RedirectResponse;

/**
 * Closes many review findings in one action, e.g. after a sweep through the backlog.
 */
class FindingBulkResolveController extends Controller
{
    public function __construct(private readonly FindingResolutionService $resolutions) {}

    public function __invoke(BulkResolveFindingsRequest $request): RedirectResponse
    {
        $resolved = $this->resolutions->resolveMany(
            $request->findingIds(),
            $request->resolutionType(),
        );

        return back()->with('status', "Resolved {$resolved} finding(s).");
    }
}
