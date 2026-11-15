<?php

use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;
use App\Models\Users\User;
use Inertia\Testing\AssertableInertia as Assert;

test('manager can view projects page', function () {
    $project = Project::factory()->create(['name' => 'Alpha']);
    $inProject = GitRepository::factory()->create(['project_id' => $project->id]);
    $free = GitRepository::factory()->create();

    $this->actingAs(User::factory()->manager()->create())
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/projects')
            ->has('projects', 1)
            ->where('projects.0.name', 'Alpha')
            ->where('projects.0.repositories.0.id', $inProject->id)
            ->has('repositories', 2)
            ->where('repositories', fn ($repos) => collect($repos)->firstWhere('id', $free->id)['project_id'] === null)
        );
});

test('member gets 403', function () {
    $this->actingAs(User::factory()->member()->create())
        ->get(route('projects.index'))
        ->assertForbidden();
});

test('store creates a project', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('projects.store'), ['name' => 'Beta', 'description' => 'Client B'])
        ->assertRedirect();

    expect(Project::query()->where('name', 'Beta')->value('description'))->toBe('Client B');
});

test('store validates unique name', function () {
    Project::factory()->create(['name' => 'Alpha']);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('projects.store'), ['name' => 'Alpha'])
        ->assertSessionHasErrors('name');
});

test('update allows keeping own name and renames', function () {
    $project = Project::factory()->create(['name' => 'Alpha']);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('projects.update', $project), ['name' => 'Alpha', 'description' => 'x'])
        ->assertRedirect();

    $this->put(route('projects.update', $project), ['name' => 'Gamma'])->assertRedirect();

    expect($project->fresh()->name)->toBe('Gamma');
});

test('assign sets repositories', function () {
    $project = Project::factory()->create();
    $repos = GitRepository::factory()->count(2)->create();

    $this->actingAs(User::factory()->manager()->create())
        ->put(route('projects.repositories', $project), ['repository_ids' => $repos->pluck('id')->all()])
        ->assertRedirect();

    expect(GitRepository::query()->where('project_id', $project->id)->count())->toBe(2);
});

test('assign rejects unknown repository ids', function () {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->manager()->create())
        ->put(route('projects.repositories', $project), ['repository_ids' => ['0198f0a0-0000-7000-8000-000000000000']])
        ->assertSessionHasErrors('repository_ids.0');
});

test('destroy unassigns repos', function () {
    $project = Project::factory()->create();
    $repo = GitRepository::factory()->create(['project_id' => $project->id]);

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('projects.destroy', $project))
        ->assertRedirect();

    expect(Project::query()->count())->toBe(0)
        ->and($repo->fresh()->project_id)->toBeNull();
});

test('member cannot mutate projects', function () {
    $project = Project::factory()->create();
    $this->actingAs(User::factory()->member()->create());

    $this->post(route('projects.store'), ['name' => 'X'])->assertForbidden();
    $this->delete(route('projects.destroy', $project))->assertForbidden();
});

test('repository settings page shows its project', function () {
    $project = Project::factory()->create(['name' => 'Alpha']);
    $repo = GitRepository::factory()->create(['project_id' => $project->id]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('integrations.repositories.settings.edit', $repo->id))
        ->assertInertia(fn (Assert $page) => $page->where('project', ['id' => $project->id, 'name' => 'Alpha']));
});
