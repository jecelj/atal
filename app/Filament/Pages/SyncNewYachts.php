<?php

namespace App\Filament\Pages;

use App\Models\SyncSite;
use App\Models\NewYacht;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class SyncNewYachts extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Sync New Yachts';

    protected static ?string $navigationGroup = 'Sync';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.sync-new-yachts';

    public function syncToSite($siteId)
    {
        try {
            $site = SyncSite::findOrFail($siteId);
            $service = app(\App\Services\WordPressSyncService::class);

            $result = $service->syncSite($site, 'new');

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
            Log::error('New yacht sync failed: ' . $e->getMessage());

            Notification::make()
                ->title('Sync Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function syncSingleYacht($siteId, $yachtId)
    {
        // For now, trigger full sync for 'new' yachts
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
                $result = $service->syncSite($site, 'new');
                if ($result['success']) {
                    $count++;
                } else {
                    $errors[] = $site->name . ': ' . ($result['error'] ?? 'Unknown error');
                }
            }

            if (empty($errors)) {
                Notification::make()
                    ->title('Sync All Successful')
                    ->body("Synced new yachts to {$count} sites")
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
        return NewYacht::with(['brand', 'yachtModel'])
            ->where('state', 'published')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();
    }
}
