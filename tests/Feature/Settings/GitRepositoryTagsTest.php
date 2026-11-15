<?php

use App\Enums\GIT\AttributionSource;
use App\Enums\GIT\GitProvider;
use App\Enums\GIT\RepositoryTagKind;
use App\Jobs\GIT\RefreshRepositoryProfile;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Models\Users\User;
use App\Services\Repositories\RepositoryTagService;
use Illuminate\Support\Facades\Queue;

function tagTestRepository(int $providerId = 500): GitRepository
{
    $account = GitAccount::query()->firstOrCreate(
        ['provider' => GitProvider::Github, 'provider_user_id' => '2001'],
        [
            'nickname' => 'octocat',
            'name' => 'Octo Cat',
            'email' => 'octocat@example.com',
            'avatar_url' => 'https://avatars.githubusercontent.com/u/2001',
            'access_token' => 'github-token',
            'scopes' => ['repo'],
            'connected_at' => now(),
        ],
    );

    return GitRepository::query()->create([
        'git_account_id' => $account->id,
        'provider' => GitProvider::Github,
        'installation_id' => 11,
        'provider_repo_id' => $providerId,
        'owner_login' => 'octocat',
        'owner_type' => 'User',
        'name' => 'repo-'.$providerId,
        'full_name' => 'octocat/repo-'.$providerId,
        'default_branch' => 'main',
        'is_private' => false,
        'web_url' => 'https://github.com/octocat/repo-'.$providerId,
    ]);
}

test('manager adds tag', function () {
    $repository = tagTestRepository();

    $this->actingAs(User::factory()->manager()->create())
        ->post(route('integrations.repositories.tags.store', $repository->id), ['name' => 'Laravel', 'kind' => 'framework'])
        ->assertRedirect();

    $tag = $repository->tags()->firstOrFail();
    expect($tag->name)->toBe('laravel')
        ->and($tag->kind)->toBe(RepositoryTagKind::Framework)
        ->and($tag->source)->toBe(AttributionSource::Manual);
});

test('tag name must slug to something', function () {
    $repository = tagTestRepository();

    $this->actingAs(User::factory()->manager()->create())
        ->post(route('integrations.repositories.tags.store', $repository->id), ['name' => '+++', 'kind' => 'other'])
        ->assertSessionHasErrors('name');

    expect($repository->tags()->count())->toBe(0);
});

test('tag name with invalid characters or kind is rejected', function () {
    $repository = tagTestRepository();

    $this->actingAs(User::factory()->manager()->create())
        ->post(route('integrations.repositories.tags.store', $repository->id), ['name' => 'bad/name', 'kind' => 'nope'])
        ->assertSessionHasErrors(['name', 'kind']);
});

test('adding a manual tag with an empty slug throws', function () {
    app(RepositoryTagService::class)->addManual(tagTestRepository(), '+++', RepositoryTagKind::Other);
})->throws(InvalidArgumentException::class);

test('removing ai tag dismisses it', function () {
    $repository = tagTestRepository();
    $tag = $repository->tags()->create(['name' => 'php', 'kind' => RepositoryTagKind::Language, 'source' => AttributionSource::Ai]);

    $this->actingAs(User::factory()->manager()->create())
        ->delete(route('integrations.repositories.tags.destroy', [$repository->id, $tag->id]))
        ->assertRedirect();

    expect($tag->fresh()->dismissed)->toBeTrue();
});

test('removing manual tag deletes it', function () {
    $repository = tagTestRepository();
    $tag = $repository->tags()->create(['name' => 'php', 'kind' => RepositoryTagKind::Language, 'source' => AttributionSource::Manual]);

    $this->actingAs(User::factory()->manager()->create())
        ->delete(route('integrations.repositories.tags.destroy', [$repository->id, $tag->id]))
        ->assertRedirect();

    expect($tag->fresh())->toBeNull();
});

test('tag from another repository returns 404', function () {
    $repository = tagTestRepository(500);
    $other = tagTestRepository(501);
    $tag = $other->tags()->create(['name' => 'php', 'kind' => RepositoryTagKind::Language, 'source' => AttributionSource::Manual]);

    $this->actingAs(User::factory()->manager()->create())
        ->delete(route('integrations.repositories.tags.destroy', [$repository->id, $tag->id]))
        ->assertNotFound();

    expect($tag->fresh())->not->toBeNull();
});

test('refresh dispatches job', function () {
    Queue::fake();
    $repository = tagTestRepository();

    $this->actingAs(User::factory()->manager()->create())
        ->post(route('integrations.repositories.profile.refresh', $repository->id))
        ->assertRedirect();

    Queue::assertPushed(RefreshRepositoryProfile::class, fn ($job) => $job->repositoryId === $repository->id);
});

test('member forbidden', function () {
    Queue::fake();
    $repository = tagTestRepository();
    $tag = $repository->tags()->create(['name' => 'php', 'kind' => RepositoryTagKind::Language, 'source' => AttributionSource::Manual]);
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(route('integrations.repositories.tags.store', $repository->id), ['name' => 'x', 'kind' => 'other'])
        ->assertForbidden();
    $this->actingAs($member)
        ->delete(route('integrations.repositories.tags.destroy', [$repository->id, $tag->id]))
        ->assertForbidden();
    $this->actingAs($member)
        ->post(route('integrations.repositories.profile.refresh', $repository->id))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('settings page exposes live tags and refresh time', function () {
    $repository = tagTestRepository();
    $repository->tags()->create(['name' => 'php', 'kind' => RepositoryTagKind::Language, 'source' => AttributionSource::Ai]);
    $repository->tags()->create(['name' => 'old', 'kind' => RepositoryTagKind::Other, 'source' => AttributionSource::Ai, 'dismissed' => true]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('integrations.repositories.settings.edit', $repository->id))
        ->assertInertia(fn ($page) => $page
            ->has('tags', 1)
            ->where('tags.0.name', 'php')
            ->where('tags.0.source', 'ai')
            ->has('tag_kinds', 4)
            ->where('profile_refreshed_at', null));
});
