<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NewYachtResource\Pages;
use App\Filament\Resources\NewYachtResource\RelationManagers;
use App\Models\NewYacht;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class NewYachtResource extends Resource
{
    protected static ?string $model = NewYacht::class;

    public static function getNavigationIcon(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return view('filament.icons.yacht');
    }

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        $baseFields = [
            Forms\Components\Section::make('Basic Information')
                ->schema([
                    Forms\Components\Select::make('brand_id')
                        ->relationship('brand', 'name')
                        ->live()
                        ->afterStateUpdated(fn(Forms\Set $set) => $set('yacht_model_id', null))
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
                    Forms\Components\Select::make('yacht_model_id')
                        ->relationship('yachtModel', 'name', modifyQueryUsing: fn(Builder $query, Forms\Get $get) => $query->where('brand_id', $get('brand_id')))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->createOptionForm([
                            Forms\Components\Select::make('brand_id')
                                ->relationship('brand', 'name')
                                ->required(),
                            Forms\Components\TextInput::make('name')
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn(Forms\Set $set, $state) => $set('slug', \Illuminate\Support\Str::slug($state))),
                            Forms\Components\TextInput::make('slug')
                                ->required(),
                        ]),
                    Forms\Components\Translatable::make([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn(Forms\Set $set, $state) => $set('slug', \Illuminate\Support\Str::slug($state))),
                    ])
                        ->locales(fn() => \App\Models\Language::pluck('code')->toArray()),
                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Forms\Components\Select::make('state')
                        ->options([
                            'draft' => 'Draft',
                            'published' => 'Published',
                            'disabled' => 'Disabled',
                        ])
                        ->default('draft')
                        ->required(),
                ])->columns(2),
        ];

        // Add dynamic custom fields grouped by sections
        $customFieldSections = static::getCustomFieldsSchema();

        foreach ($customFieldSections as $section) {
            $baseFields[] = $section;
        }

        return $form->schema($baseFields);
    }

    protected static function getCustomFieldsSchema(): array
    {
        $sections = [];
        $configurations = \App\Models\FormFieldConfiguration::forNewYachts()->ordered()->get();

        // Group fields by their group name
        $groupedFields = $configurations->groupBy('group');

        foreach ($groupedFields as $groupName => $fields) {
            $sectionFields = [];

            foreach ($fields as $config) {
                $field = static::buildFieldFromConfiguration($config);
                if ($field) {
                    $sectionFields[] = $field;
                }
            }

            if (!empty($sectionFields)) {
                $sections[] = Forms\Components\Section::make($groupName ?: 'Additional Information')
                    ->schema($sectionFields)
                    ->columns(2);
            }
        }

        return $sections;
    }

    protected static function buildFieldFromConfiguration(\App\Models\FormFieldConfiguration $config)
    {
        $fieldKey = "custom_fields.{$config->field_key}";

        // If multilingual, wrap in tabs for each language
        if ($config->is_multilingual) {
            $languages = \App\Models\Language::orderBy('is_default', 'desc')->get();

            if ($languages->isEmpty()) {
                // Fallback to simple field if no languages configured
                return static::buildSingleField($config, $fieldKey);
            }

            $defaultLanguage = $languages->where('is_default', true)->first();
            $tabs = [];

            foreach ($languages as $language) {
                $langKey = "{$fieldKey}.{$language->code}";
                $isDefault = $language->is_default;

                // Build field with translation button for non-default languages
                $field = static::buildSingleField($config, $langKey, true, !$isDefault ? [
                    'sourceField' => "{$fieldKey}.{$defaultLanguage->code}",
                    'targetLanguage' => $language->code,
                    'sourceLanguage' => $defaultLanguage->code,
                    'fieldKey' => $config->field_key,
                ] : null);

                if ($field) {
                    $label = $language->name;
                    if ($isDefault) {
                        $label .= ' (Default)';
                    }

                    $tabs[] = Forms\Components\Tabs\Tab::make($label)
                        ->schema([$field]);
                }
            }

            return Forms\Components\Tabs::make($config->label)
                ->tabs($tabs)
                ->columnSpanFull();
        }

        return static::buildSingleField($config, $fieldKey);
    }

    protected static function buildSingleField(\App\Models\FormFieldConfiguration $config, string $fieldKey, bool $showLabel = true, ?array $translationConfig = null)
    {
        $field = match ($config->field_type) {
            'text' => Forms\Components\TextInput::make($fieldKey),
            'textarea' => Forms\Components\Textarea::make($fieldKey)
                ->rows(4),
            'richtext' => \FilamentTiptapEditor\TiptapEditor::make($fieldKey)
                ->output(\FilamentTiptapEditor\Enums\TiptapOutput::Html)
                ->tools([
                    'heading',
                    'bullet-list',
                    'ordered-list',
                    'checked-list',
                    'blockquote',
                    'hr',
                    'bold',
                    'italic',
                    'strike',
                    'underline',
                    'superscript',
                    'subscript',
                    'link',
                    'media',
                    'table',
                    'grid-builder',
                    'details',
                    'code',
                    'code-block',
                    'source',
                ]),
            'number' => Forms\Components\TextInput::make($fieldKey)
                ->numeric(),
            'date' => Forms\Components\DatePicker::make($fieldKey),
            'select' => Forms\Components\Select::make($fieldKey)
                ->options(collect($config->options ?? [])->pluck('label', 'value')->toArray()),
            'image' => \Filament\Forms\Components\SpatieMediaLibraryFileUpload::make($fieldKey)
                ->collection($config->field_key)
                ->image()
                ->imageEditor()
                ->maxSize(5120)
                ->getUploadedFileNameForStorageUsing(function ($file, Forms\Get $get) {
                        // Get the yacht data from the form
                        $brandId = $get('brand_id');
                        $modelId = $get('yacht_model_id');
                        $yachtName = $get('name');

                        $brandSlug = 'unknown';
                        $modelSlug = 'unknown';
                        $nameSlug = '';

                        if ($brandId) {
                            $brand = \App\Models\Brand::find($brandId);
                            $brandSlug = $brand ? \Illuminate\Support\Str::slug($brand->name) : 'unknown';
                        }

                        if ($modelId) {
                            $yachtModel = \App\Models\YachtModel::find($modelId);
                            $modelSlug = $yachtModel ? \Illuminate\Support\Str::slug($yachtModel->name) : 'unknown';
                        }

                        if ($yachtName) {
                            // Extract name from translatable array (prefer English)
                            $nameString = is_array($yachtName)
                            ? ($yachtName['en'] ?? reset($yachtName) ?? '')
                            : $yachtName;
                            $nameSlug = \Illuminate\Support\Str::slug($nameString);
                        }

                        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                        $extension = $file->getClientOriginalExtension();

                        // Build filename: brand-model-name-originalfilename or brand-model-originalfilename if no name
                        $filename = $nameSlug
                        ? "{$brandSlug}-{$modelSlug}-{$nameSlug}-{$originalName}"
                        : "{$brandSlug}-{$modelSlug}-{$originalName}";

                        return "{$filename}.{$extension}";
                    }),
            'gallery' => \Filament\Forms\Components\SpatieMediaLibraryFileUpload::make($fieldKey)
                ->collection($config->field_key)
                ->image()
                ->imageEditor()
                ->multiple()
                ->reorderable()
                ->maxSize(5120)
                ->maxFiles(50)
                ->imagePreviewHeight('150')
                ->panelLayout('grid')
                ->columnSpanFull()
                ->getUploadedFileNameForStorageUsing(function ($file, Forms\Get $get) {
                        // Get the yacht data from the form
                        $brandId = $get('brand_id');
                        $modelId = $get('yacht_model_id');
                        $yachtName = $get('name');

                        $brandSlug = 'unknown';
                        $modelSlug = 'unknown';
                        $nameSlug = '';

                        if ($brandId) {
                            $brand = \App\Models\Brand::find($brandId);
                            $brandSlug = $brand ? \Illuminate\Support\Str::slug($brand->name) : 'unknown';
                        }

                        if ($modelId) {
                            $yachtModel = \App\Models\YachtModel::find($modelId);
                            $modelSlug = $yachtModel ? \Illuminate\Support\Str::slug($yachtModel->name) : 'unknown';
                        }

                        if ($yachtName) {
                            // Extract name from translatable array (prefer English)
                            $nameString = is_array($yachtName)
                            ? ($yachtName['en'] ?? reset($yachtName) ?? '')
                            : $yachtName;
                            $nameSlug = \Illuminate\Support\Str::slug($nameString);
                        }

                        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                        $extension = $file->getClientOriginalExtension();

                        // Build filename: brand-model-name-originalfilename or brand-model-originalfilename if no name
                        $filename = $nameSlug
                        ? "{$brandSlug}-{$modelSlug}-{$nameSlug}-{$originalName}"
                        : "{$brandSlug}-{$modelSlug}-{$originalName}";

                        return "{$filename}.{$extension}";
                    }),
            'file' => \Filament\Forms\Components\SpatieMediaLibraryFileUpload::make($fieldKey)
                ->collection($config->field_key)
                ->maxSize(10240)
                ->getUploadedFileNameForStorageUsing(function ($file, Forms\Get $get) {
                        // Get the yacht data from the form
                        $brandId = $get('brand_id');
                        $modelId = $get('yacht_model_id');
                        $yachtName = $get('name');

                        $brandSlug = 'unknown';
                        $modelSlug = 'unknown';
                        $nameSlug = '';

                        if ($brandId) {
                            $brand = \App\Models\Brand::find($brandId);
                            $brandSlug = $brand ? \Illuminate\Support\Str::slug($brand->name) : 'unknown';
                        }

                        if ($modelId) {
                            $yachtModel = \App\Models\YachtModel::find($modelId);
                            $modelSlug = $yachtModel ? \Illuminate\Support\Str::slug($yachtModel->name) : 'unknown';
                        }

                        if ($yachtName) {
                            // Extract name from translatable array (prefer English)
                            $nameString = is_array($yachtName)
                            ? ($yachtName['en'] ?? reset($yachtName) ?? '')
                            : $yachtName;
                            $nameSlug = \Illuminate\Support\Str::slug($nameString);
                        }

                        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                        $extension = $file->getClientOriginalExtension();

                        // Build filename: brand-model-name-originalfilename or brand-model-originalfilename if no name
                        $filename = $nameSlug
                        ? "{$brandSlug}-{$modelSlug}-{$nameSlug}-{$originalName}"
                        : "{$brandSlug}-{$modelSlug}-{$originalName}";

                        return "{$filename}.{$extension}";
                    }),
            default => null,
        };

        if (!$field) {
            return null;
        }

        if ($showLabel) {
            $field->label($config->label);
        } else {
            $field->label('');
        }

        if ($config->is_required) {
            $field->required();
        }

        // Add translate button for text-based fields
        if ($translationConfig) {
            if (in_array($config->field_type, ['text', 'textarea'])) {
                // TextInput and Textarea support suffixAction
                $field->suffixAction(
                    Forms\Components\Actions\Action::make('translate')
                        ->icon('heroicon-m-language')
                        ->requiresConfirmation(function (Forms\Get $get) use ($translationConfig) {
                            $targetPath = str_replace(".{$translationConfig['sourceLanguage']}", ".{$translationConfig['targetLanguage']}", $translationConfig['sourceField']);
                            $existingContent = $get($targetPath);
                            return !empty($existingContent);
                        })
                        ->modalHeading('Overwrite existing translation?')
                        ->modalDescription('This field already contains text. Do you want to replace it with the automatic translation?')
                        ->modalSubmitActionLabel('Yes, translate')
                        ->action(function (Forms\Set $set, Forms\Get $get, $state) use ($translationConfig) {
                            $sourceText = $get($translationConfig['sourceField']);

                            // Handle Tiptap JSON output
                            if (is_array($sourceText)) {
                                $sourceText = tiptap_converter()->asHTML($sourceText);
                            }

                            if (empty($sourceText)) {
                                \Filament\Notifications\Notification::make()
                                    ->warning()
                                    ->title('No source text')
                                    ->body('Please fill in the default language field first.')
                                    ->send();
                                return;
                            }

                            try {
                                $translationService = app(\App\Services\TranslationService::class);
                                $translated = $translationService->translate(
                                    $sourceText,
                                    $translationConfig['targetLanguage'],
                                    $translationConfig['sourceLanguage']
                                );

                                if ($translated) {
                                    $targetPath = str_replace(".{$translationConfig['sourceLanguage']}", ".{$translationConfig['targetLanguage']}", $translationConfig['sourceField']);
                                    $set($targetPath, $translated);

                                    \Filament\Notifications\Notification::make()
                                        ->success()
                                        ->title('Translated successfully')
                                        ->send();
                                } else {
                                    \Filament\Notifications\Notification::make()
                                        ->danger()
                                        ->title('Translation failed')
                                        ->body('Please check your OpenAI API key in settings.')
                                        ->send();
                                }
                            } catch (\Exception $e) {
                                \Filament\Notifications\Notification::make()
                                    ->danger()
                                    ->title('Translation error')
                                    ->body($e->getMessage())
                                    ->send();
                            }
                        })
                );
            } elseif ($config->field_type === 'richtext') {
                // RichEditor uses hintAction instead
                $field->hintAction(
                    Forms\Components\Actions\Action::make('translate')
                        ->icon('heroicon-m-language')
                        ->requiresConfirmation(function (Forms\Get $get) use ($translationConfig) {
                            $targetPath = str_replace(".{$translationConfig['sourceLanguage']}", ".{$translationConfig['targetLanguage']}", $translationConfig['sourceField']);
                            $existingContent = $get($targetPath);
                            return !empty($existingContent);
                        })
                        ->modalHeading('Overwrite existing translation?')
                        ->modalDescription('This field already contains text. Do you want to replace it with the automatic translation?')
                        ->modalSubmitActionLabel('Yes, translate')
                        ->action(function (Forms\Set $set, Forms\Get $get, $state) use ($translationConfig) {
                            $sourceText = $get($translationConfig['sourceField']);

                            // Handle Tiptap JSON output
                            if (is_array($sourceText)) {
                                $sourceText = tiptap_converter()->asHTML($sourceText);
                            }

                            if (empty($sourceText)) {
                                \Filament\Notifications\Notification::make()
                                    ->warning()
                                    ->title('No source text')
                                    ->body('Please fill in the default language field first.')
                                    ->send();
                                return;
                            }

                            try {
                                $translationService = app(\App\Services\TranslationService::class);
                                $translated = $translationService->translate(
                                    $sourceText,
                                    $translationConfig['targetLanguage'],
                                    $translationConfig['sourceLanguage']
                                );

                                if ($translated) {
                                    $targetPath = str_replace(".{$translationConfig['sourceLanguage']}", ".{$translationConfig['targetLanguage']}", $translationConfig['sourceField']);
                                    $set($targetPath, $translated);

                                    \Filament\Notifications\Notification::make()
                                        ->success()
                                        ->title('Translated successfully')
                                        ->send();
                                } else {
                                    \Filament\Notifications\Notification::make()
                                        ->danger()
                                        ->title('Translation failed')
                                        ->body('Please check your OpenAI API key in settings.')
                                        ->send();
                                }
                            } catch (\Exception $e) {
                                \Filament\Notifications\Notification::make()
                                    ->danger()
                                    ->title('Translation error')
                                    ->body($e->getMessage())
                                    ->send();
                            }
                        })
                );
            }
        }

        return $field;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('brand.name')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('yachtModel.name')
                    ->sortable()
                    ->searchable()
                    ->label('Model'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('price')
                    ->money('EUR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('year')
                    ->sortable(),
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
                    ->label('Published'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNewYachts::route('/'),
            'create' => Pages\CreateNewYacht::route('/create'),
            'edit' => Pages\EditNewYacht::route('/{record}/edit'),
        ];
    }
}
