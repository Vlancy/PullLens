<?php

use App\Http\Controllers\Settings\GIT\GitAccountDisconnectController;
use App\Http\Controllers\Settings\GIT\GitHubAppManifestCallbackController;
use App\Http\Controllers\Settings\GIT\GitHubAppManifestSetupController;
use App\Http\Controllers\Settings\GIT\GitPlatformCallbackController;
use App\Http\Controllers\Settings\GIT\GitPlatformController;
use App\Http\Controllers\Settings\GIT\GitPlatformRedirectController;
use App\Http\Controllers\Settings\GIT\GitProviderAppDestroyController;
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
    Route::get('settings/integrations', [GitPlatformController::class, 'edit'])->name('integrations.edit');
    Route::get('settings/integrations/github/setup', GitHubAppManifestSetupController::class)->name('integrations.github.manifest.setup');
    Route::get('settings/integrations/github/manifest/callback/{state}', GitHubAppManifestCallbackController::class)->name('integrations.github.manifest.callback');
    Route::get('settings/integrations/{provider}/redirect', GitPlatformRedirectController::class)->name('integrations.redirect');
    Route::get('settings/integrations/{provider}/callback', GitPlatformCallbackController::class)->name('integrations.callback');
    Route::delete('settings/integrations/apps/{provider}', GitProviderAppDestroyController::class)->name('integrations.apps.destroy');
    Route::get('settings/integrations/{provider}/repositories', GitRepositoryBrowseController::class)->name('integrations.repositories.browse');
    Route::post('settings/integrations/{provider}/repositories', GitRepositoryStoreController::class)->name('integrations.repositories.store');
    Route::get('settings/integrations/repositories/{gitRepository}/settings', [GitRepositorySettingsController::class, 'edit'])->name('integrations.repositories.settings.edit');
    Route::put('settings/integrations/repositories/{gitRepository}/settings', [GitRepositorySettingsController::class, 'update'])->name('integrations.repositories.settings.update');
    Route::delete('settings/integrations/repositories/{gitRepository}', GitRepositoryDestroyController::class)->name('integrations.repositories.destroy');
    Route::delete('settings/integrations/accounts/{gitAccount}', GitAccountDisconnectController::class)->name('integrations.destroy');

    Route::delete('user/settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('user/settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('user/settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('user/settings/appearance', 'settings/appearance')->name('appearance.edit');
});
