<?php

use App\Http\Controllers\Findings\FindingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->name('findings.')->group(function () {
    Route::get('/findings', [FindingsController::class, 'index'])->name('index');
});
