<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\AssignTaskFeatureRequest;
use App\Models\GIT\PullRequestTask;
use App\Services\Tasks\Features\FeatureManager;
use Illuminate\Http\RedirectResponse;

/**
 * Moves a task to another feature by hand.
 */
class TaskFeatureAssignmentController extends Controller
{
    /**
     * File the task under the chosen or newly named feature, or under none.
     */
    public function __invoke(AssignTaskFeatureRequest $request, PullRequestTask $pullRequestTask, FeatureManager $features): RedirectResponse
    {
        $features->assignTask($pullRequestTask, $request->validated('feature_id'), $request->validated('new_name'));

        return back()->with('status', 'Task feature updated.');
    }
}
