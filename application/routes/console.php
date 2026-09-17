<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('imports:refresh --due')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('reports:archive-due')->dailyAt('23:50')->timezone('Asia/Kolkata')->withoutOverlapping()->runInBackground();
Schedule::command('queue:work database --queue=imports --stop-when-empty --max-time=50 --timeout=60 --tries=1')
    ->everyMinute()->withoutOverlapping()->runInBackground();

if (config('source-monitor.enabled')) {
    Schedule::command('sources:check')->dailyAt('06:00')->timezone('Asia/Kolkata')->withoutOverlapping();
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
