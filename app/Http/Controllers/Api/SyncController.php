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
                    $media[$collectionName] = $yacht->getMedia($collectionName)->map(fn($m) => $m->getUrl())->toArray();
                }
            }
        }

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
            'model' => [
                'id' => $yacht->yachtModel->id,
                'name' => $yacht->yachtModel->name,
                'slug' => Str::slug($yacht->yachtModel->name),
            ],
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
                return [
                    'key' => 'field_' . $config->field_key,
                    'name' => $config->field_key,
                    'label' => $config->label,
                    'type' => $this->mapFieldType($config->field_type),
                    'required' => $config->is_required,
                    'group' => $config->group ?? 'General',
                    'options' => $config->options ?? [],
                ];
            });

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
            'image' => 'image',
            'gallery' => 'gallery',
            'file' => 'file',
            default => 'text',
        };
    }
}
