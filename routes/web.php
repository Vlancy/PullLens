<?php

use App\Http\Controllers\Webhooks\GIT\GitHubWebhookController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

Route::post('webhooks/github', GitHubWebhookController::class)->name('webhooks.github');

require __DIR__.'/settings.php';
