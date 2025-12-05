<?php

namespace App\Filament\Resources\NewsResource\Pages;

use App\Filament\Resources\NewsResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNews extends ListRecords
{
    protected static string $resource = NewsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Add News'),
            Actions\Action::make('checkStatus')
                ->label('Preveri stanje zapisov')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->action(function () {
                    $records = \App\Models\News::all();
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
        ];
    }
}
