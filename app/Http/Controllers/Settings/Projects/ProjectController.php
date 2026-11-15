<?php

namespace App\Http\Controllers\Settings\Projects;

use App\Enums\GIT\RepositoryTagKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\Projects\StoreProjectRequest;
use App\Http\Requests\Settings\Projects\UpdateProjectRequest;
use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;
use App\Services\Projects\ProjectService;
use App\Services\Tasks\Features\FeatureBackfillService;
use App\Support\Tasks\FeatureScope;
use Closure;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * List projects with their repositories, plus every connected repository for assignment
     * and the feature grouping status of each project and unassigned repository.
     */
    public function index(FeatureBackfillService $backfill): Response
    {
        $projects = Project::query()
            ->with(['repositories' => fn ($query) => $query->orderBy('full_name'), 'repositories.tags' => $this->activeTags()])
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'grouping' => $backfill->preview(new FeatureScope($project->id, null)),
                'repositories' => $project->repositories->map(fn (GitRepository $repository) => [
                    'id' => $repository->id,
                    'full_name' => $repository->full_name,
                    'tags' => $this->tagPayload($repository),
                    'profile_refreshed_at' => $repository->profile_refreshed_at?->toIso8601String(),
                ])->values(),
            ])
            ->values();

        $repositories = GitRepository::query()
            ->with(['tags' => $this->activeTags()])
            ->orderBy('full_name')
            ->get();

        $unassigned = $repositories->whereNull('project_id')
            ->map(fn (GitRepository $repository) => [
                'id' => $repository->id,
                'full_name' => $repository->full_name,
                'tags' => $this->tagPayload($repository),
                'profile_refreshed_at' => $repository->profile_refreshed_at?->toIso8601String(),
                'grouping' => $backfill->preview(new FeatureScope(null, $repository->id)),
            ])
            ->values();

        return Inertia::render('settings/projects', [
            'projects' => $projects,
            'repositories' => $repositories->map(fn (GitRepository $repository) => [
                'id' => $repository->id,
                'full_name' => $repository->full_name,
                'project_id' => $repository->project_id,
            ])->values(),
            'tag_kinds' => RepositoryTagKind::options(),
            'unassigned_repositories' => $unassigned,
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

    /**
     * Constraint for eager loading only the tags that have not been dismissed.
     */
    private function activeTags(): Closure
    {
        return fn ($query) => $query->where('dismissed', false)->orderBy('name');
    }

    /**
     * Serialise a repository's eager loaded profile tags for the page.
     *
     * @return array<int, array{id: string, name: string, kind: mixed, source: mixed}>
     */
    private function tagPayload(GitRepository $repository): array
    {
        return $repository->tags->map(fn ($tag) => [
            'id' => $tag->id,
            'name' => $tag->name,
            'kind' => $tag->kind,
            'source' => $tag->source,
        ])->values()->all();
    }
}
