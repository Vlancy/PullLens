<?php

use App\Jobs\GIT\SyncFindingReactions;
use App\Models\Users\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pulllens:users-exist', function () {
    $this->line(User::query()->exists() ? 'yes' : 'no');
})->purpose('Check whether PullLens has any users');

Schedule::command('telescope:prune --hours=168')->weekly();
Schedule::job(new SyncFindingReactions)->hourly();
