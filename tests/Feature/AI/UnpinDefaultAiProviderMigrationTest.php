<?php

use App\Models\AI\AiProvider;

/**
 * Run the one-time backfill that releases repositories the old settings form
 * pinned to the default provider without the operator ever choosing a pin.
 */
function runUnpinDefaultAiProviderMigration(): void
{
    $migration = require database_path('migrations/2026_09_21_120000_unpin_default_ai_provider_on_git_repositories.php');

    $migration->up();
}

it('releases repositories pinned to the default provider with no model of their own', function () {
    $default = AiProvider::query()->create([
        'provider_driver' => 'openai',
        'name' => 'Default OpenAI',
        'credentials' => ['api_key' => 'key'],
        'default_model' => 'gpt-4o-mini',
        'is_default' => true,
        'is_enabled' => true,
    ]);

    $nullModel = aiSettingsRepository($default, null, 'null-model');
    $emptyModel = aiSettingsRepository($default, '', 'empty-model');

    runUnpinDefaultAiProviderMigration();

    expect($nullModel->fresh()->ai_provider_id)->toBeNull()
        ->and($emptyModel->fresh()->ai_provider_id)->toBeNull();
});

it('keeps the pin when the repository also pins a model of its own', function () {
    $default = AiProvider::query()->create([
        'provider_driver' => 'openai',
        'name' => 'Default OpenAI',
        'credentials' => ['api_key' => 'key'],
        'default_model' => 'gpt-4o-mini',
        'is_default' => true,
        'is_enabled' => true,
    ]);

    $repository = aiSettingsRepository($default, 'gpt-4o', 'custom-model');

    runUnpinDefaultAiProviderMigration();

    expect($repository->fresh()->ai_provider_id)->toBe($default->id)
        ->and($repository->fresh()->ai_model)->toBe('gpt-4o');
});

it('keeps the pin when the repository is pinned to a provider that is not the default', function () {
    AiProvider::query()->create([
        'provider_driver' => 'openai',
        'name' => 'Default OpenAI',
        'credentials' => ['api_key' => 'key'],
        'default_model' => 'gpt-4o-mini',
        'is_default' => true,
        'is_enabled' => true,
    ]);
    $other = AiProvider::query()->create([
        'provider_driver' => 'anthropic',
        'name' => 'Anthropic',
        'credentials' => ['api_key' => 'key'],
        'default_model' => 'claude-sonnet-4-5',
        'is_default' => false,
        'is_enabled' => true,
    ]);

    $repository = aiSettingsRepository($other, null, 'other-pinned');

    runUnpinDefaultAiProviderMigration();

    expect($repository->fresh()->ai_provider_id)->toBe($other->id);
});

it('does nothing when no default provider is configured', function () {
    $repository = aiSettingsRepository(name: 'unpinned');

    runUnpinDefaultAiProviderMigration();

    expect($repository->fresh()->ai_provider_id)->toBeNull();
});
