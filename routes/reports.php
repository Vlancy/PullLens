<?php

use App\Http\Controllers\Reports\ReportsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('reports')->name('reports.')->group(function () {
    Route::get('/', [ReportsController::class, 'overview'])->name('overview');
    Route::get('/developers', [ReportsController::class, 'developers'])->name('developers');
    Route::get('/developers/{login}', [ReportsController::class, 'developerProfile'])->name('developer-profile');
    Route::get('/repositories', [ReportsController::class, 'repositories'])->name('repositories');
    Route::get('/repositories/{gitRepository}/findings', function (\App\Models\GIT\GitRepository $gitRepository) {
        return redirect()->route('findings.index', ['repository_id' => $gitRepository->id, 'status' => 'all']);
    })->name('repository-findings');
    Route::get('/commits', [ReportsController::class, 'commits'])->name('commits');
    Route::get('/daily', [ReportsController::class, 'daily'])->name('daily');
    Route::get('/developer-daily', [ReportsController::class, 'developerDaily'])->name('developer-daily');
    Route::post('/sync-commit-stats', [ReportsController::class, 'syncCommitStats'])->name('sync-commit-stats');
});
