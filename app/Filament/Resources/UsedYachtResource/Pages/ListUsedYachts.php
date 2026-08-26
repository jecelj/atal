<?php

namespace App\Filament\Resources\UsedYachtResource\Pages;

use App\Filament\Resources\UsedYachtResource;
use App\Models\SyncSite;
use App\Models\UsedYacht;
use App\Services\QueuedWordPressSyncService;
use App\Settings\ApiSettings;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ListUsedYachts extends ListRecords
{
    protected static string $resource = UsedYachtResource::class;

    public function toggleSyncSite(int $recordId, int $siteId): void
    {
        $record = UsedYacht::findOrFail($recordId);
        $site = SyncSite::active()->findOrFail($siteId);
        $isAssigned = $record->syncSites()->whereKey($site->getKey())->exists();

        if ($isAssigned) {
            $record->syncSites()->detach($site);
        } else {
            $record->syncSites()->attach($site);
        }

        // Re-evaluate every active site. This queues an upsert when enabled and
        // a delete when disabled, so the target WordPress site stays in sync.
        app(QueuedWordPressSyncService::class)->queueModelForActiveSites($record);

        Notification::make()
            ->success()
            ->title($isAssigned ? "Sync disabled for {$site->name}" : "Sync enabled for {$site->name}")
            ->body($isAssigned
                ? 'Removal from WordPress was queued.'
                : 'Sync to WordPress was queued.')
            ->send();
    }

    public function savePrice(int $recordId, mixed $price): array
    {
        $validator = Validator::make(
            ['price' => $price],
            ['price' => ['nullable', 'integer', 'min:0']],
        );

        if ($validator->fails()) {
            return ['error' => $validator->errors()->first('price')];
        }

        $record = UsedYacht::findOrFail($recordId);
        $customFields = $record->custom_fields ?? [];
        $customFields['price'] = filled($price) ? (int) $price : null;
        $record->update(['custom_fields' => $customFields]);

        return ['price' => $customFields['price']];
    }

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
                ->modalHeading('Syncing All Sites')
                ->modalDescription('Please wait while we sync all active sites...')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('2xl')
                ->modalContent(function () {
                    $sessionKey = 'sync_progress_' . uniqid();

                    // Return the Livewire component which will trigger the sync on init
                    return view('components.sync-modal-content', [
                        'sessionKey' => $sessionKey,
                    ]);
                })
                ->action(fn() => null),
        ];
    }
}
