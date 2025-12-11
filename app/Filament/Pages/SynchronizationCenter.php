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

    public static function getNavigationItems(): array
    {
        // Check if there are any pending items
        $hasPending = \App\Models\SyncStatus::where('status', 'pending')->exists();
        $color = $hasPending ? 'warning' : 'success';

        return [
            \Filament\Navigation\NavigationItem::make(static::getNavigationLabel())
                ->group(static::getNavigationGroup())
                ->icon(static::getNavigationIcon())
                ->isActiveWhen(fn() => request()->routeIs(static::getRouteName()))
                ->sort(static::getNavigationSort())
                ->badge(static::getNavigationBadge(), color: static::getNavigationBadgeColor())
                ->url(static::getNavigationUrl()),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return \App\Models\SyncStatus::where('status', 'pending')->count() > 0 ? 'Needs Sync' : 'Synced';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return \App\Models\SyncStatus::where('status', 'pending')->exists() ? 'warning' : 'success';
    }

    public function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('sync_all')
                ->label('Sync All Sites')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->requiresConfirmation()
                ->action(function () {
                    $sessionKey = 'sync_all_' . uniqid();
                    // Trigger Sync Job (Synchronously)
                    \App\Jobs\SyncSitesJob::dispatchSync(null, $sessionKey);

                    \Filament\Notifications\Notification::make()
                        ->title('Sync Completed')
                        ->body('Synchronization for all sites finished successfully.')
                        ->success()
                        ->send();
                }),
            \Filament\Actions\Action::make('force_sync_all')
                ->label('Force Sync All')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Force Sync All Sites?')
                ->modalDescription('This will overwrite ALL data on all WordPress sites, ignoring the "pending" status. It might take a while.')
                ->action(function () {
                    $sessionKey = 'sync_all_force_' . uniqid();
                    // Trigger Sync Job (Synchronously, FORCE=true)
                    \App\Jobs\SyncSitesJob::dispatchSync(null, $sessionKey, true);

                    \Filament\Notifications\Notification::make()
                        ->title('Force Sync Completed')
                        ->body('Force synchronization for all sites finished successfully.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getViewData(): array
    {
        $sites = \App\Models\SyncSite::with('syncStatuses')->orderBy('order')->get();

        foreach ($sites as $site) {
            $pending = $site->syncStatuses->where('status', 'pending')->count();
            $failed = $site->syncStatuses->where('status', 'failed')->count();

            if ($failed > 0) {
                $site->ui_status = 'error';
                $site->ui_status_label = 'Error';
                $site->ui_status_color = 'danger';
            } elseif ($pending > 0) {
                $site->ui_status = 'warning';
                $site->ui_status_label = 'Needs Sync';
                $site->ui_status_color = 'warning';
            } else {
                $site->ui_status = 'success';
                $site->ui_status_label = 'Up to date';
                $site->ui_status_color = 'success';
            }
        }

        return [
            'sites' => $sites,
        ];
    }

    // getGlobalStats removed as requested

    public function syncSite($siteId)
    {
        $site = \App\Models\SyncSite::find($siteId);
        if (!$site)
            return;

        // Dispatch Job specifically for this site (FORCE sync, SYNCHRONOUSLY)
        $sessionKey = 'sync_site_' . $siteId . '_' . uniqid();

        // Use dispatchSync to avoid Queue Worker issues (stale code, not running, etc.)
        // Force is FALSE by default to respect "Pending/Dirty" logic (Differential Sync)
        \App\Jobs\SyncSitesJob::dispatchSync($siteId, $sessionKey, false);

        \Filament\Notifications\Notification::make()
            ->title("Sync Completed for {$site->name}")
            ->body('Synchronization finished successfully.')
            ->success()
            ->send();
    }

    public function forceSyncSite($siteId)
    {
        $site = \App\Models\SyncSite::find($siteId);
        if (!$site)
            return;

        $sessionKey = 'sync_site_force_' . $siteId . '_' . uniqid();
        // FORCE SYNC = true
        \App\Jobs\SyncSitesJob::dispatchSync($siteId, $sessionKey, true);

        \Filament\Notifications\Notification::make()
            ->title("Force Sync Completed for {$site->name}")
            ->body('Force synchronization finished successfully.')
            ->success()
            ->send();
    }
}
