<?php

use App\Enums\Users\UserPermission;
use App\Enums\Users\UserRole;
use App\Http\Controllers\Admin\FindingBulkResolveController;
use App\Http\Controllers\Admin\FindingResolveController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Administration routes
|--------------------------------------------------------------------------
|
| Everything here mutates privileged state. Authentication alone is not
| sufficient: each group additionally requires an explicit role or permission.
|
*/

// User administration - administrators only. Creating or deleting accounts is the
// highest-privilege action in the app, so it is gated on the role, not a permission
// that could be granted to a lesser role by mistake.
Route::middleware(['auth', 'verified', 'role:'.UserRole::Admin->value])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        // Role/permission matrix. `{role}` is constrained to the enum's values so an
        // unknown role never reaches the controller.
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::put('roles/{role}', [RoleController::class, 'update'])
            ->whereIn('role', UserRole::values())
            ->name('roles.update');
    });

// Finding triage - available to managers as well as administrators.
Route::middleware(['auth', 'verified', 'permission:'.UserPermission::ResolveFindings->value])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::post('findings/bulk-resolve', FindingBulkResolveController::class)->name('findings.bulk-resolve');
        Route::post('findings/{finding}/resolve', FindingResolveController::class)->name('findings.resolve');
    });
