<?php

namespace App\Providers;

use App\Services\ArchiveFiles;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ArchiveFiles::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->terminating(fn () => app(ArchiveFiles::class)->cleanup());
        Queue::after(fn () => app(ArchiveFiles::class)->cleanup());
    }
}
