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
            Actions\CreateAction::make()
                ->label('Add Used Yacht')
                ->icon('heroicon-o-plus'),
            Actions\Action::make('add_openai')
                ->label('AventuraBoat AI import')
                ->icon('heroicon-o-sparkles')
                ->color('info')
                ->form([
                    \Filament\Forms\Components\Select::make('brand_id')
                        ->label('Brand')
                        ->options(\App\Models\Brand::all()->pluck('name', 'id'))
                        ->createOptionForm([
                            \Filament\Forms\Components\TextInput::make('name')->required(),
                        ])
                        ->createOptionUsing(fn($data) => \App\Models\Brand::create($data)->id)
                        ->searchable()
                        ->preload()
                        ->required(),
                    \Filament\Forms\Components\TextInput::make('url')
                        ->label('Aventura: EN URL')
                        ->url()
                        ->required()
                        ->columnSpanFull(),
                ])
                ->action(function (array $data) {
                    set_time_limit(600);
                    $service = new \App\Services\OpenAIImportService();

                    // Show Notification
                    \Filament\Notifications\Notification::make()
                        ->title('Import Started')
                        ->body('Fetching data from URL... This may take a minute.')
                        ->info()
                        ->send();

                    $brand = \App\Models\Brand::find($data['brand_id']);
                    $context = [
                        'brand' => $brand ? $brand->name : '',
                        'model' => '',
                    ];

                    $extractedData = $service->fetchUsedYachtData($data['url'], $context);

                    if (isset($extractedData['error'])) {
                        \Filament\Notifications\Notification::make()
                            ->title('OpenAI Import Failed')
                            ->body($extractedData['error'])
                            ->danger()
                            ->send();
                        return;
                    }

                    $importId = uniqid('import_used_');
                    $mergedData = array_merge((array) $extractedData, [
                        'brand_id' => $data['brand_id'],
                        'original_url' => $data['url'],
                        // Store full extracted data properly
                        'custom_fields' => $extractedData,
                        'title' => $extractedData['title'] ?? null,
                    ]);

                    \Illuminate\Support\Facades\Cache::put('openai_import_used_' . $importId, $mergedData, 3600);

                    return redirect()->to(\App\Filament\Pages\ReviewUsedYachtImport::getUrl(['import_id' => $importId]));
                }),
            Actions\Action::make('checkStatus')
                ->label('Check Status')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->action(function () {
                    $records = \App\Models\UsedYacht::all();
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
