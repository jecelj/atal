<?php

namespace App\Filament\Pages;

use App\Models\SyncSite;
use App\Models\UsedYacht;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncUsedYachts extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Sync Used Yachts';

    protected static ?string $navigationGroup = 'Sync Sites';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.sync-used-yachts';

    public function syncToSite($siteId)
    {
        try {
            $site = SyncSite::findOrFail($siteId);

            $response = Http::timeout(300)->post(
                url("/api/used-yachts/sync/{$siteId}")
            );

            if ($response->successful()) {
                $result = $response->json();

                Notification::make()
                    ->title('Sync Successful')
                    ->body("Synced {$result['imported']} used yachts to {$site->name}")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Sync Failed')
                    ->body($response->body())
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            Log::error('Used yacht sync failed: ' . $e->getMessage());

            Notification::make()
                ->title('Sync Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function syncSingleYacht($siteId, $yachtId)
    {
        try {
            $site = SyncSite::findOrFail($siteId);
            $yacht = UsedYacht::findOrFail($yachtId);

            $response = Http::timeout(300)->post(
                url("/api/used-yachts/sync/{$siteId}/yacht/{$yachtId}")
            );

            if ($response->successful()) {
                $result = $response->json();

                Notification::make()
                    ->title('Test Sync Successful')
                    ->body("Synced '{$yacht->name}' to {$site->name}")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Test Sync Failed')
                    ->body($response->body())
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            Log::error('Single yacht sync failed: ' . $e->getMessage());

            Notification::make()
                ->title('Sync Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function syncAllSites()
    {
        try {
            $response = Http::timeout(600)->post(url('/api/used-yachts/sync-all'));

            if ($response->successful()) {
                $result = $response->json();

                Notification::make()
                    ->title('Sync All Successful')
                    ->body('Synced used yachts to all active sites')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Sync All Failed')
                    ->body($response->body())
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            Log::error('Sync all failed: ' . $e->getMessage());

            Notification::make()
                ->title('Sync Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getSites()
    {
        return SyncSite::where('is_active', true)->orderBy('name')->get();
    }

    public function getYachts()
    {
        return UsedYacht::with(['brand', 'yachtModel'])
            ->where('state', 'published')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();
    }
}
