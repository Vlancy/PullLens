<?php

namespace App\Http\Controllers\Settings\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\Projects\FeatureGroupingRequest;
use App\Services\Tasks\Features\FeatureBackfillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class FeatureGroupingController extends Controller
{
    /**
     * How many tasks grouping would cover, and the progress of a running job.
     */
    public function preview(FeatureGroupingRequest $request, FeatureBackfillService $backfill): JsonResponse
    {
        return response()->json($backfill->preview($request->scope()));
    }

    /**
     * Queue grouping for the scope's ungrouped tasks.
     */
    public function start(FeatureGroupingRequest $request, FeatureBackfillService $backfill): RedirectResponse
    {
        $backfill->start($request->scope());

        return back()->with('status', 'Grouping existing tasks started.');
    }
}
