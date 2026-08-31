<?php

use App\Enums\Users\UserPermission;
use App\Http\Controllers\Assistant\AssistantChatController;
use App\Http\Controllers\Assistant\AssistantHistoryClearController;
use App\Http\Controllers\Assistant\AssistantPageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'permission:'.UserPermission::UseAssistant->value])
    ->prefix('assistant')
    ->name('assistant.')
    ->group(function (): void {
        Route::get('/', AssistantPageController::class)->name('index');

        // Every call spends AI provider credit, so it carries its own named rate limiter
        // (see AppServiceProvider::configureRateLimiting) on top of the permission check.
        Route::post('/chat', AssistantChatController::class)
            ->middleware('throttle:assistant')
            ->name('chat');

        Route::delete('/history', AssistantHistoryClearController::class)->name('history.clear');
    });
