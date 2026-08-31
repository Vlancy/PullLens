<?php

use App\Enums\Users\UserPermission;
use App\Http\Controllers\Findings\FindingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'permission:'.UserPermission::ViewFindings->value])
    ->name('findings.')
    ->group(function (): void {
        Route::get('/findings', FindingsController::class)->name('index');
    });
