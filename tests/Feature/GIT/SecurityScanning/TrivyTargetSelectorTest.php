<?php

use App\Services\Git\VulnerabilityScanning\TrivyTargetSelector;

/** Maps selected TrivyTarget objects to plain [path, basePath, added] tuples for assertions. */
function selected(array $files): array
{
    return array_map(fn ($t) => [$t->path, $t->basePath, $t->added], app(TrivyTargetSelector::class)->select($files));
}

it('selects the lockfiles and infrastructure files trivy can scan', function (string $path) {
    expect(selected([['filename' => $path, 'status' => 'modified']]))->toBe([[$path, $path, false]]);
})->with([
    'package-lock.json', 'npm-shrinkwrap.json', 'web/yarn.lock', 'pnpm-lock.yaml', 'bun.lock', 'composer.lock',
    'go.mod', 'go.sum', 'Cargo.lock', 'Pipfile.lock', 'poetry.lock', 'uv.lock', 'requirements.txt', 'requirements-dev.txt',
    'Gemfile.lock', 'pom.xml', 'gradle.lockfile', 'app/buildscript-gradle.lockfile', 'packages.lock.json', 'App.deps.json',
    'mix.lock', 'pubspec.lock', 'Podfile.lock', 'Package.resolved', 'conan.lock',
    'Dockerfile', 'Dockerfile.prod', 'api.Dockerfile', 'worker.dockerfile', 'Containerfile',
    'infra/main.tf', 'infra/main.tf.json', 'infra/prod.tfvars', 'charts/api/Chart.yaml', 'charts/api/values-prod.yaml',
    'k8s/deployment.yaml', 'deploy/service.yml', 'kubernetes/ingress.yaml', 'manifests/job.yaml',
    'cloudformation/stack.yaml', 'stack.template', 'arm/storage.json',
]);

it('ignores files trivy has nothing to say about', function (string $path) {
    expect(selected([['filename' => $path, 'status' => 'modified']]))->toBe([]);
})->with([
    'app/Http/Controller.php', 'package.json', 'composer.json', 'config/app.yaml', 'docker-compose.yml', 'compose.yaml', 'README.md',
]);

it('drops removed files and unsafe paths, and marks added files', function () {
    expect(selected([
        ['filename' => 'composer.lock', 'status' => 'removed'],
        ['filename' => '../../etc/composer.lock', 'status' => 'added'],
        ['filename' => '/abs/composer.lock', 'status' => 'added'],
        ['filename' => 'Dockerfile', 'status' => 'added'],
    ]))->toBe([['Dockerfile', 'Dockerfile', true]]);
});

it('reads the base copy of a renamed file from its previous name', function () {
    expect(selected([['filename' => 'api/composer.lock', 'previous_filename' => 'composer.lock', 'status' => 'renamed']]))
        ->toBe([['api/composer.lock', 'composer.lock', false]]);
});
