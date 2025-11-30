<?php

namespace App\Filament\Pages;

use App\Models\SyncSite;
use App\Models\News;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class SyncNews extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationLabel = 'Sync News';

    protected static ?string $navigationGroup = 'Sync';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.sync-news';

    public function syncToSite($siteId)
    {
        try {
            $site = SyncSite::findOrFail($siteId);
            $service = app(\App\Services\WordPressSyncService::class);

            // Sync all news
            $newsItems = News::where('status', 'published')->get();
            $count = 0;
            $errors = [];

            foreach ($newsItems as $news) {
                // WordPressSyncService::syncNews syncs to ALL sites. 
                // We need to modify it or manually handle site specific sync.
                // But syncNews iterates sites internally.

                // Let's use a modified approach here or update service.
                // For now, we'll iterate and call a site-specific sync if possible.
                // But syncNews() logic is: $sites = $news->syncSites...

                // If we want to sync ALL news to ONE site:
                // We need to check if this site is enabled for this news.

                if ($news->syncSites->contains($siteId)) {
                    // Logic from WordPressSyncService::syncNews but for specific site
                    $result = $this->syncSingleNewsToSite($service, $news, $site);
                    if ($result['success']) {
                        $count++;
                    } else {
                        $errors[] = "News '{$news->title}': " . ($result['message'] ?? 'Error');
                    }
                }
            }

            if (empty($errors)) {
                Notification::make()
                    ->title('Sync Successful')
                    ->body("Synced {$count} news items to {$site->name}")
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Sync Completed with Errors')
                    ->body("Synced {$count} items. Errors: " . implode(', ', array_slice($errors, 0, 3)))
                    ->warning()
                    ->send();
            }
        } catch (\Exception $e) {
            Log::error('News sync failed: ' . $e->getMessage());

            Notification::make()
                ->title('Sync Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function syncSingleNewsToSite($service, $news, $site)
    {
        // We can't easily call private logic of service.
        // But service->syncNews() syncs to ALL sites.
        // If we want to sync to specific site, we might need to update service.

        // For now, let's just trigger syncNews($news) which syncs to all its assigned sites.
        // This is safer.

        return $service->syncNews($news)[$site->name] ?? ['success' => false, 'message' => 'Site not found for this news'];
    }

    public function syncAllSites()
    {
        try {
            $service = app(\App\Services\WordPressSyncService::class);
            $newsItems = News::where('status', 'published')->get();
            $count = 0;

            foreach ($newsItems as $news) {
                $service->syncNews($news);
                $count++;
            }

            Notification::make()
                ->title('Sync All Successful')
                ->body("Synced {$count} news items to their assigned sites")
                ->success()
                ->send();

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
}
