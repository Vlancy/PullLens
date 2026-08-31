<?php

use App\Enums\Users\UserPermission;
use App\Http\Controllers\Reports\CommitsReportController;
use App\Http\Controllers\Reports\DailyReportController;
use App\Http\Controllers\Reports\DeveloperDailyReportController;
use App\Http\Controllers\Reports\DeveloperProfileReportController;
use App\Http\Controllers\Reports\DevelopersReportController;
use App\Http\Controllers\Reports\OverviewReportController;
use App\Http\Controllers\Reports\RepositoriesReportController;
use App\Http\Controllers\Reports\RepositoryFindingsRedirectController;
use App\Http\Controllers\Reports\SyncCommitStatsController;
use App\Http\Controllers\Reports\TaskReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reporting routes
|--------------------------------------------------------------------------
|
| Read-only analytics pages. Each page is a single-action controller so that a
| report can gain its own request validation and dependencies without growing a
| shared controller (Single Responsibility / Interface Segregation).
|
| These reports aggregate across every repository — developer seniority, commit
| quality and daily throughput are only meaningful installation-wide — so they
| additionally require `repositories.view-all`. A user scoped to a subset of
| repositories uses the dashboard, repositories and findings pages instead, all of
| which narrow correctly to their grants.
|
*/

Route::middleware([
    'auth',
    'verified',
    'permission:'.UserPermission::ViewReports->value,
    'permission:'.UserPermission::ViewAllRepositories->value,
])
    ->prefix('reports')
    ->name('reports.')
    ->group(function (): void {
        Route::get('/', OverviewReportController::class)->name('overview');
        Route::get('/developers', DevelopersReportController::class)->name('developers');
        Route::get('/developers/{login}', DeveloperProfileReportController::class)->name('developer-profile');
        Route::get('/repositories', RepositoriesReportController::class)->name('repositories');
        Route::get('/repositories/{gitRepository}/findings', RepositoryFindingsRedirectController::class)->name('repository-findings');
        Route::get('/tasks', TaskReportController::class)->name('tasks');
        Route::get('/commits', CommitsReportController::class)->name('commits');
        Route::get('/daily', DailyReportController::class)->name('daily');
        Route::get('/developer-daily', DeveloperDailyReportController::class)->name('developer-daily');
    });

// Queues background work and therefore costs API quota — throttled and held to a
// higher permission than the read-only report pages above.
Route::middleware([
    'auth',
    'verified',
    'permission:'.UserPermission::TriggerReviews->value,
    'permission:'.UserPermission::ViewAllRepositories->value,
    'throttle:6,1',
])
    ->prefix('reports')
    ->name('reports.')
    ->group(function (): void {
        Route::post('/sync-commit-stats', SyncCommitStatsController::class)->name('sync-commit-stats');
    });
