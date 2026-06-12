<?php

use App\Jobs\GIT\SyncFindingReactions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('telescope:prune --hours=168')->weekly();
Schedule::job(new SyncFindingReactions)->hourly();
