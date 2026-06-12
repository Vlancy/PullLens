<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Repositories\RepositoryIndexController;
use App\Http\Controllers\Repositories\RepositoryShowController;
use App\Http\Controllers\Webhooks\GIT\GitHubWebhookController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('repositories', RepositoryIndexController::class)->name('repositories.index');
    Route::get('repositories/{gitRepository}', RepositoryShowController::class)->name('repositories.show');
});

Route::post('webhooks/github', GitHubWebhookController::class)->name('webhooks.github');

require __DIR__.'/settings.php';
