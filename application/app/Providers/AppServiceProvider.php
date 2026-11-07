<?php

namespace App\Providers;

use App\Services\ArchiveFiles;
use App\Services\ConstituencyUrls;
use App\Services\DistrictDashboardUrls;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ArchiveFiles::class);
        $this->app->scoped(ConstituencyUrls::class);
        $this->app->scoped(DistrictDashboardUrls::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer(['*-source-district-map', '*-source-village-map', 'modern-district-source-map', 'ladakh-current-district'], function (\Illuminate\View\View $view) {
            if (request()->is('india/districts/*/dashboard') && request()->query('view') !== 'details'
                && request()->query('topic', 'overview') === 'overview'
                && request()->except(['lang', 'topic', 'view', 'utm_source', 'utm_medium', 'utm_campaign']) === []) {
                $view->setPath(app('view.finder')->find('district-source-summary'));
            }
        });
        $this->app->terminating(fn () => app(ArchiveFiles::class)->cleanup());
        foreach (['sir-vision' => 2, 'sir-decisions' => 20, 'sir-imports' => 10, 'sir-upload-chunks' => 400] as $name => $maximum) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($maximum)->by($name.':'.$request->user()?->id));
        }
        Queue::after(fn () => app(ArchiveFiles::class)->cleanup());
    }
}
