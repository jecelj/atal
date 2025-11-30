<?php

namespace App\Filament\Pages;

use App\Models\SyncSite;
use App\Models\News;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class SyncAll extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'Sync All';

    protected static ?string $navigationGroup = 'Sync';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.sync-all';

    public function syncAll()
    {
        try {
            $service = app(\App\Services\WordPressSyncService::class);

            // 1. Sync Yachts (New + Used)
            $yachtResults = $service->syncAll(); // This iterates all sites and calls syncSite (which syncs New + Used)

            // 2. Sync News
            $newsItems = News::where('is_active', true)->get();
            $newsCount = 0;
            foreach ($newsItems as $news) {
                $service->syncNews($news);
                $newsCount++;
            }

            Notification::make()
                ->title('Full Sync Successful')
                ->body("Synced all yachts and {$newsCount} news items to all sites.")
                ->success()
                ->send();

        } catch (\Exception $e) {
            Log::error('Full sync failed: ' . $e->getMessage());

            Notification::make()
                ->title('Sync Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
