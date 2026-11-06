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
        ->onSuccess(fn () => $key === 'election_sync' ? null : app(ManagedTasks::class)->record($key, 'success'))
        ->onFailure(fn () => app(ManagedTasks::class)->record($key, 'failed'));
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('farmers:prune')->daily()->withoutOverlapping();

Artisan::command('seo:build-sitemaps', function () {
    $this->info(json_encode(app(\App\Services\PublicSitemaps::class)->build(), JSON_THROW_ON_ERROR));
})->purpose('Publish public sitemap files atomically');

Schedule::command('seo:build-sitemaps')->dailyAt('03:40')->timezone('Asia/Kolkata')->withoutOverlapping()->runInBackground();
