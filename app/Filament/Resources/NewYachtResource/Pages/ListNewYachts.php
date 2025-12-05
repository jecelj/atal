<?php

namespace App\Filament\Resources\NewYachtResource\Pages;

use App\Filament\Resources\NewYachtResource;
use App\Settings\ApiSettings;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Http;

class ListNewYachts extends ListRecords
{
    protected static string $resource = NewYachtResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Add New Yacht'),
            Actions\Action::make('checkStatus')
                ->label('Preveri stanje zapisov')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->action(function () {
                    $records = \App\Models\NewYacht::all();
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

                    // Dispatch the job synchronously (no queue worker needed)
                    \App\Jobs\SyncSitesJob::dispatchSync(null, $sessionKey);

                    // Return the Livewire component
                    return view('components.sync-modal-content', [
                        'sessionKey' => $sessionKey,
                    ]);
                })
                ->action(fn() => null),
        ];
    }
}
