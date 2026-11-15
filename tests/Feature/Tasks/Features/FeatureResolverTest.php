<?php

use App\Enums\GIT\AttributionSource;
use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;
use App\Models\GIT\TaskFeature;
use App\Services\Tasks\Features\FeatureResolver;
use App\Support\Tasks\FeatureScope;

/**
 * Build a feature row in the given scope.
 */
function frFeature(FeatureScope $scope, string $slug, array $attributes = []): TaskFeature
{
    return TaskFeature::query()->create([
        'project_id' => $scope->projectId,
        'git_repository_id' => $scope->repositoryId,
        'scope_key' => $scope->key(),
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'source' => AttributionSource::Ai,
        ...$attributes,
    ]);
}

/**
 * Shorthand for the resolver input shape.
 */
function frInput(?string $existing = null, ?string $name = null, ?string $description = null): array
{
    return ['existing_slug' => $existing, 'new_name' => $name, 'new_description' => $description];
}

test('existing slug resolves', function () {
    $scope = FeatureScope::forRepository(GitRepository::factory()->create());
    $feature = frFeature($scope, 'auth-system');

    $resolved = app(FeatureResolver::class)->resolve($scope, frInput('auth-system'));

    expect($resolved->id)->toBe($feature->id);
});

test('merged feature resolves to target', function () {
    $scope = FeatureScope::forRepository(GitRepository::factory()->create());
    $c = frFeature($scope, 'c');
    $b = frFeature($scope, 'b', ['merged_into_id' => $c->id]);
    frFeature($scope, 'a', ['merged_into_id' => $b->id]);

    $resolved = app(FeatureResolver::class)->resolve($scope, frInput('a'));

    expect($resolved->id)->toBe($c->id);
});

test('unknown existing slug falls back to creating from it', function () {
    $scope = FeatureScope::forRepository(GitRepository::factory()->create());

    $resolved = app(FeatureResolver::class)->resolve($scope, frInput('billing-portal'));

    expect($resolved->name)->toBe('Billing portal')
        ->and($resolved->slug)->toBe('billing-portal')
        ->and($resolved->source)->toBe(AttributionSource::Ai)
        ->and($resolved->scope_key)->toBe($scope->key());
});

test('name variants resolve to one feature', function () {
    $scope = FeatureScope::forRepository(GitRepository::factory()->create());
    $resolver = app(FeatureResolver::class);

    $ids = collect(['Auth System', ' auth-system ', 'Auth system!'])
        ->map(fn ($name) => $resolver->resolve($scope, frInput(null, $name))->id)
        ->unique();

    expect($ids)->toHaveCount(1)
        ->and(TaskFeature::query()->count())->toBe(1);
});

test('same name in different scopes are different features', function () {
    $a = FeatureScope::forRepository(GitRepository::factory()->create());
    $b = FeatureScope::forRepository(GitRepository::factory()->create());
    $resolver = app(FeatureResolver::class);

    $first = $resolver->resolve($a, frInput(null, 'Auth'));
    $second = $resolver->resolve($b, frInput(null, 'Auth'));

    expect($first->id)->not->toBe($second->id);
});

test('empty input returns null', function () {
    $scope = FeatureScope::forRepository(GitRepository::factory()->create());
    $resolver = app(FeatureResolver::class);

    expect($resolver->resolve($scope, frInput()))->toBeNull()
        ->and($resolver->resolve($scope, frInput('  ', '  ')))->toBeNull();
});

test('scope uses project when repo has one', function () {
    $project = Project::factory()->create();
    $repository = GitRepository::factory()->create(['project_id' => $project->id]);
    $plain = GitRepository::factory()->create();

    $scope = FeatureScope::forRepository($repository);
    $plainScope = FeatureScope::forRepository($plain);

    expect($scope->projectId)->toBe($project->id)
        ->and($scope->repositoryId)->toBeNull()
        ->and($scope->key())->toBe("project:{$project->id}")
        ->and($plainScope->projectId)->toBeNull()
        ->and($plainScope->key())->toBe("repository:{$plain->id}");

    $feature = app(FeatureResolver::class)->resolve($scope, frInput(null, 'Search'));

    expect($feature->project_id)->toBe($project->id)
        ->and($feature->git_repository_id)->toBeNull();
});
