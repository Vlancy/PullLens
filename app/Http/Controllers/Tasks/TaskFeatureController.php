<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\MergeTaskFeatureRequest;
use App\Http\Requests\Tasks\UpdateTaskFeatureRequest;
use App\Models\GIT\TaskFeature;
use App\Services\Tasks\Features\FeatureManager;
use Illuminate\Http\RedirectResponse;

/**
 * Corrections a person makes to a feature: renaming it and merging it into another.
 */
class TaskFeatureController extends Controller
{
    /**
     * Inject the feature manager this controller delegates to.
     *
     * @param  FeatureManager  $features  Applies the corrections.
     */
    public function __construct(private readonly FeatureManager $features) {}

    /**
     * Rename a feature and update its description.
     */
    public function update(UpdateTaskFeatureRequest $request, TaskFeature $taskFeature): RedirectResponse
    {
        $this->features->rename($taskFeature, $request->validated('name'), $request->validated('description'));

        return back()->with('status', 'Feature updated.');
    }

    /**
     * Merge a feature into another in the same scope.
     */
    public function merge(MergeTaskFeatureRequest $request, TaskFeature $taskFeature): RedirectResponse
    {
        $this->features->merge($taskFeature, $request->target());

        return back()->with('status', 'Features merged.');
    }
}
