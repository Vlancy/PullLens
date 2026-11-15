<?php

use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;
use App\Repositories\Contracts\GIT\ProjectRepositoryInterface;
use App\Services\Projects\ProjectService;
use App\Services\Tasks\Features\FeatureManager;
use App\Services\Tasks\Features\FeatureScopeMigrator;
use Illuminate\Database\QueryException;

it('creates a project with unique name', function () {
    $project = app(ProjectService::class)->create(['name' => 'Alpha', 'description' => null]);

    expect($project->exists)->toBeTrue()->and($project->name)->toBe('Alpha');

    expect(fn () => app(ProjectService::class)->create(['name' => 'Alpha']))
        ->toThrow(QueryException::class);
});

it('assigning repositories sets exactly the given set', function () {
    $project = Project::factory()->create();
    [$a, $b, $c] = GitRepository::factory()->count(3)->create();
    $service = app(ProjectService::class);

    $service->assignRepositories($project, [$a->id, $b->id]);
    $service->assignRepositories($project, [$b->id, $c->id]);

    expect($a->fresh()->project_id)->toBeNull()
        ->and($b->fresh()->project_id)->toBe($project->id)
        ->and($c->fresh()->project_id)->toBe($project->id);
});

it('a repository moved from project X to Y belongs only to Y', function () {
    [$x, $y] = Project::factory()->count(2)->create();
    $repo = GitRepository::factory()->create();
    $service = app(ProjectService::class);

    $service->assignRepositories($x, [$repo->id]);
    $service->assignRepositories($y, [$repo->id]);

    expect($repo->fresh()->project_id)->toBe($y->id)
        ->and($x->repositories()->count())->toBe(0)
        ->and($y->repositories()->count())->toBe(1);
});

it('deleting a project unassigns its repositories', function () {
    $project = Project::factory()->create();
    $repo = GitRepository::factory()->create(['project_id' => $project->id]);

    app(ProjectService::class)->delete($project);

    expect(Project::query()->count())->toBe(0)
        ->and($repo->fresh())->not->toBeNull()
        ->and($repo->fresh()->project_id)->toBeNull();
});

it('assignment calls migrator joined and left, left before joined when moving', function () {
    [$x, $y] = Project::factory()->count(2)->create();
    $repo = GitRepository::factory()->create(['project_id' => $x->id]);

    $order = [];
    $migrator = Mockery::mock(FeatureScopeMigrator::class);
    $migrator->shouldReceive('repositoryLeft')->once()
        ->withArgs(fn ($r, $p) => $r->is($repo) && $p->is($x))
        ->andReturnUsing(function () use (&$order) {
            $order[] = 'left';
        });
    $migrator->shouldReceive('repositoryJoined')->once()
        ->withArgs(fn ($r, $p) => $r->is($repo) && $p->is($y))
        ->andReturnUsing(function () use (&$order) {
            $order[] = 'joined';
        });

    (new ProjectService(app(ProjectRepositoryInterface::class), $migrator, app(FeatureManager::class)))
        ->assignRepositories($y, [$repo->id]);

    expect($order)->toBe(['left', 'joined']);
});

it('removing a repository and deleting a project call the migrator left hook', function () {
    $project = Project::factory()->create();
    [$a, $b] = GitRepository::factory()->count(2)->create(['project_id' => $project->id]);

    $migrator = Mockery::mock(FeatureScopeMigrator::class);
    $migrator->shouldReceive('repositoryLeft')->once()->withArgs(fn ($r, $p) => $r->is($a) && $p->is($project));
    $service = new ProjectService(app(ProjectRepositoryInterface::class), $migrator, app(FeatureManager::class));
    $service->assignRepositories($project, [$b->id]);

    $migrator2 = Mockery::mock(FeatureScopeMigrator::class);
    $migrator2->shouldReceive('repositoryLeft')->once()->withArgs(fn ($r) => $r->is($b));
    (new ProjectService(app(ProjectRepositoryInterface::class), $migrator2, app(FeatureManager::class)))->delete($project);
});
