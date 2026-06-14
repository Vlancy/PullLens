<?php

use App\Http\Controllers\Assistant\AssistantChatController;
use App\Http\Controllers\Assistant\AssistantHistoryClearController;
use App\Http\Controllers\Assistant\AssistantPageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('assistant')->name('assistant.')->group(function () {
    Route::get('/', AssistantPageController::class)->name('index');
    Route::post('/chat', AssistantChatController::class)->name('chat');
    Route::delete('/history', AssistantHistoryClearController::class)->name('history.clear');
});
