<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Register Event Listener for WebP conversion
        // DISABLED: Automatic optimization is disabled. Use manual "Optimize Images" button instead.
        // \Illuminate\Support\Facades\Event::listen(
        //     \Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent::class,
        //     \App\Listeners\ConvertImageToWebP::class
        // );

        // Register Sync Observers
        \App\Models\NewYacht::observe(\App\Observers\SyncObserver::class);
        \App\Models\UsedYacht::observe(\App\Observers\SyncObserver::class);
        \App\Models\News::observe(\App\Observers\SyncObserver::class);
    }
}
