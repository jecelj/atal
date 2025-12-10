<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class SynchronizationCenter extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';
    protected static ?string $navigationGroup = 'Synchronization';
    protected static ?string $title = 'Synchronization Center';
    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.synchronization-center';

    public function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('sync_all')
                ->label('Sync All Sites')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->requiresConfirmation()
                ->action(function () {
                    // Trigger Sync Job
                    \App\Jobs\SyncSitesJob::dispatch();

                    \Filament\Notifications\Notification::make()
                        ->title('Sync Started')
                        ->body('Synchronization for all sites has been queued.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getViewData(): array
    {
        return [
            'sites' => \App\Models\SyncSite::orderBy('order')->get(),
            'stats' => $this->getGlobalStats(),
        ];
    }

    protected function getGlobalStats(): array
    {
        $totalItems = \App\Models\SyncStatus::count();
        $synced = \App\Models\SyncStatus::where('status', 'synced')->count();
        $failed = \App\Models\SyncStatus::where('status', 'failed')->count();

        return [
            'total' => $totalItems,
            'synced' => $synced,
            'failed' => $failed,
            'pending' => $totalItems - $synced - $failed,
        ];
    }

    public function syncSite($siteId)
    {
        $site = \App\Models\SyncSite::find($siteId);
        if (!$site)
            return;

        // Dispatch Job specifically for this site
        \App\Jobs\SyncSitesJob::dispatch($siteId);

        \Filament\Notifications\Notification::make()
            ->title("Sync Started for {$site->name}")
            ->body('Synchronization for this site has been queued.')
            ->success()
            ->send();
    }
}
