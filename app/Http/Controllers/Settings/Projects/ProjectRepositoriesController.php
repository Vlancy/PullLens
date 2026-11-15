<?php

namespace App\Http\Controllers\Settings\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\Projects\AssignProjectRepositoriesRequest;
use App\Models\GIT\Project;
use App\Services\Projects\ProjectService;
use Illuminate\Http\RedirectResponse;

class ProjectRepositoriesController extends Controller
{
    /**
     * Set exactly which repositories belong to the project.
     */
    public function __invoke(AssignProjectRepositoriesRequest $request, Project $project, ProjectService $projects): RedirectResponse
    {
        $projects->assignRepositories($project, $request->validated('repository_ids'));

        return back()->with('status', 'Project repositories updated.');
    }
}
