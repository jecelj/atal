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

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 100;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g., Atal SK')
                    ->helperText('A friendly name for this site'),

                Forms\Components\TextInput::make('url')
                    ->required()
                    ->url()
                    ->maxLength(255)
                    ->placeholder('https://atal.sk/wp-json/atal-sync/v1/import')
                    ->helperText('Full URL to the WordPress sync API endpoint'),

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
