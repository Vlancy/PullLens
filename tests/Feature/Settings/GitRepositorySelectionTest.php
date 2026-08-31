<?php

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitProviderApp;
use App\Models\GIT\GitRepository;
use App\Models\GIT\GitRepositoryBranch;
use App\Models\Users\User;
use Illuminate\Support\Facades\Http;

function testRsaPrivateKey(): string
{
    $res = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $pem);

    return $pem;
}

function gitOperatorAccount(string $providerUserId = '1001'): GitAccount
{
    return GitAccount::query()->create([
        'provider' => GitProvider::Github,
        'provider_user_id' => $providerUserId,
        'nickname' => 'octocat',
        'name' => 'Octo Cat',
        'email' => 'octocat@example.com',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/'.$providerUserId,
        'access_token' => 'github-token',
        'scopes' => ['repo'],
        'connected_at' => now(),
    ]);
}

test('browse returns installations and repositories across personal and org accounts', function () {
    $user = User::factory()->admin()->create();

    GitProviderApp::query()->create([
        'provider' => GitProvider::Github,
        'name' => 'PullLens',
        'app_id' => '12345',
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
        'webhook_secret' => 'github-webhook-secret',
        'private_key' => testRsaPrivateKey(),
        'slug' => 'pulllens-test-app',
        'configured_at' => now(),
    ]);

    Http::fake([
        'api.github.com/app/installations*' => Http::response([
            ['id' => 11, 'account' => ['login' => 'octocat', 'type' => 'User', 'avatar_url' => 'https://avatars/octocat']],
            ['id' => 22, 'account' => ['login' => 'acme-inc', 'type' => 'Organization', 'avatar_url' => 'https://avatars/acme']],
        ]),
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'install-token']),
        'api.github.com/installation/repositories*' => Http::sequence()
            ->push(['repositories' => [
                ['id' => 100, 'name' => 'personal-repo', 'full_name' => 'octocat/personal-repo', 'default_branch' => 'main', 'private' => false, 'html_url' => 'https://github.com/octocat/personal-repo'],
            ]])
            ->push(['repositories' => [
                ['id' => 200, 'name' => 'org-repo', 'full_name' => 'acme-inc/org-repo', 'default_branch' => 'develop', 'private' => true, 'html_url' => 'https://github.com/acme-inc/org-repo'],
            ]]),
    ]);

    $this->actingAs($user)
        ->getJson(route('integrations.repositories.browse', GitProvider::Github->value))
        ->assertOk()
        ->assertJsonPath('installations.0.account_login', 'octocat')
        ->assertJsonPath('installations.0.account_type', 'User')
        ->assertJsonPath('installations.0.repositories.0.provider_repo_id', 100)
        ->assertJsonPath('installations.1.account_login', 'acme-inc')
        ->assertJsonPath('installations.1.account_type', 'Organization')
        ->assertJsonPath('installations.1.repositories.0.provider_repo_id', 200)
        ->assertJsonPath('installations.1.repositories.0.private', true);
});

test('browse returns 404 when no provider app is configured', function () {
    $user = User::factory()->admin()->create();

    Http::fake();

    $this->actingAs($user)
        ->getJson(route('integrations.repositories.browse', GitProvider::Github->value))
        ->assertNotFound();

    Http::assertNothingSent();
});

test('storing a selection persists repositories with their branches', function () {
    $user = User::factory()->admin()->create();
    $account = gitOperatorAccount();

    Http::fake([
        'api.github.com/user/installations?*' => Http::response([
            'installations' => [
                ['id' => 22, 'account' => ['login' => 'acme-inc', 'type' => 'Organization', 'avatar_url' => null]],
            ],
        ]),
        'api.github.com/user/installations/22/repositories*' => Http::response([
            'repositories' => [
                ['id' => 200, 'name' => 'org-repo', 'full_name' => 'acme-inc/org-repo', 'default_branch' => 'main', 'private' => true, 'html_url' => 'https://github.com/acme-inc/org-repo'],
            ],
        ]),
        'api.github.com/repos/acme-inc/org-repo/branches*' => Http::response([
            ['name' => 'main', 'commit' => ['sha' => 'sha-main'], 'protected' => true],
            ['name' => 'feature', 'commit' => ['sha' => 'sha-feature'], 'protected' => false],
        ]),
    ]);

    $this->actingAs($user)
        ->post(route('integrations.repositories.store', GitProvider::Github->value), [
            'account_id' => $account->id,
            'repositories' => [
                ['provider_repo_id' => 200, 'installation_id' => 22],
            ],
        ])
        ->assertRedirect(route('integrations.edit'));

    $repository = GitRepository::query()->where('provider_repo_id', 200)->firstOrFail();

    expect($repository->git_account_id)->toBe($account->id)
        ->and($repository->full_name)->toBe('acme-inc/org-repo')
        ->and($repository->owner_login)->toBe('acme-inc')
        ->and($repository->owner_type)->toBe('Organization')
        ->and($repository->is_private)->toBeTrue()
        ->and($repository->installation_id)->toBe(22)
        ->and($repository->web_url)->toBe('https://github.com/acme-inc/org-repo')
        ->and($repository->branches()->count())->toBe(2)
        ->and($repository->tracked_branches)->toBe(['main']);

    $default = GitRepositoryBranch::query()->where('name', 'main')->firstOrFail();

    expect($default->is_default)->toBeTrue()
        ->and($default->is_protected)->toBeTrue()
        ->and($default->commit_sha)->toBe('sha-main');
});

test('storing ignores selections the operator cannot actually access', function () {
    $user = User::factory()->admin()->create();
    $account = gitOperatorAccount();

    Http::fake([
        'api.github.com/user/installations?*' => Http::response([
            'installations' => [
                ['id' => 22, 'account' => ['login' => 'acme-inc', 'type' => 'Organization', 'avatar_url' => null]],
            ],
        ]),
        'api.github.com/user/installations/22/repositories*' => Http::response([
            'repositories' => [
                ['id' => 200, 'name' => 'org-repo', 'full_name' => 'acme-inc/org-repo', 'default_branch' => 'main', 'private' => true, 'html_url' => 'https://github.com/acme-inc/org-repo'],
            ],
        ]),
        'api.github.com/repos/acme-inc/org-repo/branches*' => Http::response([
            ['name' => 'main', 'commit' => ['sha' => 'sha-main'], 'protected' => true],
        ]),
    ]);

    $this->actingAs($user)
        ->post(route('integrations.repositories.store', GitProvider::Github->value), [
            'account_id' => $account->id,
            'repositories' => [
                ['provider_repo_id' => 200, 'installation_id' => 22],
                ['provider_repo_id' => 999, 'installation_id' => 22],
            ],
        ])
        ->assertRedirect(route('integrations.edit'));

    expect(GitRepository::query()->count())->toBe(1)
        ->and(GitRepository::query()->where('provider_repo_id', 999)->exists())->toBeFalse();
});

test('deselecting a repository untracks it and removes its branches', function () {
    $user = User::factory()->admin()->create();
    $account = gitOperatorAccount();

    $stale = GitRepository::query()->create([
        'git_account_id' => $account->id,
        'provider' => GitProvider::Github,
        'installation_id' => 22,
        'provider_repo_id' => 200,
        'owner_login' => 'acme-inc',
        'owner_type' => 'Organization',
        'name' => 'org-repo',
        'full_name' => 'acme-inc/org-repo',
        'default_branch' => 'main',
        'is_private' => true,
        'web_url' => 'https://github.com/acme-inc/org-repo',
    ]);
    $stale->branches()->create(['name' => 'main', 'commit_sha' => 'sha', 'is_protected' => true, 'is_default' => true]);

    Http::fake([
        'api.github.com/user/installations?*' => Http::response([
            'installations' => [
                ['id' => 11, 'account' => ['login' => 'octocat', 'type' => 'User', 'avatar_url' => null]],
            ],
        ]),
        'api.github.com/user/installations/11/repositories*' => Http::response([
            'repositories' => [
                ['id' => 100, 'name' => 'personal-repo', 'full_name' => 'octocat/personal-repo', 'default_branch' => 'main', 'private' => false, 'html_url' => 'https://github.com/octocat/personal-repo'],
            ],
        ]),
        'api.github.com/repos/octocat/personal-repo/branches*' => Http::response([
            ['name' => 'main', 'commit' => ['sha' => 'sha-main'], 'protected' => false],
        ]),
    ]);

    $this->actingAs($user)
        ->post(route('integrations.repositories.store', GitProvider::Github->value), [
            'account_id' => $account->id,
            'repositories' => [
                ['provider_repo_id' => 100, 'installation_id' => 11],
            ],
        ])
        ->assertRedirect(route('integrations.edit'));

    expect(GitRepository::query()->where('provider_repo_id', 200)->exists())->toBeFalse()
        ->and(GitRepositoryBranch::query()->where('commit_sha', 'sha')->exists())->toBeFalse()
        ->and(GitRepository::query()->where('provider_repo_id', 100)->exists())->toBeTrue();
});

test('authenticated users can stop tracking a repository', function () {
    $user = User::factory()->admin()->create();
    $account = gitOperatorAccount();

    $repository = GitRepository::query()->create([
        'git_account_id' => $account->id,
        'provider' => GitProvider::Github,
        'installation_id' => 11,
        'provider_repo_id' => 100,
        'owner_login' => 'octocat',
        'owner_type' => 'User',
        'name' => 'personal-repo',
        'full_name' => 'octocat/personal-repo',
        'default_branch' => 'main',
        'is_private' => false,
        'web_url' => 'https://github.com/octocat/personal-repo',
    ]);
    $repository->branches()->create(['name' => 'main', 'commit_sha' => 'sha', 'is_protected' => false, 'is_default' => true]);

    $this->actingAs($user)
        ->delete(route('integrations.repositories.destroy', $repository->id))
        ->assertRedirect(route('integrations.edit'));

    expect($repository->fresh())->toBeNull()
        ->and(GitRepositoryBranch::query()->where('git_repository_id', $repository->id)->exists())->toBeFalse();
});

test('guests cannot browse repositories', function () {
    $this->getJson(route('integrations.repositories.browse', GitProvider::Github->value))
        ->assertUnauthorized();
});

test('tracked repositories are exposed to the git platforms page', function () {
    $user = User::factory()->admin()->create();
    $account = gitOperatorAccount();

    $repository = GitRepository::query()->create([
        'git_account_id' => $account->id,
        'provider' => GitProvider::Github,
        'installation_id' => 11,
        'provider_repo_id' => 100,
        'owner_login' => 'octocat',
        'owner_type' => 'User',
        'name' => 'personal-repo',
        'full_name' => 'octocat/personal-repo',
        'default_branch' => 'main',
        'is_private' => false,
        'web_url' => 'https://github.com/octocat/personal-repo',
    ]);
    $repository->branches()->create(['name' => 'main', 'commit_sha' => 'sha', 'is_protected' => false, 'is_default' => true]);

    $this->actingAs($user)
        ->get(route('integrations.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('repositories', 1)
            ->where('repositories.0.provider_repo_id', 100)
            ->where('repositories.0.full_name', 'octocat/personal-repo')
            ->where('repositories.0.web_url', 'https://github.com/octocat/personal-repo')
            ->where('repositories.0.branches_count', 1)
            ->etc(),
        );
});
