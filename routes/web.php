<?php

use App\Enums\Users\UserPermission;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Repositories\RepositoryIndexController;
use App\Http\Controllers\Repositories\RepositoryShowController;
use App\Http\Controllers\Repositories\RepositorySyncReviewsController;
use App\Http\Controllers\Webhooks\GIT\GitHubWebhookController;
use App\Http\Controllers\Welcome\WelcomeController;
use App\Http\Middleware\GIT\VerifyGitHubWebhookSignature;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Feature areas live in their own route files (see the requires at the bottom).
| Only the landing page, dashboard, repository browsing and the inbound webhook
| are declared here.
|
*/

Route::get('/', WelcomeController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Deliberately gated on findings.view rather than reports.view: a Contributor
    // scoped to a few repositories must still be able to browse them. Which
    // repositories they actually see is decided by RepositoryScope and the policy.
    Route::middleware('permission:'.UserPermission::ViewFindings->value)->group(function (): void {
        Route::get('repositories', RepositoryIndexController::class)->name('repositories.index');
        Route::get('repositories/{gitRepository}', RepositoryShowController::class)->name('repositories.show');
    });

    // Dispatches AI review jobs — costs provider credit, so it is both permissioned
    // and rate limited per user.
    Route::post('repositories/{gitRepository}/sync-reviews', RepositorySyncReviewsController::class)
        ->middleware(['permission:'.UserPermission::TriggerReviews->value, 'throttle:6,1'])
        ->name('repositories.sync-reviews');
});

/*
| Inbound provider webhooks
|
| Unauthenticated by design and CSRF-exempt (see bootstrap/app.php); the request is
| authenticated inside the controller by verifying the provider's HMAC signature.
| The throttle is deliberately generous — GitHub bursts on large pushes — but caps
| the damage an unauthenticated flood can do.
*/
Route::post('webhooks/github', GitHubWebhookController::class)
    ->middleware([VerifyGitHubWebhookSignature::class, 'throttle:webhooks'])
    ->name('webhooks.github');

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
require __DIR__.'/findings.php';
require __DIR__.'/tasks.php';
require __DIR__.'/reports.php';
require __DIR__.'/assistant.php';
