<?php

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

Route::middleware(['auth'])->group(function () {
    Route::redirect('user/settings', '/user/settings/profile');

    Route::get('user/settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('user/settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/ai-providers', [AiProviderController::class, 'edit'])->name('ai-providers.edit');
    Route::post('settings/ai-providers', [AiProviderController::class, 'store'])->name('ai-providers.store');
    Route::post('settings/ai-providers/test', [AiProviderController::class, 'test'])->name('ai-providers.test');
    Route::put('settings/ai-providers/{aiProvider}', [AiProviderController::class, 'update'])->name('ai-providers.update');
    Route::put('settings/ai-providers/{aiProvider}/default', [AiProviderController::class, 'markDefault'])->name('ai-providers.default');
    Route::delete('settings/ai-providers/{aiProvider}', [AiProviderController::class, 'destroy'])->name('ai-providers.destroy');

    Route::get('settings/git-providers', [GitPlatformController::class, 'edit'])->name('integrations.edit');
    Route::get('settings/git-providers/github/setup', GitHubAppManifestSetupController::class)->name('integrations.github.manifest.setup');
    Route::get('settings/git-providers/github/manifest/callback/{state}', GitHubAppManifestCallbackController::class)->name('integrations.github.manifest.callback');
    Route::post('settings/git-providers/apps/github/test', GitHubAppTestController::class)->name('integrations.github.app.test');
    Route::post('settings/git-providers/apps/github/connect', GitHubAppConnectController::class)->name('integrations.github.app.connect');
    Route::post('settings/git-providers/apps/github/sync', GitHubAppSyncController::class)->name('integrations.github.app.sync');
    Route::get('settings/git-providers/{provider}/redirect', GitPlatformRedirectController::class)->name('integrations.redirect');
    Route::get('settings/git-providers/{provider}/callback', GitPlatformCallbackController::class)->name('integrations.callback');
    Route::delete('settings/git-providers/apps/{provider}', GitProviderAppDestroyController::class)->name('integrations.apps.destroy');
    Route::get('settings/git-providers/{provider}/repositories', GitRepositoryBrowseController::class)->name('integrations.repositories.browse');
    Route::post('settings/git-providers/{provider}/repositories', GitRepositoryStoreController::class)->name('integrations.repositories.store');
    Route::get('settings/git-providers/repositories/{gitRepository}/settings', [GitRepositorySettingsController::class, 'edit'])->name('integrations.repositories.settings.edit');
    Route::put('settings/git-providers/repositories/{gitRepository}/settings', [GitRepositorySettingsController::class, 'update'])->name('integrations.repositories.settings.update');
    Route::post('settings/git-providers/repositories/{gitRepository}/branches/sync', GitRepositoryBranchSyncController::class)->name('integrations.repositories.branches.sync');
    Route::delete('settings/git-providers/repositories/{gitRepository}', GitRepositoryDestroyController::class)->name('integrations.repositories.destroy');
    Route::delete('settings/git-providers/accounts/{gitAccount}', GitAccountDisconnectController::class)->name('integrations.destroy');

    Route::delete('user/settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('user/settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('user/settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('user/settings/appearance', 'settings/appearance')->name('appearance.edit');
});
