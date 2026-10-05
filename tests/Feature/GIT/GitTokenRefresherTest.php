<?php

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitAccount;
use App\Models\GIT\GitProviderApp;
use App\Services\Git\GitTokenRefresher;
use Illuminate\Support\Facades\Http;

function refresherProviderApp(): void
{
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
}

function expiredAccount(): GitAccount
{
    return GitAccount::factory()->create([
        'access_token' => 'gho_old',
        'refresh_token' => 'ghr_old',
        'token_expires_at' => now()->subMinute(),
    ]);
}

test('expired token is exchanged and persisted', function () {
    refresherProviderApp();
    $account = expiredAccount();

    Http::fake([
        'github.com/login/oauth/access_token' => Http::response([
            'access_token' => 'gho_new',
            'refresh_token' => 'ghr_new',
            'expires_in' => 28800,
        ]),
    ]);

    $refreshed = app(GitTokenRefresher::class)->refreshIfExpired($account);

    expect($refreshed->access_token)->toBe('gho_new')
        ->and($account->fresh()->refresh_token)->toBe('ghr_new');
    Http::assertSentCount(1);
});

test('stale account reuses the token another worker already refreshed', function () {
    refresherProviderApp();
    $stale = expiredAccount();

    // Another worker refreshed the row after this job loaded its copy.
    GitAccount::query()->findOrFail($stale->id)->update([
        'access_token' => 'gho_rotated',
        'refresh_token' => 'ghr_rotated',
        'token_expires_at' => now()->addHours(8),
    ]);

    Http::fake();

    $refreshed = app(GitTokenRefresher::class)->refreshIfExpired($stale);

    expect($refreshed->access_token)->toBe('gho_rotated');
    Http::assertNothingSent();
});
