<?php

namespace App\Filament\Resources\CharterYachtResource\Pages;

use App\Filament\Resources\CharterYachtResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCharterYachts extends ListRecords
{
    protected static string $resource = CharterYachtResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('checkStatus')
                ->label('Check Status')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->action(function () {
                    $records = \App\Models\CharterYacht::all();
                    $service = new \App\Services\StatusCheckService();

                    foreach ($records as $record) {
                        $service->checkAndUpdateStatus($record);
                    }

                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title('Status Checked')
                        ->body('All records have been updated.')
                        ->send();
                }),
            Actions\Action::make('syncToWordPress')
                ->label('Sync to WordPress')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->modalHeading('Syncing All Sites')
                ->modalDescription('Please wait while we sync all active sites...')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('2xl')
                ->modalContent(function () {
                    $sessionKey = 'sync_progress_' . uniqid();

                    // Return the Livewire component which will trigger the sync on init
                    // We need to pass type='charter_yacht' if the sync component supports it, 
                    // otherwise it defaults to 'used_yacht' or similar. 
                    // Let's check `sync-modal-content` or `SyncProgress` component.
                    return view('components.sync-modal-content', [
                        'sessionKey' => $sessionKey,
                        'type' => 'charter_yacht' // Passing type just in case
                    ]);
                })
                ->action(fn() => null),
        ];
    }
}
