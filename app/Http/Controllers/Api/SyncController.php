<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewYacht;
use App\Models\UsedYacht;
use App\Models\Brand;
use App\Models\YachtModel;
use App\Models\FormFieldConfiguration;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SyncController extends Controller
{
    /**
     * Export yachts with all translations
     * GET /api/sync/yachts?type=new&brand=beneteau&lang=en
     */
    public function yachts(Request $request)
    {
        $type = $request->query('type'); // 'new' or 'used'
        $brandSlug = $request->query('brand');
        $lang = $request->query('lang'); // Filter by language

        // Determine which model to query
        $query = $type === 'used' ? UsedYacht::query() : NewYacht::query();

        // Filter by brand slug if specified (exact match)
        if ($brandSlug) {
            $query->whereHas('brand', function ($q) use ($brandSlug) {
                $q->whereRaw('LOWER(name) = ?', [strtolower($brandSlug)])
                    ->orWhereRaw('LOWER(REPLACE(name, " ", "-")) = ?', [strtolower($brandSlug)]);
            });
        }

        // Only sync published yachts (exclude drafts)
        $query->where('state', 'published');

        // Eager load relationships
        $yachts = $query->with(['brand', 'yachtModel', 'media'])->get();

        return response()->json([
            'yachts' => $yachts->map(fn($yacht) => $this->formatYacht($yacht, $lang)),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Format yacht data for WordPress (fully dynamic)
     * @param mixed $yacht
     * @param string|null $lang Optional language filter (e.g., 'en', 'sl')
     */
    protected function formatYacht($yacht, $lang = null)
    {
        // Get languages - filter if lang parameter is provided
        if ($lang) {
            $languages = Language::where('code', $lang)->get();
        } else {
            $languages = Language::all();
        }

        $entityType = $yacht instanceof NewYacht ? 'new_yacht' : 'used_yacht';
        $configs = FormFieldConfiguration::where('entity_type', $entityType)->get();

        // Get custom fields once
        $customFields = $yacht->getRawOriginal('custom_fields');
        if (is_string($customFields)) {
            $customFields = json_decode($customFields, true);
        }
        if (!is_array($customFields)) {
            $customFields = [];
        }

        // Helper function to get multilingual value from custom_fields
        $getFieldValue = function ($fieldKey, $langCode) use ($customFields) {
            if (!isset($customFields[$fieldKey])) {
                return '';
            }

            $value = $customFields[$fieldKey];

            // If it's an array with language codes, get the specific language
            if (is_array($value) && isset($value[$langCode])) {
                return $value[$langCode];
            }

            // If it's a string, return as-is (non-multilingual field)
            if (is_string($value)) {
                return $value;
            }

            return '';
        };

        // Build translations for each language
        $translations = [];
        foreach ($languages as $language) {
            $langCode = $language->code;

            // Build translation data dynamically from FormFieldConfiguration
            $translationData = [
                'title' => $yacht->getTranslation('name', $langCode),
                'price' => $yacht->price ?? 0,
            ];

            // Add all custom fields for this language
            $translationData['custom_fields'] = $this->getCustomFields($yacht, $langCode);

            $translations[$langCode] = $translationData;
        }

        // Build media collections dynamically (originals are now WebP)
        $media = [];
        foreach ($configs as $config) {
            if (in_array($config->field_type, ['image', 'gallery'])) {
                $collectionName = $config->field_key;

                if ($config->field_type === 'image') {
                    $media[$collectionName] = $yacht->getFirstMediaUrl($collectionName) ?: null;
                } else {
                    // Sort by order_column to preserve user-defined order
                    $media[$collectionName] = $yacht->getMedia($collectionName)
                        ->sortBy('order_column')
                        ->map(fn($m) => $m->getUrl())
                        ->values()
                        ->toArray();
                }
            }
        }

        // Transform sync_as_taxonomy fields to simple Text fields with Translations
        foreach ($configs as $config) {
            if ($config->sync_as_taxonomy && in_array($config->field_type, ['select', 'checkbox'])) {
                $rawValue = $customFields[$config->field_key] ?? null;

                if (!$rawValue)
                    continue;

                // Normalize to array
                $values = is_array($rawValue) ? $rawValue : [$rawValue];
                $options = collect($config->options);

                // Helper to get labels for a set of values in a specific language
                $getLabels = function ($values, $langCode = null) use ($options) {
                    $labels = [];
                    foreach ($values as $val) {
                        $option = $options->firstWhere('value', $val);
                        if ($option) {
                            if ($langCode) {
                                $labels[] = $option['label_' . $langCode] ?? $option['label']; // Fallback to default label
                            } else {
                                $labels[] = $option['label'];
                            }
                        }
                    }
                    return $labels; // Return array of labels
                };

                // 1. Set Default Language Value (Label(s))
                $defaultLangCode = $languages->firstWhere('is_default', true)->code ?? 'en';
                $defaultLabels = $getLabels($values);

                if (!empty($defaultLabels)) {
                    // Start keys with array if multiple, but WP might expect single value for meta if not tax.
                    // But for taxonomy sync, we likely want an array or comma-list.
                    // Let's send Array. WP importer should handle array of terms.
                    $translations[$defaultLangCode]['custom_fields'][$config->field_key] = $config->field_type === 'checkbox' ? $defaultLabels : ($defaultLabels[0] ?? '');
                }

                // 2. Set Translated Values
                foreach ($languages as $language) {
                    if ($language->is_default)
                        continue;

                    $translatedLabels = $getLabels($values, $language->code);

                    if (!empty($translatedLabels)) {
                        if (!isset($translations[$language->code]['custom_fields'])) {
                            $translations[$language->code]['custom_fields'] = [];
                        }
                        $translations[$language->code]['custom_fields'][$config->field_key] = $config->field_type === 'checkbox' ? $translatedLabels : ($translatedLabels[0] ?? '');
                    }
                }
            }
        }

        // REMOVED: Taxonomies payload generation (Simplified strategy)
        // $taxonomies = []; ...

        return [
            'id' => $yacht->id,
            'type' => $yacht instanceof NewYacht ? 'new' : 'used',
            'state' => $yacht->state,
            'source_id' => 'yacht-' . $yacht->id,
            'brand' => [
                'id' => $yacht->brand->id,
                'name' => $yacht->brand->name,
                'slug' => Str::slug($yacht->brand->name),
            ],
            'model' => $yacht->yachtModel ? [
                'id' => $yacht->yachtModel->id,
                'name' => $yacht->yachtModel->name,
                'slug' => Str::slug($yacht->yachtModel->name),
            ] : null,
            'translations' => $translations,
            'media' => $media,
        ];
    }

    /**
     * Get custom fields for a yacht in a specific language
     */
    protected function getCustomFields($yacht, $lang)
    {
        $fields = [];
        $entityType = $yacht instanceof NewYacht ? 'new_yacht' : 'used_yacht';
        $configs = FormFieldConfiguration::where('entity_type', $entityType)->get();

        // Use getRawOriginal to bypass Translatable trait accessor
        $customFields = $yacht->getRawOriginal('custom_fields');
        if (is_string($customFields)) {
            $customFields = json_decode($customFields, true);
        }
        if (!is_array($customFields)) {
            $customFields = [];
        }

        foreach ($configs as $config) {
            $value = null;

            // Handle media field types - ALWAYS fetch from media table
            if ($config->field_type === 'gallery') {
                // Gallery fields ALWAYS return media URLs from media table
                $mediaItems = $yacht->getMedia($config->field_key);
                $value = $mediaItems->map(fn($m) => $m->getUrl())->toArray();
            } elseif ($config->field_type === 'image' || $config->field_type === 'file') {
                // Image/File fields ALWAYS return media URL from media table
                $media = $yacht->getMedia($config->field_key)->first();
                $value = $media ? $media->getUrl() : '';
            } else {
                // Regular fields - get from custom_fields JSON
                if ($config->is_multilingual) {
                    // Get value for specific language
                    $value = $customFields[$config->field_key][$lang] ?? '';
                } else {
                    // Get non-multilingual value
                    $value = $customFields[$config->field_key] ?? '';
                }

                // SPECIAL HANDLING: Repeater Flattening
                // If the field is a repeater (array of arrays with 'url'), flatten it
                if ($config->field_type === 'repeater' && is_array($value)) {
                    $limit = 3; // Limit to 3 items
                    for ($i = 0; $i < $limit; $i++) {
                        // Assuming the repeater schema has a 'url' key (Common for video links)
                        // Or just taking the first value if it's a simple repeater?
                        // Based on NewYachtResource, it is TextInput::make('url')
                        $item = $value[$i] ?? null;
                        $itemValue = '';

                        if (is_array($item)) {
                            $itemValue = $item['url'] ?? (array_values($item)[0] ?? '');
                        } elseif (is_string($item)) {
                            $itemValue = $item;
                        }

                        $fields[$config->field_key . '_' . ($i + 1)] = $itemValue;
                    }
                    // Do not add the original array to $fields to avoid confusion, or keep it?
                    // Let's keep distinct fields.
                    continue;
                }
            }

            $fields[$config->field_key] = $value;
        }

        // Add debug info to the first field (hacky but effective for debugging)
        if (!empty($fields)) {
            $fields['_debug_configured_fields'] = $configs->pluck('field_key')->toArray();
        }

        return $fields;
    }

    /**
     * Export brands with translations
     * GET /api/sync/brands
     */
    public function brands(Request $request)
    {
        $brands = Brand::all();

        return response()->json([
            'brands' => $brands->map(function ($brand) {
                $languages = Language::all();
                $translations = [];

                // Brand name is not translatable, use same name for all languages
                foreach ($languages as $language) {
                    $translations[$language->code] = [
                        'name' => $brand->name,
                    ];
                }

                return [
                    'id' => $brand->id,
                    'slug' => Str::slug($brand->name),
                    'logo' => $brand->logo ? url('storage/' . $brand->logo) : null,
                    'translations' => $translations,
                ];
            }),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Export yacht models with brand relationship
     * GET /api/sync/models
     */
    public function models(Request $request)
    {
        $models = YachtModel::with('brand')->get();

        return response()->json([
            'models' => $models->map(function ($model) {
                $languages = Language::all();
                $translations = [];

                // Model name is not translatable, use same name for all languages
                foreach ($languages as $language) {
                    $translations[$language->code] = [
                        'name' => $model->name,
                    ];
                }

                return [
                    'id' => $model->id,
                    'slug' => Str::slug($model->name),
                    'brand_id' => $model->brand_id,
                    'brand_slug' => Str::slug($model->brand->name),
                    'translations' => $translations,
                ];
            }),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Export ACF field structure from FormFieldConfiguration
     * GET /api/sync/fields
     */
    public function fields(Request $request)
    {
        $fieldGroups = [];

        foreach (['new_yacht', 'used_yacht', 'news'] as $entityType) {
            $configs = FormFieldConfiguration::where('entity_type', $entityType)
                ->orderBy('order')
                ->get();

            $fields = $configs->map(function ($config) {
                // If sync_as_taxonomy is true, we want to force this field to be a TEXT field in WordPress
                // so it can hold the translated string (e.g. "Diesel") instead of a select value or taxonomy ID.
                $type = $config->sync_as_taxonomy ? 'text' : $this->mapFieldType($config->field_type);

                if ($config->field_type === 'repeater') {
                    // Expand repeater into 3 text fields
                    $expandedFields = [];
                    for ($i = 1; $i <= 3; $i++) {
                        $expandedFields[] = [
                            'key' => 'field_' . $config->field_key . '_' . $i,
                            'name' => $config->field_key . '_' . $i,
                            'label' => $config->label . ' ' . $i,
                            'type' => 'text', // Flattened to text
                            'required' => false, // sub-fields probably not required
                            'group' => $config->group ?? 'General',
                        ];
                    }
                    return $expandedFields;
                }

                return [
                    [
                        'key' => 'field_' . $config->field_key,
                        'name' => $config->field_key,
                        'label' => $config->label,
                        'type' => $type,
                        'required' => $config->is_required,
                        'group' => $config->group ?? 'General',
                        'options' => $config->options ?? [],
                    ]
                ];
            })->flatten(1); // Flatten the array of arrays

            $postType = match ($entityType) {
                'new_yacht' => 'new_yachts',
                'used_yacht' => 'used_yachts',
                'news' => 'news',
            };

            $fieldGroups[$postType] = [
                'title' => ucfirst(str_replace('_', ' ', $entityType)) . ' Fields',
                'fields' => $fields,
            ];
        }

        return response()->json([
            'field_groups' => $fieldGroups,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Map Filament field types to ACF field types
     */
    protected function mapFieldType($filamentType)
    {
        return match ($filamentType) {
            'text' => 'text',
            'textarea' => 'textarea',
            'richtext' => 'wysiwyg',
            'number' => 'number',
            'date' => 'date_picker',
            'select' => 'select',
            'checkbox' => 'checkbox',
            'image' => 'image',
            'gallery' => 'gallery',
            'file' => 'file',
            default => 'text',
        };
    }
}
