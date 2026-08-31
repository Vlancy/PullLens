<?php

use App\Enums\GIT\GitProvider;
use App\Models\AI\AiProvider;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Models\Users\User;
use App\Repositories\Contracts\AI\AiProviderRepositoryInterface;
use App\Services\AI\AiProviderConfigResolver;
use App\Services\AI\AiProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

it('stores provider credentials encrypted', function () {
    $provider = AiProvider::query()->create([
        'provider_driver' => 'openai',
        'name' => 'OpenAI',
        'credentials' => ['api_key' => 'secret-key'],
        'default_model' => 'gpt-4o-mini',
        'is_default' => true,
        'is_enabled' => true,
    ]);

    $raw = $provider->getRawOriginal('credentials');
    $decrypted = json_decode(Crypt::decryptString($raw), true);

    expect($raw)->not->toContain('secret-key')
        ->and($decrypted['api_key'])->toBe('secret-key');
});

it('keeps a single default provider', function () {
    $manager = app(AiProviderManager::class);

    $first = $manager->create([
        'provider_driver' => 'openai',
        'name' => 'OpenAI',
        'api_key' => 'first-key',
        'default_model' => 'gpt-4o-mini',
        'is_default' => true,
        'is_enabled' => true,
    ]);
    $second = $manager->create([
        'provider_driver' => 'anthropic',
        'name' => 'Anthropic',
        'api_key' => 'second-key',
        'default_model' => 'claude-sonnet-4-5',
        'is_default' => true,
        'is_enabled' => true,
    ]);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and(app(AiProviderRepositoryInterface::class)->default()->is($second))->toBeTrue();
});

it('rejects duplicate provider names when creating providers', function () {
    $user = User::factory()->admin()->create();

    AiProvider::query()->create([
        'provider_driver' => 'openai',
        'name' => 'OpenAI',
        'credentials' => ['api_key' => 'secret-key'],
        'is_default' => true,
        'is_enabled' => true,
    ]);

    $this->actingAs($user)
        ->post(route('ai-providers.store'), [
            'provider_driver' => 'anthropic',
            'name' => 'OpenAI',
            'api_key' => 'another-key',
            'base_url' => null,
            'default_model' => 'claude-sonnet-4-5',
            'is_default' => false,
            'is_enabled' => true,
        ])
        ->assertSessionHasErrors('name');
});

it('rejects duplicate provider names when updating providers', function () {
    $user = User::factory()->admin()->create();
    $openAi = AiProvider::query()->create([
        'provider_driver' => 'openai',
        'name' => 'OpenAI',
        'credentials' => ['api_key' => 'secret-key'],
        'is_default' => true,
        'is_enabled' => true,
    ]);
    $anthropic = AiProvider::query()->create([
        'provider_driver' => 'anthropic',
        'name' => 'Anthropic',
        'credentials' => ['api_key' => 'another-key'],
        'is_default' => false,
        'is_enabled' => true,
    ]);

    $this->actingAs($user)
        ->put(route('ai-providers.update', $anthropic), [
            'provider_driver' => 'anthropic',
            'name' => $openAi->name,
            'api_key' => null,
            'base_url' => null,
            'default_model' => 'claude-sonnet-4-5',
            'is_default' => false,
            'is_enabled' => true,
        ])
        ->assertSessionHasErrors('name');
});

it('resolves repository AI overrides into Laravel AI config', function () {
    $defaultProvider = AiProvider::query()->create([
        'provider_driver' => 'openai',
        'name' => 'Default OpenAI',
        'credentials' => ['api_key' => 'default-key'],
        'default_model' => 'gpt-4o-mini',
        'is_default' => true,
        'is_enabled' => true,
    ]);
    $overrideProvider = AiProvider::query()->create([
        'provider_driver' => 'openrouter',
        'name' => 'OpenRouter',
        'credentials' => ['api_key' => 'override-key'],
        'base_url' => 'https://openrouter.ai/api/v1',
        'default_model' => 'anthropic/claude-sonnet-4.5',
        'is_default' => false,
        'is_enabled' => true,
    ]);
    $account = GitAccount::query()->create([
        'provider' => GitProvider::Github,
        'provider_user_id' => '123',
        'access_token' => 'token',
        'connected_at' => now(),
    ]);
    $repository = GitRepository::query()->create([
        'git_account_id' => $account->id,
        'provider' => GitProvider::Github,
        'provider_repo_id' => 456,
        'owner_login' => 'vlancy',
        'name' => 'PullLens',
        'full_name' => 'vlancy/PullLens',
        'ai_provider_id' => $overrideProvider->id,
        'ai_model' => 'custom/model',
    ]);

    $resolved = app(AiProviderConfigResolver::class)->forRepository($repository);

    expect($defaultProvider->is_default)->toBeTrue()
        ->and($resolved->provider->is($overrideProvider))->toBeTrue()
        ->and($resolved->model)->toBe('custom/model')
        ->and(config("ai.providers.{$resolved->configName}.driver"))->toBe('openrouter')
        ->and(config("ai.providers.{$resolved->configName}.key"))->toBe('override-key')
        ->and(config("ai.providers.{$resolved->configName}.url"))->toBe('https://openrouter.ai/api/v1')
        ->and(config('ai.default'))->not->toBe($resolved->configName);
});
