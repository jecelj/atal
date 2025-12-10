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
            Actions\CreateAction::make()
                ->label('Add New Yacht')
                ->icon('heroicon-o-plus'),
            Actions\Action::make('add_openai')
                ->label('Add New Yacht AI')
                ->icon('heroicon-o-sparkles')
                ->color('info')
                ->form([
                    \Filament\Forms\Components\Select::make('type')
                        ->label('Yacht Type')
                        ->options([
                            'yacht' => 'Yacht',
                            'daly_crouser' => 'Daily Crouser',
                            'sport_boat' => 'Sport Boat',
                            'sailling_boat' => 'Sailling boat',
                        ])
                        ->required(),
                    \Filament\Forms\Components\TextInput::make('url')
                        ->label('Yacht URL')
                        ->url()
                        ->required()
                        ->columnSpanFull(),
                    \Filament\Forms\Components\Select::make('brand_id')
                        ->label('Brand')
                        ->options(\App\Models\Brand::all()->pluck('name', 'id'))
                        ->createOptionForm([
                            \Filament\Forms\Components\TextInput::make('name')->required(),
                        ])
                        ->createOptionUsing(fn($data) => \App\Models\Brand::create($data)->id)
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn(\Filament\Forms\Set $set) => $set('yacht_model_id', null))
                        ->required(),
                    \Filament\Forms\Components\Select::make('yacht_model_id')
                        ->label('Model Type')
                        ->options(function (\Filament\Forms\Get $get) {
                            $brandId = $get('brand_id');
                            if (!$brandId) {
                                return \App\Models\YachtModel::all()->pluck('name', 'id'); // Or empty []
                            }
                            return \App\Models\YachtModel::where('brand_id', $brandId)->pluck('name', 'id');
                        })
                        ->createOptionForm([
                            \Filament\Forms\Components\Hidden::make('brand_id')
                                ->default(fn(\Filament\Forms\Get $get) => $get('brand_id')),
                            \Filament\Forms\Components\TextInput::make('name')->required(),
                        ])
                        ->createOptionUsing(function ($data, \Filament\Forms\Get $get) {
                            // data usually contains form fields. 
                            // If I use Hidden field for brand_id inside createOptionForm, it should be in $data.
                            // But wait, the form inside 'createOptionForm' is isolated?
                            // No, creating option form receives its own data.
                            // I need to ensure brand_id is passed.
                            // If the Hidden field works, $data['brand_id'] will be set.
                
                            // BUT: context in createOptionForm might differ.
                            // Simpler: 
                            if (empty($data['brand_id'])) {
                                $data['brand_id'] = $get('brand_id');
                            }
                            return \App\Models\YachtModel::create($data)->id;
                        })
                        ->searchable()
                        ->preload()
                        ->required(),

                    \Filament\Forms\Components\TextInput::make('name')
                        ->label('Model Name')
                        ->required(),
                ])
                ->action(function (array $data) {
                    set_time_limit(600); // Allow 10 minutes for OpenAI/Browserless processing
                    $service = new \App\Services\OpenAIImportService();

                    // Show spinner notification (optional, but UI handles spinner on action button)
        
                    // Resolve names for Context
                    $brand = \App\Models\Brand::find($data['brand_id']);
                    $context = [
                        'brand' => $brand ? $brand->name : '',
                        'model' => $data['name'], // "Yacht Name"
                    ];

                    $extractedData = $service->fetchData($data['url'], $context);

                    if (isset($extractedData['error'])) {
                        \Filament\Notifications\Notification::make()
                            ->title('OpenAI Import Failed')
                            ->body($extractedData['error'])
                            ->danger()
                            ->send();
                        return;
                    }

                    // Prepare Cache Data
                    $importId = uniqid('import_');
                    $mergedData = array_merge((array) $extractedData, [
                        'brand_id' => $data['brand_id'],
                        'yacht_model_id' => $data['yacht_model_id'],
                        'name' => $data['name'],
                        'original_url' => $data['url'],
                        // Custom Fields Structure
                        'custom_fields' => [
                            'sub_title' => $extractedData['sub_title'] ?? null,
                            'full_description' => $extractedData['full_description'] ?? null,
                            'specifications' => $extractedData['specifications'] ?? null,
                            'length' => $extractedData['length'] ?? null,
                            'type' => $data['type'] ?? null,
                            'engine_type' => $extractedData['engine_type'] ?? [],
                            'engine_location' => $extractedData['engine_location'] ?? null,
                            'no_cabins' => isset($extractedData['no_cabins']) ? (string) $extractedData['no_cabins'] : null,
                            'number_of_bathrooms' => isset($extractedData['number_of_bathrooms']) ? (string) $extractedData['number_of_bathrooms'] : null,

                            // Video URL (already normalized by Service to [['url' => '...']])
                            'video_url' => $extractedData['video_url'] ?? [],

                            'pdf_brochure' => $extractedData['pdf_brochure'] ?? null,

                            // Gallery Mapping for Review Page (inside custom_fields)
                            'gallery_exterior_urls_source' => $extractedData['gallery_exterior'] ?? [],
                            'gallery_exterior_urls' => $extractedData['gallery_exterior'] ?? [],
                            'gallery_interior_urls_source' => $extractedData['gallery_interior'] ?? [],
                            'gallery_interior_urls' => $extractedData['gallery_interior'] ?? [],
                            'gallery_cockpit_urls_source' => $extractedData['gallery_cockpit'] ?? [],
                            'gallery_cockpit_urls' => $extractedData['gallery_cockpit'] ?? [],
                            'gallery_layout_urls_source' => $extractedData['gallery_layout'] ?? [],
                            'gallery_layout_urls' => $extractedData['gallery_layout'] ?? [],

                            // Single Images Mapping
                            'cover_image_source' => isset($extractedData['cover_image']) ? [$extractedData['cover_image']] : [],
                            'cover_image_url' => isset($extractedData['cover_image']) ? [$extractedData['cover_image']] : [],
                            'grid_image_source' => isset($extractedData['grid_image']) ? [$extractedData['grid_image']] : [],
                            'grid_image_url' => isset($extractedData['grid_image']) ? [$extractedData['grid_image']] : [],
                            'grid_image_hover_source' => isset($extractedData['grid_image_hover']) ? [$extractedData['grid_image_hover']] : [],
                            'grid_image_hover_url' => isset($extractedData['grid_image_hover']) ? [$extractedData['grid_image_hover']] : [],

                            // Debug Info
                            '_debug_prompt' => $extractedData['_debug_prompt'] ?? null,
                            '_debug_response' => $extractedData['_debug_response'] ?? null,
                        ],

                        // Keep these here just in case, or we can remove them if Review Page purely uses custom_fields.
                        // Safe to keep for now or clean up. Let's rely on custom_fields.
                    ]);

                    // WAIT: User said modal input includes 'name'. I missed 'name' in form above.
                    // "brand, yacht_model, name, from_year, URL"
                    // I will add 'name' to the form now.
        
                    \Illuminate\Support\Facades\Cache::put('openai_import_' . $importId, $mergedData, 3600); // 1 hour
        
                    return redirect()->to(\App\Filament\Pages\ReviewOpenAIImport::getUrl(['import_id' => $importId]));
                }),
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
