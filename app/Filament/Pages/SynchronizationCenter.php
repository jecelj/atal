<?php

namespace App\Filament\Pages;

use App\Models\WordPressSyncOutbox;
use Illuminate\Support\Facades\DB;
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
                        ->title('Sync queued')
                        ->body('Items will be sent in the background, one media file at a time.')
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
                        ->title('Force sync queued')
                        ->body('Items will be reconciled in the background.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getViewData(): array
    {
        $sites = \App\Models\SyncSite::with('syncStatuses')->orderBy('order')->get();
        $outboxCounts = WordPressSyncOutbox::query()
            ->select('sync_site_id', 'state', DB::raw('count(*) as total'))
            ->groupBy('sync_site_id', 'state')
            ->get()
            ->groupBy('sync_site_id');

        foreach ($sites as $site) {
            $pending = $site->syncStatuses->where('status', 'pending')->count();
            $failed = $site->syncStatuses->where('status', 'failed')->count();
            $states = $outboxCounts->get($site->id, collect())->pluck('total', 'state');

            $site->sync_progress = [
                'pending' => (int) ($states->get('pending') ?? 0),
                'media' => (int) ($states->get('media') ?? 0),
                'completed' => (int) ($states->get('completed') ?? 0),
                'failed' => (int) ($states->get('failed') ?? 0),
            ];

            if ($site->sync_progress['failed'] > 0 || $failed > 0) {
                $site->ui_status = 'error';
                $site->ui_status_label = 'Error';
                $site->ui_status_color = 'danger';
            } elseif ($site->sync_progress['pending'] > 0) {
                $site->ui_status = 'processing';
                $site->ui_status_label = 'Sending data';
                $site->ui_status_color = 'warning';
            } elseif ($site->sync_progress['media'] > 0) {
                $site->ui_status = 'processing';
                $site->ui_status_label = 'Syncing media';
                $site->ui_status_color = 'info';
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

        // This only determines changed records; transfers run through wordpress-sync.
        $sessionKey = 'sync_site_' . $siteId . '_' . uniqid();

        \App\Jobs\SyncSitesJob::dispatchSync($siteId, $sessionKey, false);

        \Filament\Notifications\Notification::make()
            ->title("Sync queued for {$site->name}")
            ->body('Changed records will be processed in the background.')
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
            ->title("Force sync queued for {$site->name}")
            ->body('All eligible records will be reconciled in the background.')
            ->success()
            ->send();
    }
}
