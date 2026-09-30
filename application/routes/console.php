<?php

use App\Services\ManagedTasks;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

foreach (app(ManagedTasks::class)->all() as $key => $task) {
    if (! $task['enabled']) {
        continue;
    }
    Schedule::command($task['command'])->cron($task['schedule'])->timezone('Asia/Kolkata')->withoutOverlapping()->runInBackground()
        ->before(fn () => app(ManagedTasks::class)->record($key, 'running'))
        ->onSuccess(fn () => app(ManagedTasks::class)->record($key, 'success'))
        ->onFailure(fn () => app(ManagedTasks::class)->record($key, 'failed'));
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
