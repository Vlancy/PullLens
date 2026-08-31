<?php

use App\Enums\Users\UserPermission;
use App\Http\Controllers\Tasks\TaskBoardController;
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
    });
