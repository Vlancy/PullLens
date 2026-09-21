<?php

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitProviderApp;
use App\Models\Users\User;
use App\Services\Git\ConnectedGitAccount;
use App\Services\Git\GitAccountConnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('git platforms page is displayed for authenticated users', function () {
    $user = User::factory()->admin()->create();

    GitProviderApp::query()->create([
        'provider' => GitProvider::Github,
        'name' => 'PullLens',
        'app_id' => '12345',
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
        'webhook_secret' => 'github-webhook-secret',
        'private_key' => 'github-private-key',
        'slug' => 'pulllens-test-app',
        'configured_at' => now(),
    ]);

    GitAccount::query()->create([
        'provider' => GitProvider::Github,
        'provider_user_id' => '1001',
        'nickname' => 'octocat',
        'name' => 'Octo Cat',
        'email' => 'octocat@example.com',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/1001',
        'access_token' => 'github-token',
        'scopes' => ['read:user', 'user:email'],
        'connected_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('integrations.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/git-platforms')
            ->has('providers', 1)
            ->where('providers.0.value', 'github')
            ->where('providers.0.configured', true)
            ->where('providers.0.install_url', 'https://github.com/apps/pulllens-test-app/installations/new')
            ->where('providers.0.github_settings_url', 'https://github.com/apps/pulllens-test-app')
            ->where('providers.0.delete_url', route('integrations.apps.destroy', GitProvider::Github->value))
            ->where('providers.0.setup_url', route('integrations.github.manifest.setup'))
            ->where('providers.0.callback_url', route('integrations.callback', GitProvider::Github->value))
            ->where('providers.0.webhook_url', route('webhooks.github'))
            ->where('accounts.0.nickname', 'octocat')
            ->missing('accounts.0.access_token')
            ->missing('accounts.0.refresh_token'),
        );
});

test('git platforms page shows github setup when provider app is missing', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route('integrations.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('providers.0.value', 'github')
            ->where('providers.0.configured', false)
            ->where('providers.0.setup_url', route('integrations.github.manifest.setup')),
        );
});

test('guests cannot access git platform settings', function () {
    $this->get(route('integrations.edit'))
        ->assertRedirect(route('login'));
});

test('git account tokens and scopes are encrypted at rest', function () {
    $account = app(GitAccountConnector::class)->connect(connectedGitAccount(
        providerUserId: '1001',
        accessToken: 'plain-access-token',
        refreshToken: 'plain-refresh-token',
    ));

    $raw = DB::table('git_accounts')->where('id', $account->id)->first();

    expect($raw->access_token)->not->toBe('plain-access-token')
        ->and($raw->refresh_token)->not->toBe('plain-refresh-token')
        ->and($raw->scopes)->not->toContain('read:user')
        ->and($account->access_token)->toBe('plain-access-token')
        ->and($account->refresh_token)->toBe('plain-refresh-token')
        ->and($account->scopes)->toBe(['read:user', 'user:email']);
});

test('github app manifest setup posts a generated manifest to github', function () {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)
        ->get(route('integrations.github.manifest.setup'));

    $content = str_replace('\\/', '/', html_entity_decode($response->getContent()));

    $response->assertOk()
        ->assertSee('https://github.com/settings/apps/new');

    expect($content)->toContain('pull_request')
        ->and($content)->toContain(route('integrations.callback', GitProvider::Github->value))
        ->and($content)->toContain(route('webhooks.github'));

    expect(session('github_app_manifest_state'))->toBeString()->not->toBeEmpty();
});

test('github app manifest callback stores generated app credentials encrypted', function () {
    $user = User::factory()->admin()->create();

    Http::fake([
        'api.github.com/app-manifests/temporary-code/conversions' => Http::response([
            'id' => 12345,
            'name' => 'PullLens test app',
            'slug' => 'pulllens-test-app',
            'client_id' => 'github-client-id',
            'client_secret' => 'plain-client-secret',
            'webhook_secret' => 'plain-webhook-secret',
            'pem' => 'plain-private-key',
        ]),
    ]);

    $this->actingAs($user)
        ->withSession(['github_app_manifest_state' => 'known-state'])
        ->get(route('integrations.github.manifest.callback', [
            'state' => 'known-state',
            'code' => 'temporary-code',
        ]))
        ->assertRedirect(route('integrations.edit'));

    $app = GitProviderApp::query()->where('provider', GitProvider::Github)->firstOrFail();
    $raw = DB::table('git_provider_apps')->where('id', $app->id)->first();

    expect($app->client_id)->toBe('github-client-id')
        ->and($app->client_secret)->toBe('plain-client-secret')
        ->and($app->webhook_secret)->toBe('plain-webhook-secret')
        ->and($app->private_key)->toBe('plain-private-key')
        ->and($raw->client_secret)->not->toBe('plain-client-secret')
        ->and($raw->webhook_secret)->not->toBe('plain-webhook-secret')
        ->and($raw->private_key)->not->toBe('plain-private-key');
});

test('github app manifest callback rejects invalid state', function () {
    $user = User::factory()->admin()->create();

    Http::fake();

    $this->actingAs($user)
        ->withSession(['github_app_manifest_state' => 'known-state'])
        ->get(route('integrations.github.manifest.callback', [
            'state' => 'wrong-state',
            'code' => 'temporary-code',
        ]))
        ->assertForbidden();

    Http::assertNothingSent();
    expect(GitProviderApp::query()->count())->toBe(0);
});

test('github app manifest callback rejects missing state', function () {
    $user = User::factory()->admin()->create();

    Http::fake();

    $this->actingAs($user)
        ->get('/settings/git-platforms/github/manifest/callback?code=temporary-code')
        ->assertNotFound();

    Http::assertNothingSent();
    expect(GitProviderApp::query()->count())->toBe(0);
});

test('the instance can connect multiple github accounts', function () {
    $connector = app(GitAccountConnector::class);

    $connector->connect(connectedGitAccount(providerUserId: '1001', nickname: 'first'));
    $connector->connect(connectedGitAccount(providerUserId: '1002', nickname: 'second'));

    expect(GitAccount::query()->where('provider', GitProvider::Github)->count())->toBe(2);
});

test('connecting the same github account refreshes the system account', function () {
    $connector = app(GitAccountConnector::class);

    $account = $connector->connect(connectedGitAccount(providerUserId: '1001', nickname: 'first'));
    $refreshed = $connector->connect(connectedGitAccount(providerUserId: '1001', nickname: 'updated'));

    expect($refreshed->id)->toBe($account->id)
        ->and($refreshed->nickname)->toBe('updated')
        ->and(GitAccount::query()->count())->toBe(1);
});

test('authenticated users can disconnect system git accounts', function () {
    $user = User::factory()->admin()->create();
    $account = app(GitAccountConnector::class)->connect(connectedGitAccount(providerUserId: '1001'));

    $this->actingAs($user)
        ->delete(route('integrations.destroy', $account))
        ->assertRedirect(route('integrations.edit'));

    expect($account->fresh())->toBeNull();
});

test('authenticated users can delete provider app configuration and connected accounts', function () {
    $user = User::factory()->admin()->create();

    Http::fake();

    GitProviderApp::query()->create([
        'provider' => GitProvider::Github,
        'name' => 'PullLens',
        'app_id' => '12345',
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
        'webhook_secret' => 'github-webhook-secret',
        'private_key' => 'github-private-key',
        'slug' => 'pulllens-test-app',
        'configured_at' => now(),
    ]);

    app(GitAccountConnector::class)->connect(connectedGitAccount(providerUserId: '1001'));

    $this->actingAs($user)
        ->delete(route('integrations.apps.destroy', GitProvider::Github->value))
        ->assertRedirect(route('integrations.edit'));

    expect(GitProviderApp::query()->where('provider', GitProvider::Github)->count())->toBe(0)
        ->and(GitAccount::query()->where('provider', GitProvider::Github)->count())->toBe(0);

    // An unparseable private key must not trigger any GitHub API calls.
    Http::assertNothingSent();
});

test('deleting the github app uninstalls it from every account', function () {
    $user = User::factory()->admin()->create();

    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $pem);

    GitProviderApp::query()->create([
        'provider' => GitProvider::Github,
        'name' => 'PullLens',
        'app_id' => '12345',
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
        'webhook_secret' => 'github-webhook-secret',
        'private_key' => $pem,
        'slug' => 'pulllens-test-app',
        'configured_at' => now(),
    ]);

    Http::fake([
        'api.github.com/app/installations?*' => Http::response([
            ['id' => 11],
            ['id' => 22],
        ]),
        'api.github.com/app/installations/*' => Http::response([], 204),
    ]);

    $this->actingAs($user)
        ->delete(route('integrations.apps.destroy', GitProvider::Github->value))
        ->assertRedirect(route('integrations.edit'));

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/app/installations/11');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/app/installations/22');

    expect(GitProviderApp::query()->where('provider', GitProvider::Github)->count())->toBe(0);
});

function connectedGitAccount(
    string $providerUserId,
    string $nickname = 'octocat',
    string $accessToken = 'access-token',
    ?string $refreshToken = null,
): ConnectedGitAccount {
    return new ConnectedGitAccount(
        provider: GitProvider::Github,
        providerUserId: $providerUserId,
        nickname: $nickname,
        name: 'Octo Cat',
        email: 'octocat@example.com',
        avatarUrl: 'https://avatars.githubusercontent.com/u/'.$providerUserId,
        accessToken: $accessToken,
        refreshToken: $refreshToken,
        scopes: ['read:user', 'user:email'],
        tokenExpiresAt: null,
    );
}

/*
| The OAuth callback is the one route here a browser reaches while carrying state
| PullLens did not set. Everything that can go wrong in it - an expired handshake,
| a refusal on GitHub's side, an unreachable provider - used to surface as a bare
| 500, which tells the operator nothing and looks like the instance is broken.
*/

function configuredGithubApp(): GitProviderApp
{
    return GitProviderApp::query()->create([
        'provider' => GitProvider::Github,
        'name' => 'PullLens',
        'app_id' => '12345',
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
        'webhook_secret' => 'github-webhook-secret',
        'private_key' => 'github-private-key',
        'slug' => 'pulllens-test-app',
        'configured_at' => now(),
    ]);
}

test('a refusal on the provider side comes back as a message, not a 500', function () {
    $user = User::factory()->admin()->create();
    configuredGithubApp();

    $this->actingAs($user)
        ->get(route('integrations.callback', GitProvider::Github->value).'?error=access_denied&error_description=The+user+denied+the+request')
        ->assertRedirect(route('integrations.edit'))
        ->assertSessionHas('connection_error');

    expect(GitAccount::query()->count())->toBe(0);
});

test('an expired handshake comes back as a message, not a 500', function () {
    $user = User::factory()->admin()->create();
    configuredGithubApp();

    // No oauth state in the session, which is exactly what Socialite sees when the
    // handshake took too long or the session was replaced mid-flow.
    $response = $this->actingAs($user)
        ->get(route('integrations.callback', GitProvider::Github->value).'?code=abc&state=does-not-match');

    $response->assertRedirect(route('integrations.edit'))
        ->assertSessionHas('connection_error');

    expect(GitAccount::query()->count())->toBe(0);
});

test('the integrations page shows a failed connection attempt', function () {
    $user = User::factory()->admin()->create();
    configuredGithubApp();

    $this->actingAs($user)
        ->withSession(['connection_error' => 'The GitHub sign-in expired before it finished.'])
        ->get(route('integrations.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('connection_error', 'The GitHub sign-in expired before it finished.')
        );
});

test('a callback for an unknown provider is still a 404', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route('integrations.callback', 'bitbucket'))
        ->assertNotFound();
});
