<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NewsResource\Pages;
use App\Models\News;
use App\Models\Language;
use App\Models\SyncSite;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use FilamentTiptapEditor\TiptapEditor;
use FilamentTiptapEditor\Enums\TiptapOutput;

class NewsResource extends Resource
{
    protected static ?string $model = News::class;

    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        $baseFields = [
            Forms\Components\Section::make('Content')
                ->schema([
                    Forms\Components\Tabs::make('Translations')
                        ->tabs(function () {
                            $languages = Language::orderBy('is_default', 'desc')->get();
                            $tabs = [];

                            foreach ($languages as $language) {
                                $isDefault = $language->is_default;
                                $label = $language->name . ($isDefault ? ' (Default)' : '');
                                $code = $language->code;

                                $titleField = Forms\Components\TextInput::make("title.{$code}")
                                    ->label('Title')
                                    ->required($isDefault)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Forms\Set $set, $state) use ($isDefault) {
                                        if ($isDefault) {
                                            $set('slug', \Illuminate\Support\Str::slug($state));
                                        }
                                    });

                                if (!$isDefault) {
                                    $titleField->suffixAction(self::getTranslateAction('title', $code));
                                }

                                $tabs[] = Forms\Components\Tabs\Tab::make($label)
                                    ->schema([
                                        $titleField,
                                    ]);
                            }

                            return $tabs;
                        })
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->unique(ignoreRecord: true),
                ])->columnSpan(2),

            Forms\Components\Section::make('Settings')
                ->schema([
                    Forms\Components\Toggle::make('is_active')
                        ->default(true),

                    Forms\Components\DatePicker::make('published_at')
                        ->default(now()),

                    Forms\Components\CheckboxList::make('syncSites')
                        ->relationship('syncSites', 'name')
                        ->label('Sync to Sites')
                        ->helperText('Select which sites this news item should be synced to.')
                        ->columns(1)
                        ->gridDirection('row'),
                ])->columnSpan(1),
        ];

        // Add dynamic custom fields grouped by sections
        $customFieldSections = static::getCustomFieldsSchema();

        foreach ($customFieldSections as $section) {
            $baseFields[] = $section;
        }

        return $form->schema($baseFields)->columns(3);
    }

    protected static function getCustomFieldsSchema(): array
    {
        $sections = [];
        $configurations = \App\Models\FormFieldConfiguration::forNews()->ordered()->get();

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
                    ->columns(2)
                    ->columnSpan(2); // Span 2 columns to match Content section
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
            'richtext' => TiptapEditor::make($fieldKey)
                ->output(TiptapOutput::Html)
                ->columnSpanFull(),
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
                ->extraAttributes(['class' => 'single-element']),
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
                ->columnSpanFull(),
            'file' => \Filament\Forms\Components\SpatieMediaLibraryFileUpload::make($fieldKey)
                ->collection($config->field_key)
                ->maxSize(20480)
                ->panelLayout('compact')
                ->extraAttributes(['class' => 'single-element']),
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

                            if (empty($sourceText)) {
                                \Filament\Notifications\Notification::make()
                                    ->warning()
                                    ->title('No source text')
                                    ->body('Please fill in the default language field first.')
                                    ->send();
                                return;
                            }

                            if (is_array($sourceText)) {
                                try {
                                    $sourceText = tiptap_converter()->asHTML($sourceText);
                                } catch (\Exception $e) {
                                    \Filament\Notifications\Notification::make()
                                        ->warning()
                                        ->title('Content format not supported')
                                        ->body('Could not convert editor content to HTML. Please save first.')
                                        ->send();
                                    return;
                                }
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
                Tables\Columns\TextColumn::make('title')
                    ->getStateUsing(fn($record) => is_array($record->title) ? ($record->title['en'] ?? reset($record->title)) : $record->title)
                    ->searchable(query: function (Builder $query, string $search) {
                        return $query->where('title', 'like', "%{$search}%");
                    }),

                Tables\Columns\TextColumn::make('published_at')
                    ->date()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('syncSites.name')
                    ->badge()
                    ->label('Synced To'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('sync')
                    ->label('Sync Now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (News $record) {
                        $service = app(\App\Services\WordPressSyncService::class);
                        $results = $service->syncNews($record);

                        $successCount = collect($results)->where('success', true)->count();

                        if ($successCount > 0) {
                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Sync Completed')
                                ->body("Synced to {$successCount} site(s)")
                                ->send();
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->warning()
                                ->title('Sync Failed')
                                ->body('Could not sync to any sites.')
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    protected static function getTranslateAction(string $field, string $targetLang, bool $isRichText = false)
    {
        $action = Forms\Components\Actions\Action::make('translate')
            ->icon('heroicon-m-language')
            ->requiresConfirmation(function (Forms\Get $get) use ($field, $targetLang) {
                $existingContent = $get("{$field}.{$targetLang}");
                return !empty($existingContent);
            })
            ->modalHeading('Overwrite existing translation?')
            ->modalDescription('This field already contains text. Do you want to replace it with the automatic translation?')
            ->modalSubmitActionLabel('Yes, translate')
            ->action(function (Forms\Set $set, Forms\Get $get) use ($field, $targetLang, $isRichText) {
                $defaultLang = Language::where('is_default', true)->first()->code;
                $sourceText = $get("{$field}.{$defaultLang}");

                if (empty($sourceText)) {
                    \Filament\Notifications\Notification::make()
                        ->warning()
                        ->title('Missing Source')
                        ->body('Please fill the default language first.')
                        ->send();
                    return;
                }

                try {
                    $service = app(\App\Services\TranslationService::class);
                    $translated = $service->translate($sourceText, $targetLang, $defaultLang);

                    if ($translated) {
                        $set("{$field}.{$targetLang}", $translated);
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
            });

        return $action;
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
            'index' => Pages\ListNews::route('/'),
            'create' => Pages\CreateNews::route('/create'),
            'edit' => Pages\EditNews::route('/{record}/edit'),
        ];
    }
}
