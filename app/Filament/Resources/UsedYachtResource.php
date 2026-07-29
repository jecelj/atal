<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UsedYachtResource\Pages;
use App\Filament\Resources\UsedYachtResource\RelationManagers;
use App\Models\UsedYacht;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UsedYachtResource extends Resource
{
    use \App\Traits\HasDynamicResourceFields;

    protected static ?string $model = UsedYacht::class;

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 2;

    public static function getNavigationIcon(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return view('filament.icons.yacht');
    }

    public static function form(Form $form): Form
    {
        $baseFields = [
            Forms\Components\Section::make('Basic Information')
                ->schema([
                    Forms\Components\Select::make('brand_id')
                        ->relationship('brand', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn(Forms\Set $set, $state) => $set('slug', \Illuminate\Support\Str::slug($state))),
                            Forms\Components\TextInput::make('slug')
                                ->required(),
                        ]),
                    Forms\Components\Select::make('location_id')
                        ->relationship('location', 'name')
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn(Forms\Set $set, $state) => $set('slug', \Illuminate\Support\Str::slug($state))),
                            Forms\Components\TextInput::make('slug')
                                ->required(),
                        ]),
                    Forms\Components\Tabs::make('Name')
                        ->tabs(function () {
                            $languages = \App\Models\Language::orderBy('is_default', 'desc')->get();
                            $tabs = [];

                            foreach ($languages as $language) {
                                $isDefault = $language->is_default;
                                $label = $language->name . ($isDefault ? ' (Default)' : '');

                                $field = Forms\Components\TextInput::make("name.{$language->code}")
                                    ->label('Model Name')
                                    ->required($isDefault)
                                    ->maxLength(255)
                                    ->live(onBlur: true);

                                // If this is the default language, auto-fill other languages when typing
                                if ($isDefault) {
                                    $field->afterStateUpdated(function (Forms\Set $set, $state, Forms\Get $get) use ($languages) {
                                        // Update slug
                                        $set('slug', \Illuminate\Support\Str::slug($state));

                                        // Auto-fill other languages if they're empty
                                        foreach ($languages as $lang) {
                                            if (!$lang->is_default) {
                                                $currentValue = $get("name.{$lang->code}");
                                                if (empty($currentValue)) {
                                                    $set("name.{$lang->code}", $state);
                                                }
                                            }
                                        }
                                    });
                                }

                                $tabs[] = Forms\Components\Tabs\Tab::make($label)
                                    ->schema([$field]);
                            }

                            return $tabs;
                        })
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(table: 'yachts', column: 'slug', ignoreRecord: true),
                    Forms\Components\Select::make('state')
                        ->options([
                            'draft' => 'Draft',
                            'published' => 'Published',
                            'disabled' => 'Disabled',
                        ])
                        ->default('draft')
                        ->required(),
                    Forms\Components\Toggle::make('is_featured')
                        ->label('Featured')
                        ->helperText('Mark this yacht as featured for priority display')
                        ->default(false),
                ])->columns(2),
        ];

        // Add dynamic custom fields grouped by sections
        $customFieldSections = static::getCustomFieldsSchemaForType('used_yacht');

        foreach ($customFieldSections as $section) {
            $baseFields[] = $section;
        }

        return $form->schema($baseFields);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(100)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->wrap(false)
                    ->limit(20)
                    ->tooltip(function ($record) {
                        return $record->name;
                    })
                    ->label('Model Name'),
                Tables\Columns\TextColumn::make('brand.name')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('location.name')
                    ->sortable()
                    ->searchable()
                    ->label('Location'),
                Tables\Columns\TextInputColumn::make('custom_fields.price')
                    ->type('number')
                    ->inputMode('numeric')
                    ->step(1)
                    ->rules(['nullable', 'integer', 'min:0'])
                    ->sortable()
                    ->label('Price'),
                Tables\Columns\TextColumn::make('custom_fields.year')
                    ->sortable()
                    ->label('Year')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('img_opt_status')
                    ->label('Img Opt.')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->placeholder('No Info')
                    ->alignment('center'),
                Tables\Columns\IconColumn::make('translation_status')
                    ->label('Translations')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->placeholder('No Info')
                    ->alignment('center'),
                Tables\Columns\ViewColumn::make('sync_status')
                    ->view('filament.columns.sync-status')
                    ->label('Sync Status')
                    ->alignment('center'),
                Tables\Columns\ToggleColumn::make('is_featured')
                    ->label('Featured')
                    ->sortable()
                    ->alignment('center'),
                Tables\Columns\ToggleColumn::make('state')
                    ->onColor('success')
                    ->offColor('danger')
                    ->onIcon('heroicon-m-check')
                    ->offIcon('heroicon-m-x-mark')
                    ->state(fn($record) => $record->state === 'published')
                    ->afterStateUpdated(function ($record, $state) {
                        $record->update([
                            'state' => $state ? 'published' : 'draft'
                        ]);
                    })
                    ->label('Published')
                    ->alignment('center'),
                Tables\Columns\TextColumn::make('created_at')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('brand')
                    ->relationship('brand', 'name'),
            ])
            ->actions([
                // EditAction removed as requested
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsedYachts::route('/'),
            'create' => Pages\CreateUsedYacht::route('/create'),
            'edit' => Pages\EditUsedYacht::route('/{record}/edit'),
        ];
    }
}
