<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SyncSiteResource\Pages;
use App\Models\SyncSite;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SyncSiteResource extends Resource
{
    protected static ?string $model = SyncSite::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Sync Sites';

    protected static ?string $navigationGroup = 'Migration';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('General Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., Atal SK')
                            ->helperText('A friendly name for this site'),

                        Forms\Components\TextInput::make('url')
                            ->label('Site URL')
                            ->required()
                            ->url()
                            ->maxLength(255)
                            ->placeholder('https://atal.sk')
                            ->helperText('Enter the root domain (e.g. https://atal.sk). The system will automatically append the API endpoint.'),

                        Forms\Components\TextInput::make('api_key')
                            ->password()
                            ->maxLength(255)
                            ->helperText('Site-specific API key (optional, uses global API key from Settings if empty)'),

                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->helperText('Enable or disable sync for this site'),

                        Forms\Components\TextInput::make('order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Display order (lower numbers first)'),
                    ])->columns(2),

                Forms\Components\Section::make('Languages')
                    ->schema([
                        Forms\Components\Select::make('default_language')
                            ->options(\App\Models\Language::all()->pluck('name', 'code'))
                            ->default('sl')
                            ->required(),

                        Forms\Components\CheckboxList::make('supported_languages')
                            ->options(\App\Models\Language::all()->pluck('name', 'code'))
                            ->columns(4)
                            ->helperText('Select all languages that this site supports.'),
                    ]),

                Forms\Components\Section::make('Brand & Model Filtering')
                    ->description('Configure which Yachts are synced to this site.')
                    ->schema([
                        Forms\Components\Toggle::make('sync_all_brands')
                            ->label('Sync All Brands & Models')
                            ->default(true)
                            ->live(),

                        Forms\Components\Repeater::make('brand_restrictions')
                            ->label('Brand Restrictions')
                            ->visible(fn(Forms\Get $get) => !$get('sync_all_brands'))
                            ->schema([
                                Forms\Components\Select::make('brand_id')
                                    ->label('Brand')
                                    ->options(\App\Models\Brand::pluck('name', 'id'))
                                    ->required()
                                    ->live()
                                    ->distinct()
                                    ->columnSpan(1),

                                Forms\Components\Toggle::make('allowed')
                                    ->label('Allowed?')
                                    ->default(true)
                                    ->inline(false)
                                    ->live()
                                    ->columnSpan(1),

                                Forms\Components\Select::make('model_type_restriction')
                                    ->label('Model Restriction')
                                    ->helperText('Leave empty to allow ALL models from this brand. Select specific models to restrict.')
                                    ->options(function (Forms\Get $get) {
                                        $brandId = $get('brand_id');
                                        if (!$brandId)
                                            return [];
                                        return \App\Models\YachtModel::where('brand_id', $brandId)->pluck('name', 'id');
                                    })
                                    ->multiple()
                                    ->visible(fn(Forms\Get $get) => $get('allowed'))
                                    ->columnSpan(2),
                            ])
                            ->columns(4)
                            ->defaultItems(0)
                            ->addActionLabel('Add Brand Rule'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('url')
                    ->searchable()
                    ->limit(50),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_synced_at')
                    ->dateTime()
                    ->sortable()
                    ->since(),

                Tables\Columns\TextColumn::make('order')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only')
                    ->native(false),
            ])
            ->actions([
                Tables\Actions\Action::make('sync_config')
                    ->label('Sync Configuration')
                    ->icon('heroicon-o-cog')
                    ->color('warning')
                    ->action(function (SyncSite $record) {
                        $service = app(\App\Services\WordPressSyncService::class);
                        $errors = [];
                        $service->syncConfig($record, $errors);

                        if (empty($errors)) {
                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Configuration Synced')
                                ->send();
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Sync Failed')
                                ->body(implode("\n", $errors))
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('sync')
                    ->label('Sync')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->modalHeading(fn(SyncSite $record) => "Syncing {$record->name}")
                    ->modalDescription('Please wait while we sync this site...')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('2xl')
                    ->modalContent(function (SyncSite $record) {
                        $sessionKey = 'sync_progress_' . uniqid();

                        // Dispatch the job synchronously (no queue worker needed)
                        \App\Jobs\SyncSitesJob::dispatchSync($record->id, $sessionKey);

                        // Return the Livewire component
                        return view('components.sync-modal-content', [
                            'sessionKey' => $sessionKey,
                        ]);
                    })
                    ->action(fn() => null), // No action needed, job is dispatched in modalContent
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSyncSites::route('/'),
        ];
    }
}
