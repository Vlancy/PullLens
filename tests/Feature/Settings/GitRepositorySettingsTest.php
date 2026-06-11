<?php

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Models\Users\User;
use Inertia\Testing\AssertableInertia as Assert;

function settingsGitAccount(): GitAccount
{
    return GitAccount::query()->create([
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
}

function trackedRepository(): GitRepository
{
    $repository = GitRepository::query()->create([
        'git_account_id' => settingsGitAccount()->id,
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
        'selected_at' => now(),
    ]);

    $repository->branches()->create(['name' => 'main', 'commit_sha' => 'sha-main', 'is_protected' => true, 'is_default' => true]);
    $repository->branches()->create(['name' => 'develop', 'commit_sha' => 'sha-dev', 'is_protected' => false, 'is_default' => false]);

    return $repository;
}

test('repository settings page is displayed with defaults and branches', function () {
    $user = User::factory()->create();
    $repository = trackedRepository();

    $this->actingAs($user)
        ->get(route('integrations.repositories.settings.edit', $repository->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/git-repository-settings')
            ->where('repository.full_name', 'octocat/personal-repo')
            ->where('repository.reviews_enabled', true)
            ->where('repository.auto_merge', false)
            ->where('repository.auto_merge_method', 'merge')
            ->where('repository.review_language', 'English')
            ->where('repository.base_branches', [])
            ->where('repository.tracked_branches', [])
            ->where('branches', ['main', 'develop'])
            ->where('merge_methods', ['merge', 'squash', 'rebase'])
            ->has('languages')
        );
});

test('authenticated users can update repository settings', function () {
    $user = User::factory()->create();
    $repository = trackedRepository();

    $this->actingAs($user)
        ->put(route('integrations.repositories.settings.update', $repository->id), [
            'reviews_enabled' => true,
            'auto_review_on_open' => false,
            'auto_approve' => true,
            'auto_apply_labels' => true,
            'allow_comment_replies' => false,
            'auto_merge' => true,
            'auto_merge_method' => 'squash',
            'review_language' => 'Arabic',
            'base_branches' => ['main'],
            'tracked_branches' => ['main', 'develop'],
        ])
        ->assertRedirect(route('integrations.repositories.settings.edit', $repository->id));

    $repository->refresh();

    expect($repository->auto_review_on_open)->toBeFalse()
        ->and($repository->auto_approve)->toBeTrue()
        ->and($repository->auto_apply_labels)->toBeTrue()
        ->and($repository->allow_comment_replies)->toBeFalse()
        ->and($repository->auto_merge)->toBeTrue()
        ->and($repository->auto_merge_method)->toBe('squash')
        ->and($repository->review_language)->toBe('Arabic')
        ->and($repository->base_branches)->toBe(['main'])
        ->and($repository->tracked_branches)->toBe(['main', 'develop']);
});

test('repository settings update rejects an invalid merge method', function () {
    $user = User::factory()->create();
    $repository = trackedRepository();

    $this->actingAs($user)
        ->put(route('integrations.repositories.settings.update', $repository->id), [
            'reviews_enabled' => true,
            'auto_review_on_open' => true,
            'auto_approve' => false,
            'auto_apply_labels' => false,
            'allow_comment_replies' => true,
            'auto_merge' => false,
            'auto_merge_method' => 'fast-forward',
            'review_language' => 'English',
            'base_branches' => [],
            'tracked_branches' => [],
        ])
        ->assertSessionHasErrors('auto_merge_method');
});

test('guests cannot access repository settings', function () {
    $repository = trackedRepository();

    $this->get(route('integrations.repositories.settings.edit', $repository->id))
        ->assertRedirect(route('login'));
});
