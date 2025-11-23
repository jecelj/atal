<?php

namespace App\Filament\Resources\SyncSiteResource\Pages;

use App\Filament\Resources\SyncSiteResource;
use App\Services\WordPressSyncService;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Filament\Notifications\Notification;

class ManageSyncSites extends ManageRecords
{
    protected static string $resource = SyncSiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync_all')
                ->label('Sync All Sites')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->modalHeading('Syncing All Sites')
                ->modalDescription('Please wait while we sync all active sites...')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('2xl')
                ->modalContent(function () {
                    $sessionKey = 'sync_progress_' . uniqid();

                    // Dispatch the job
                    \App\Jobs\SyncSitesJob::dispatch(null, $sessionKey);

                    // Return the Livewire component
                    return view('components.sync-modal-content', [
                        'sessionKey' => $sessionKey,
                    ]);
                })
                ->action(fn() => null), // No action needed, job is dispatched in modalContent
            Actions\CreateAction::make(),
        ];
    }

}
