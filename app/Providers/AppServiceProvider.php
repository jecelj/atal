<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
        // Global Date Format
        \Filament\Tables\Table::configureUsing(function (\Filament\Tables\Table $table): void {
            $table->dateDisplayFormat('d.m.Y');
            $table->dateTimeDisplayFormat('d.m.Y H:i');
        });

        // Register Event Listener for WebP conversion
        \Illuminate\Support\Facades\Event::listen(
            \Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent::class,
            \App\Listeners\ConvertImageToWebP::class
        );
    }
}
