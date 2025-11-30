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

    protected static ?string $navigationGroup = 'Migration';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.sync-used-yachts';

    public function syncToSite($siteId)
    {
        try {
            $site = SyncSite::findOrFail($siteId);
            $service = app(\App\Services\WordPressSyncService::class);

            $result = $service->syncSite($site, 'used');

            if ($result['success']) {
                Notification::make()
                    ->title('Sync Successful')
                    ->body($result['message'])
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Sync Failed')
                    ->body($result['error'] ?? 'Unknown error')
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
        // Note: WordPressSyncService currently doesn't support syncing a SINGLE yacht by ID via push.
        // It triggers a full pull from WP.
        // For now, we will trigger a full sync for 'used' yachts, which is safer.
        // Or we could implement single sync in the service later.

        $this->syncToSite($siteId);
    }

    public function syncAllSites()
    {
        try {
            $sites = SyncSite::where('is_active', true)->get();
            $service = app(\App\Services\WordPressSyncService::class);
            $count = 0;
            $errors = [];

            foreach ($sites as $site) {
                $result = $service->syncSite($site, 'used');
                if ($result['success']) {
                    $count++;
                } else {
                    $errors[] = $site->name . ': ' . ($result['error'] ?? 'Unknown error');
                }
            }

            if (empty($errors)) {
                Notification::make()
                    ->title('Sync All Successful')
                    ->body("Synced used yachts to {$count} sites")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Sync Completed with Errors')
                    ->body("Synced to {$count} sites. Errors: " . implode(', ', $errors))
                    ->warning()
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
