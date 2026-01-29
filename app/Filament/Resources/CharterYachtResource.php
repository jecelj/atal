<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CharterYachtResource\Pages;
use App\Models\CharterYacht;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

use App\Traits\HasDynamicResourceFields;

class CharterYachtResource extends Resource
{
    use HasDynamicResourceFields;

    protected static ?string $model = CharterYacht::class;

    protected static ?string $navigationGroup = 'Content';
    protected static ?string $navigationLabel = 'Charter Yachts';
    protected static ?int $navigationSort = 3;

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
                        ->preload(),
                    Forms\Components\Select::make('charter_location_id')
                        ->relationship('charterLocation', 'name')
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

                    // Translatable Name
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

                                // If this is the default language, auto-fill slug
                                if ($isDefault) {
                                    $field->afterStateUpdated(function (Forms\Set $set, $state) {
                                        $set('slug', \Illuminate\Support\Str::slug($state));
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
                        ->default(false),
                ])->columns(2),
        ];

        // Add dynamic custom fields grouped by sections
        // Add dynamic custom fields grouped by sections
        $customFieldSections = static::getCustomFieldsSchemaForType('charter_yacht');

        foreach ($customFieldSections as $section) {
            $baseFields[] = $section;
        }

        return $form->schema($baseFields);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->label('Model Name'),
                Tables\Columns\TextColumn::make('brand.name')
                    ->sortable()
                    ->searchable(),
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
                    ->label('Featured'),
                Tables\Columns\ToggleColumn::make('state')
                    ->onColor('success')
                    ->offColor('danger')
                    ->state(fn($record) => $record->state === 'published')
                    ->label('Published'),
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
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCharterYachts::route('/'),
            'create' => Pages\CreateCharterYacht::route('/create'),
            'edit' => Pages\EditCharterYacht::route('/{record}/edit'),
        ];
    }
}
