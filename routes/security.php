<?php

use App\Enums\Users\UserPermission;
use App\Http\Controllers\Security\SecurityFindingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'permission:'.UserPermission::ViewFindings->value])
    ->name('security.')
    ->group(function (): void {
        Route::get('/security', SecurityFindingsController::class)->name('index');
    });
