<?php

use App\Enums\Users\UserPermission;
use App\Http\Controllers\Settings\AI\AiProviderController;
use App\Http\Controllers\Settings\GIT\GitAccountDisconnectController;
use App\Http\Controllers\Settings\GIT\GitHubAppConnectController;
use App\Http\Controllers\Settings\GIT\GitHubAppManifestCallbackController;
use App\Http\Controllers\Settings\GIT\GitHubAppManifestSetupController;
use App\Http\Controllers\Settings\GIT\GitHubAppSyncController;
use App\Http\Controllers\Settings\GIT\GitHubAppTestController;
use App\Http\Controllers\Settings\GIT\GitPlatformCallbackController;
use App\Http\Controllers\Settings\GIT\GitPlatformController;
use App\Http\Controllers\Settings\GIT\GitPlatformRedirectController;
use App\Http\Controllers\Settings\GIT\GitProviderAppDestroyController;
use App\Http\Controllers\Settings\GIT\GitRepositoryBranchSyncController;
use App\Http\Controllers\Settings\GIT\GitRepositoryBrowseController;
use App\Http\Controllers\Settings\GIT\GitRepositoryDestroyController;
use App\Http\Controllers\Settings\GIT\GitRepositorySettingsController;
use App\Http\Controllers\Settings\GIT\GitRepositoryStoreController;
use App\Http\Controllers\Settings\Users\ProfileController;
use App\Http\Controllers\Settings\Users\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Settings routes
|--------------------------------------------------------------------------
|
| Split into three tiers:
|   1. Personal settings  - any authenticated user, acting on their own account.
|   2. AI provider config - holds API keys, so it needs ai-providers.manage.
|   3. Git integration    - holds app private keys and webhook secrets, so it
|                           needs integrations.manage.
|
*/

// ── 1. Personal account settings ────────────────────────────────────────────────
Route::middleware(['auth'])->group(function (): void {
    Route::redirect('user/settings', '/user/settings/profile');

    Route::get('user/settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('user/settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::delete('user/settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('user/settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('user/settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('user/settings/appearance', 'settings/appearance')->name('appearance.edit');
});

// ── 2. AI providers (stores encrypted API credentials) ──────────────────────────
Route::middleware(['auth', 'verified', 'permission:'.UserPermission::ManageAiProviders->value])
    ->prefix('settings/ai-providers')
    ->name('ai-providers.')
    ->group(function (): void {
        Route::get('/', [AiProviderController::class, 'edit'])->name('edit');
        Route::post('/', [AiProviderController::class, 'store'])->name('store');

        // Performs a live outbound call to the provider - throttled to stop it being
        // used as a credential-probing or egress oracle.
        Route::post('/test', [AiProviderController::class, 'test'])
            ->middleware('throttle:10,1')
            ->name('test');

        Route::put('/{aiProvider}', [AiProviderController::class, 'update'])->name('update');
        Route::put('/{aiProvider}/default', [AiProviderController::class, 'markDefault'])->name('default');
        Route::delete('/{aiProvider}', [AiProviderController::class, 'destroy'])->name('destroy');
    });

// ── 3. Git providers, apps and repositories (stores app private keys/secrets) ───
Route::middleware(['auth', 'verified', 'permission:'.UserPermission::ManageIntegrations->value])
    ->prefix('settings/git-providers')
    ->name('integrations.')
    ->group(function (): void {
        Route::get('/', [GitPlatformController::class, 'edit'])->name('edit');

        // GitHub App manifest flow. The callback carries a one-time state token and is
        // hit by the browser on redirect back from GitHub.
        Route::get('github/setup', GitHubAppManifestSetupController::class)->name('github.manifest.setup');
        Route::get('github/manifest/callback/{state}', GitHubAppManifestCallbackController::class)->name('github.manifest.callback');

        Route::post('apps/github/test', GitHubAppTestController::class)->middleware('throttle:10,1')->name('github.app.test');
        Route::post('apps/github/connect', GitHubAppConnectController::class)->name('github.app.connect');
        Route::post('apps/github/sync', GitHubAppSyncController::class)->middleware('throttle:10,1')->name('github.app.sync');
        Route::delete('apps/{provider}', GitProviderAppDestroyController::class)->name('apps.destroy');

        // OAuth handshake with the git platform.
        Route::get('{provider}/redirect', GitPlatformRedirectController::class)->name('redirect');
        Route::get('{provider}/callback', GitPlatformCallbackController::class)->name('callback');

        // Repository selection and per-repository review configuration.
        Route::get('{provider}/repositories', GitRepositoryBrowseController::class)->name('repositories.browse');
        Route::post('{provider}/repositories', GitRepositoryStoreController::class)->name('repositories.store');
        Route::get('repositories/{gitRepository}/settings', [GitRepositorySettingsController::class, 'edit'])->name('repositories.settings.edit');
        Route::put('repositories/{gitRepository}/settings', [GitRepositorySettingsController::class, 'update'])->name('repositories.settings.update');
        Route::post('repositories/{gitRepository}/branches/sync', GitRepositoryBranchSyncController::class)->middleware('throttle:10,1')->name('repositories.branches.sync');
        Route::delete('repositories/{gitRepository}', GitRepositoryDestroyController::class)->name('repositories.destroy');

        Route::delete('accounts/{gitAccount}', GitAccountDisconnectController::class)->name('destroy');
    });
