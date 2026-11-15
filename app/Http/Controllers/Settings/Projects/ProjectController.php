<?php

namespace App\Http\Controllers\Settings\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\Projects\StoreProjectRequest;
use App\Http\Requests\Settings\Projects\UpdateProjectRequest;
use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;
use App\Services\Projects\ProjectService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * List projects with their repositories, plus every connected repository for assignment.
     */
    public function index(): Response
    {
        $projects = Project::query()
            ->with(['repositories' => fn ($query) => $query->orderBy('full_name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'repositories' => $project->repositories->map(fn (GitRepository $repository) => [
                    'id' => $repository->id,
                    'full_name' => $repository->full_name,
                ])->values(),
            ])
            ->values();

        $repositories = GitRepository::query()
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'project_id']);

        return Inertia::render('settings/projects', [
            'projects' => $projects,
            'repositories' => $repositories,
            'status' => session('status'),
        ]);
    }

    /**
     * Create a project.
     */
    public function store(StoreProjectRequest $request, ProjectService $projects): RedirectResponse
    {
        $projects->create($request->validated());

        return back()->with('status', 'Project created.');
    }

    /**
     * Update a project's name and description.
     */
    public function update(UpdateProjectRequest $request, Project $project, ProjectService $projects): RedirectResponse
    {
        $projects->update($project, $request->validated());

        return back()->with('status', 'Project saved.');
    }

    /**
     * Delete a project and unassign its repositories.
     */
    public function destroy(Project $project, ProjectService $projects): RedirectResponse
    {
        $projects->delete($project);

        return back()->with('status', 'Project deleted.');
    }
}
