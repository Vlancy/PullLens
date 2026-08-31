<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\CommitStatsBackfiller;
use Illuminate\Http\RedirectResponse;

/**
 * Queues a backfill of missing per-commit line statistics.
 *
 * Commits recorded from a push payload have no additions/deletions until a follow-up
 * API call fills them in; this lets an operator re-run that for anything left at zero.
 */
class SyncCommitStatsController extends Controller
{
    /**
     * Inject the commit stats backfiller this class delegates to.
     */
    public function __construct(private readonly CommitStatsBackfiller $backfiller) {}

    /**
     * Handle the request and redirect back to the caller.
     */
    public function __invoke(): RedirectResponse
    {
        $queued = $this->backfiller->queue();

        return back()->with('status', "Queued {$queued} PR(s) for commit stats sync.");
    }
}
