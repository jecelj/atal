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
                ->label('Check Status')
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
            Actions\Action::make('syncToWp')
                ->label('Sync to WordPress')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Sync All News?')
                ->modalDescription('This will verify and sync all news items to their assigned WordPress sites. This may take a moment.')
                ->action(function () {
                    $records = \App\Models\News::all();
                    $service = app(\App\Services\WordPressSyncService::class);
                    $successCount = 0;
                    $failCount = 0;

                    foreach ($records as $record) {
                        $results = $service->syncNews($record);
                        $allOk = true;

                        if (empty($results)) {
                            continue;
                        }

                        foreach ($results as $res) {
                            if (!$res['success']) {
                                $allOk = false;
                            }
                        }

                        if ($allOk) {
                            $successCount++;
                        } else {
                            $failCount++;
                        }
                    }

                    if ($failCount > 0) {
                        \Filament\Notifications\Notification::make()
                            ->warning()
                            ->title('Sync Completed with Errors')
                            ->body("Success: {$successCount}, Failed: {$failCount}. Check Sync Status column.")
                            ->send();
                    } else {
                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Sync Completed')
                            ->body("All {$successCount} items synced successfully.")
                            ->send();
                    }
                }),
        ];
    }
}
