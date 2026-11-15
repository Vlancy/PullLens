<?php

use App\Enums\Users\UserPermission;
use App\Http\Controllers\Tasks\TaskBoardController;
use App\Http\Controllers\Tasks\TaskFeatureAssignmentController;
use App\Http\Controllers\Tasks\TaskFeatureController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tasks
|--------------------------------------------------------------------------
|
| The tasks board is a top-level destination rather than a report. Reports answer
| "how did we do this month"; this answers "what happened to this piece of work",
| and is reached by search rather than by period.
|
| Deliberately not gated on repositories.view-all: the board narrows correctly to a
| scoped user's grants, so a contributor can follow the history of the repositories
| they actually work on.
|
*/

Route::middleware(['auth', 'verified', 'permission:'.UserPermission::ViewTasks->value])
    ->group(function (): void {
        Route::get('tasks', TaskBoardController::class)->name('tasks.index');

        // Feature corrections change shared grouping, so they need repository management.
        Route::middleware('permission:'.UserPermission::ManageRepositories->value)
            ->group(function (): void {
                Route::put('tasks/features/{taskFeature}', [TaskFeatureController::class, 'update'])->name('tasks.features.update');
                Route::post('tasks/features/{taskFeature}/merge', [TaskFeatureController::class, 'merge'])->name('tasks.features.merge');
                Route::put('tasks/{pullRequestTask}/feature', TaskFeatureAssignmentController::class)->name('tasks.feature.update');
            });
    });
