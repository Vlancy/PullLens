<?php

use App\Ai\Agents\RepositoryProfileAgent;
use App\Enums\AI\AiOperation;
use App\Enums\GIT\GitProvider;
use App\Enums\GIT\RepositoryTagKind;
use App\Jobs\GIT\RefreshRepositoryProfile;
use App\Models\AI\AiProvider;
use App\Models\AI\AiUsageRecord;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Models\Users\User;
use App\Services\AI\AiProviderConfigResolver;
use App\Services\AI\AiUsageRecorder;
use App\Services\Git\GitHubApiClient;
use App\Services\Git\GitHubCallerResolver;
use App\Services\Repositories\RepositoryTagService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Create an enabled default AI provider so the job can resolve one.
 */
function profileAiProvider(): AiProvider
{
    return AiProvider::query()->create([
        'provider_driver' => 'openai',
        'name' => 'Default OpenAI',
        'credentials' => ['api_key' => 'key'],
        'default_model' => 'gpt-4o-mini',
        'is_default' => true,
        'is_enabled' => true,
    ]);
}

/**
 * Fake the GitHub endpoints the job reads for a repository.
 */
function fakeProfileGithub(): void
{
    Http::fake([
        'api.github.com/repos/*/languages' => Http::response(['PHP' => 9000, 'Blade' => 100]),
        'api.github.com/repos/*/contents/composer.json*' => Http::response(['content' => base64_encode('{"require":{"laravel/framework":"^12"}}')]),
        'api.github.com/repos/*/contents/README.md*' => Http::response(['content' => base64_encode('# Billing app')]),
        'api.github.com/*' => Http::response([], 404),
    ]);
}

it('refresh stores ai tags and stamps profile_refreshed_at', function () {
    profileAiProvider();
    fakeProfileGithub();
    RepositoryProfileAgent::fake([['tags' => [['name' => 'laravel', 'kind' => 'framework'], ['name' => 'php', 'kind' => 'language']]]]);

    $repo = GitRepository::factory()->create();

    (new RefreshRepositoryProfile($repo->id))->handle(
        app(GitHubApiClient::class),
        app(GitHubCallerResolver::class),
        app(AiProviderConfigResolver::class),
        app(AiUsageRecorder::class),
        app(RepositoryTagService::class),
    );

    $repo->refresh();

    expect($repo->profile_refreshed_at)->not->toBeNull()
        ->and($repo->tags()->pluck('name')->sort()->values()->all())->toBe(['laravel', 'php'])
        ->and(AiUsageRecord::where('operation', AiOperation::RepositoryProfile->value)->count())->toBe(1);
});

it('manual tag kept after refresh', function () {
    profileAiProvider();
    fakeProfileGithub();
    RepositoryProfileAgent::fake([['tags' => [['name' => 'laravel', 'kind' => 'framework']]]]);

    $repo = GitRepository::factory()->create();
    app(RepositoryTagService::class)->addManual($repo, 'billing', RepositoryTagKind::Domain);

    RefreshRepositoryProfile::dispatchSync($repo->id);

    expect($repo->tags()->pluck('name')->sort()->values()->all())->toBe(['billing', 'laravel']);
});

it('does nothing when no ai provider is configured', function () {
    fakeProfileGithub();

    $repo = GitRepository::factory()->create();

    RefreshRepositoryProfile::dispatchSync($repo->id);

    expect($repo->fresh()->profile_refreshed_at)->toBeNull()
        ->and($repo->tags()->count())->toBe(0);
});

it('command dispatches only stale repos', function () {
    Queue::fake();

    $never = GitRepository::factory()->create(['profile_refreshed_at' => null]);
    $stale = GitRepository::factory()->create(['profile_refreshed_at' => now()->subDays(40)]);
    GitRepository::factory()->create(['profile_refreshed_at' => now()->subDays(5)]);

    $this->artisan('pulllens:refresh-repository-profiles')->assertSuccessful();

    Queue::assertPushed(RefreshRepositoryProfile::class, 2);
    Queue::assertPushed(RefreshRepositoryProfile::class, fn ($job) => $job->repositoryId === $never->id);
    Queue::assertPushed(RefreshRepositoryProfile::class, fn ($job) => $job->repositoryId === $stale->id);
});

it('newly selected repository dispatches refresh', function () {
    Queue::fake();

    $user = User::factory()->admin()->create();
    $account = GitAccount::query()->create([
        'provider' => GitProvider::Github,
        'provider_user_id' => '1001',
        'nickname' => 'octocat',
        'name' => 'Octo Cat',
        'email' => 'octocat@example.com',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/1001',
        'access_token' => 'github-token',
        'scopes' => ['repo'],
        'connected_at' => now(),
    ]);

    Http::fake([
        'api.github.com/user/installations?*' => Http::response([
            'installations' => [['id' => 22, 'account' => ['login' => 'acme-inc', 'type' => 'Organization', 'avatar_url' => null]]],
        ]),
        'api.github.com/user/installations/22/repositories*' => Http::response([
            'repositories' => [
                ['id' => 200, 'name' => 'org-repo', 'full_name' => 'acme-inc/org-repo', 'default_branch' => 'main', 'private' => true, 'html_url' => 'https://github.com/acme-inc/org-repo'],
            ],
        ]),
        'api.github.com/repos/acme-inc/org-repo/branches*' => Http::response([['name' => 'main', 'commit' => ['sha' => 's'], 'protected' => true]]),
        'api.github.com/*' => Http::response([]),
    ]);

    $post = fn () => $this->actingAs($user)->post(route('integrations.repositories.store', GitProvider::Github->value), [
        'account_id' => $account->id,
        'repositories' => [['provider_repo_id' => 200, 'installation_id' => 22]],
    ]);

    $post();
    Queue::assertPushed(RefreshRepositoryProfile::class, 1);

    // Re-saving an existing selection must not re-profile it.
    $post();
    Queue::assertPushed(RefreshRepositoryProfile::class, 1);
});
