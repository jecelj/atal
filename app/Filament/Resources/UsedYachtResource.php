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
                                    ->label('Name')
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
        $configurations = \App\Models\FormFieldConfiguration::forUsedYachts()->ordered()->get();

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
                ->maxSize(20480)
                ->imagePreviewHeight('250')
                ->panelLayout('compact')
                ->extraAttributes(['class' => 'single-element'])
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
                ->maxSize(20480)
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
                ->maxSize(20480)
                ->panelLayout('compact')
                ->extraAttributes(['class' => 'single-element'])
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
            if ($config->field_type === 'text') {
                // TextInput supports suffixAction
                $field->suffixAction(
                    Forms\Components\Actions\Action::make('translate')
                        ->icon('heroicon-m-language')
                        ->requiresConfirmation(function (Forms\Get $get) use ($translationConfig) {
                            $targetPath = str_replace(".{$translationConfig['sourceLanguage']}", ".{$translationConfig['targetLanguage']}", $translationConfig['sourceField']);
                            $existingContent = $get($targetPath);
                            // Check if content is truly empty (ignoring whitespace and HTML tags)
                            return !empty($existingContent) && trim(strip_tags($existingContent)) !== '';
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
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(100)
            ->columns([
                Tables\Columns\TextColumn::make('brand.name')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('location.name')
                    ->sortable()
                    ->searchable()
                    ->label('Location'),
                Tables\Columns\IconColumn::make('img_opt_status')
                    ->label('Img Opt.')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->placeholder('No Info'),
                Tables\Columns\IconColumn::make('translation_status')
                    ->label('Translations')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->placeholder('No Info'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('custom_fields.price')
                    ->money('EUR')
                    ->sortable()
                    ->label('Price'),
                Tables\Columns\TextColumn::make('custom_fields.year')
                    ->sortable()
                    ->label('Year'),
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
