<?php

namespace App\Traits;

use Filament\Forms;
use Filament\Forms\Components\Tabs;

trait HasDynamicResourceFields
{
    public static function getCustomFieldsSchemaForType(string $entityType): array
    {
        $sections = [];
        $configurations = \App\Models\FormFieldConfiguration::where('entity_type', $entityType)->ordered()->get();

        // Group fields by their group name
        $groupedFields = $configurations->groupBy('group');

        foreach ($groupedFields as $groupName => $fields) {
            $sectionFields = [];

            foreach ($fields as $config) {
                // Determine if we need to call self:: or static:: or just use the logic here.
                // Helper method logic is self-contained.
                $field = self::buildFieldFromConfiguration($config);
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
                return self::buildSingleField($config, $fieldKey);
            }

            $defaultLanguage = $languages->where('is_default', true)->first();
            $tabs = [];

            foreach ($languages as $language) {
                $langKey = "{$fieldKey}.{$language->code}";
                $isDefault = $language->is_default;

                // Build field with translation button for non-default languages
                $field = self::buildSingleField($config, $langKey, true, !$isDefault ? [
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

        return self::buildSingleField($config, $fieldKey);
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
                ->downloadable()
                ->maxSize(20480)
                ->imagePreviewHeight('250')
                ->panelLayout('compact')
                ->extraAttributes(['class' => 'single-element'])
                ->getUploadedFileNameForStorageUsing(function ($file, Forms\Get $get) {
                        $brandId = $get('brand_id');
                        $modelId = $get('yacht_model_id'); // Might be null for Charter if not used
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
                            $nameString = is_array($yachtName)
                            ? ($yachtName['en'] ?? reset($yachtName) ?? '')
                            : $yachtName;
                            $nameSlug = \Illuminate\Support\Str::slug($nameString);
                        }

                        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                        $extension = $file->getClientOriginalExtension();

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
                ->downloadable()
                ->maxSize(20480)
                ->maxFiles(50)
                ->imagePreviewHeight('150')
                ->panelLayout('grid')
                ->columnSpanFull()
                ->getUploadedFileNameForStorageUsing(function ($file, Forms\Get $get) {
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
                            $nameString = is_array($yachtName)
                            ? ($yachtName['en'] ?? reset($yachtName) ?? '')
                            : $yachtName;
                            $nameSlug = \Illuminate\Support\Str::slug($nameString);
                        }

                        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                        $extension = $file->getClientOriginalExtension();

                        $filename = $nameSlug
                        ? "{$brandSlug}-{$modelSlug}-{$nameSlug}-{$originalName}"
                        : "{$brandSlug}-{$modelSlug}-{$originalName}";

                        return "{$filename}.{$extension}";
                    }),
            'file' => \Filament\Forms\Components\SpatieMediaLibraryFileUpload::make($fieldKey)
                ->collection($config->field_key)
                ->downloadable()
                ->maxSize(20480)
                ->panelLayout('compact')
                ->extraAttributes(['class' => 'single-element'])
                ->getUploadedFileNameForStorageUsing(function ($file, Forms\Get $get) {
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
                            $nameString = is_array($yachtName)
                            ? ($yachtName['en'] ?? reset($yachtName) ?? '')
                            : $yachtName;
                            $nameSlug = \Illuminate\Support\Str::slug($nameString);
                        }

                        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                        $extension = $file->getClientOriginalExtension();

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
            $translateAction = Forms\Components\Actions\Action::make('translate')
                ->icon('heroicon-m-language')
                ->requiresConfirmation(function (Forms\Get $get) use ($translationConfig) {
                    $targetPath = str_replace(".{$translationConfig['sourceLanguage']}", ".{$translationConfig['targetLanguage']}", $translationConfig['sourceField']);
                    $existingContent = $get($targetPath);
                    return !empty($existingContent) && trim(strip_tags($existingContent)) !== '';
                })
                ->modalHeading('Overwrite existing translation?')
                ->modalDescription('This field already contains text. Do you want to replace it with the automatic translation?')
                ->modalSubmitActionLabel('Yes, translate')
                ->action(function (Forms\Set $set, Forms\Get $get, $state) use ($translationConfig) {
                    $sourceText = $get($translationConfig['sourceField']);

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
                });

            if ($config->field_type === 'text') {
                $field->suffixAction($translateAction);
            } elseif ($config->field_type === 'richtext') {
                $field->hintAction($translateAction);
            }
        }

        return $field;
    }
}
