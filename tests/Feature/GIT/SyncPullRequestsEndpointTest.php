<?php

use App\Enums\GIT\GitProvider;
use App\Jobs\GIT\DiscoverPullRequests;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitRepository;
use App\Models\Users\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

function sweepRepository(string $name, array $attributes = []): GitRepository
{
    $account = GitAccount::query()->firstOrCreate(
        ['provider' => GitProvider::Github, 'provider_user_id' => '910'],
        ['access_token' => 'token', 'connected_at' => now()],
    );

    return GitRepository::query()->create([
        'git_account_id' => $account->id,
        'provider' => GitProvider::Github,
        'provider_repo_id' => random_int(1, 999999),
        'owner_login' => 'octocat',
        'name' => $name,
        'full_name' => 'octocat/'.$name,
        'default_branch' => 'main',
        'reviews_enabled' => true,
        ...$attributes,
    ]);
}

it('queues one discovery job per repository the operator can manage', function () {
    $user = User::factory()->admin()->create();
    sweepRepository('api');
    sweepRepository('web');
    Queue::fake();

    $this->actingAs($user)
        ->post(route('repositories.sync-pull-requests'))
        ->assertRedirect();

    Queue::assertPushed(DiscoverPullRequests::class, 2);
});

it('reports how many repositories are being scanned', function () {
    $user = User::factory()->admin()->create();
    sweepRepository('api');
    sweepRepository('web');
    Queue::fake();

    $this->actingAs($user)
        ->post(route('repositories.sync-pull-requests'))
        ->assertSessionHas('discovery_queued', 2);
});

it('scans a repository whose reviews are disabled, because discovery is not a review', function () {
    $user = User::factory()->admin()->create();
    sweepRepository('archived', ['reviews_enabled' => false]);
    Queue::fake();

    $this->actingAs($user)
        ->post(route('repositories.sync-pull-requests'))
        ->assertRedirect();

    Queue::assertPushed(DiscoverPullRequests::class, 1);
});

it('refuses a user without permission to trigger reviews', function () {
    $user = User::factory()->create();
    sweepRepository('api');
    Queue::fake();

    $this->actingAs($user)
        ->post(route('repositories.sync-pull-requests'))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('refuses a guest', function () {
    $this->post(route('repositories.sync-pull-requests'))
        ->assertRedirect(route('login'));
});

it('surfaces the scan count on the repositories page after the redirect', function () {
    $user = User::factory()->admin()->create();
    sweepRepository('api');
    Queue::fake();

    $this->actingAs($user)
        ->post(route('repositories.sync-pull-requests'));

    $this->actingAs($user)
        ->withSession(['discovery_queued' => 1])
        ->get(route('repositories.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('repositories/index')
            ->where('discovery_queued', 1)
        );
});
