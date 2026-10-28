<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('pbx:health')->everyMinute()->withoutOverlapping();
// Re-push PBX state nightly: drops one-off holidays that have passed and heals any drift.
Schedule::command('pbx:sync')->dailyAt('00:30')->withoutOverlapping()->onOneServer();
Schedule::command('billing:run')->dailyAt('01:00')->withoutOverlapping()->onOneServer();
