<?php

namespace App\Filament\Resources\UsedYachtResource\Pages;

use App\Filament\Resources\UsedYachtResource;
use App\Settings\ApiSettings;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Http;

class ListUsedYachts extends ListRecords
{
    protected static string $resource = UsedYachtResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Add Used Yacht'),
            Actions\Action::make('syncToWordPress')
                ->label('Sync to WordPress')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Sync to WordPress')
                ->modalDescription('This will sync all published yachts to WordPress sites configured in your settings.')
                ->action(function () {
                    $sites = config('wordpress.sites');
                    $apiKey = app(ApiSettings::class)->sync_api_key;

                    if (empty($apiKey)) {
                        \Filament\Notifications\Notification::make()
                            ->danger()
                            ->title('API Key not configured')
                            ->body('Please set WORDPRESS_SYNC_API_KEY in your .env file')
                            ->send();
                        return;
                    }

                    $success = 0;
                    $errors = [];

                    foreach ($sites as $site) {
                        try {
                            $response = Http::withHeaders([
                                'X-API-Key' => $apiKey,
                            ])->post($site . '/wp-json/atal-sync/v1/import');

                            if ($response->successful()) {
                                $success++;
                            } else {
                                $errors[] = $site . ': ' . $response->body();
                            }
                        } catch (\Exception $e) {
                            $errors[] = $site . ': ' . $e->getMessage();
                        }
                    }

                    if ($success > 0) {
                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Sync completed')
                            ->body("Successfully synced to {$success} site(s)")
                            ->send();
                    }

                    if (!empty($errors)) {
                        \Filament\Notifications\Notification::make()
                            ->warning()
                            ->title('Some syncs failed')
                            ->body(implode(', ', $errors))
                            ->send();
                    }
                }),
        ];
    }
}
