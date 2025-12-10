<?php

namespace App\Filament\Pages;

use App\Filament\Resources\NewYachtResource;
use App\Filament\Resources\NewsResource;
use App\Filament\Resources\UsedYachtResource;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('addNews')
                ->label('Add News')
                ->url(NewsResource::getUrl('create'))
                ->icon('heroicon-m-newspaper')
                ->color('info'),

            Action::make('add_openai')
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
                                return \App\Models\YachtModel::all()->pluck('name', 'id');
                            }
                            return \App\Models\YachtModel::where('brand_id', $brandId)->pluck('name', 'id');
                        })
                        ->createOptionForm([
                            \Filament\Forms\Components\Hidden::make('brand_id')
                                ->default(fn(\Filament\Forms\Get $get) => $get('brand_id')),
                            \Filament\Forms\Components\TextInput::make('name')->required(),
                        ])
                        ->createOptionUsing(function ($data, \Filament\Forms\Get $get) {
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
                    set_time_limit(600);
                    $service = new \App\Services\OpenAIImportService();

                    $brand = \App\Models\Brand::find($data['brand_id']);
                    $context = [
                        'brand' => $brand ? $brand->name : '',
                        'model' => $data['name'],
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

                    $importId = uniqid('import_');
                    $mergedData = array_merge((array) $extractedData, [
                        'brand_id' => $data['brand_id'],
                        'yacht_model_id' => $data['yacht_model_id'],
                        'name' => $data['name'],
                        'original_url' => $data['url'],
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
                            'video_url' => $extractedData['video_url'] ?? [],
                            'pdf_brochure' => $extractedData['pdf_brochure'] ?? null,
                            'gallery_exterior_urls_source' => $extractedData['gallery_exterior'] ?? [],
                            'gallery_exterior_urls' => $extractedData['gallery_exterior'] ?? [],
                            'gallery_interior_urls_source' => $extractedData['gallery_interior'] ?? [],
                            'gallery_interior_urls' => $extractedData['gallery_interior'] ?? [],
                            'gallery_cockpit_urls_source' => $extractedData['gallery_cockpit'] ?? [],
                            'gallery_cockpit_urls' => $extractedData['gallery_cockpit'] ?? [],
                            'gallery_layout_urls_source' => $extractedData['gallery_layout'] ?? [],
                            'gallery_layout_urls' => $extractedData['gallery_layout'] ?? [],
                            'cover_image_source' => isset($extractedData['cover_image']) ? [$extractedData['cover_image']] : [],
                            'cover_image_url' => isset($extractedData['cover_image']) ? [$extractedData['cover_image']] : [],
                            'grid_image_source' => isset($extractedData['grid_image']) ? [$extractedData['grid_image']] : [],
                            'grid_image_url' => isset($extractedData['grid_image']) ? [$extractedData['grid_image']] : [],
                            'grid_image_hover_source' => isset($extractedData['grid_image_hover']) ? [$extractedData['grid_image_hover']] : [],
                            'grid_image_hover_url' => isset($extractedData['grid_image_hover']) ? [$extractedData['grid_image_hover']] : [],
                            '_debug_prompt' => $extractedData['_debug_prompt'] ?? null,
                            '_debug_response' => $extractedData['_debug_response'] ?? null,
                        ],
                    ]);

                    \Illuminate\Support\Facades\Cache::put('openai_import_' . $importId, $mergedData, 3600);
                    return redirect()->to(\App\Filament\Pages\ReviewOpenAIImport::getUrl(['import_id' => $importId]));
                }),

            Action::make('addNewYacht')
                ->label('Add New Yacht')
                ->url(NewYachtResource::getUrl('create'))
                ->icon('heroicon-m-plus')
                ->color('info'),

            Action::make('addUsedYacht')
                ->label('Add Used Yacht')
                ->url(UsedYachtResource::getUrl('create'))
                ->icon('heroicon-m-plus')
                ->color('info'),


        ];
    }
}
