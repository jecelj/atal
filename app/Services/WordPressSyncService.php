<?php

namespace App\Services;

use App\Models\SyncSite;
use App\Models\SyncStatus;
use App\Models\NewYacht;
use App\Models\UsedYacht;
use App\Models\News;
use App\Models\Language;
use App\Models\FormFieldConfiguration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WordPressSyncService
{
    /**
     * Master Sync Method
     */
    public function syncSite(SyncSite $site, bool $force = false): array
    {
        Log::info("Starting sync for site: {$site->name} (Force: " . ($force ? 'YES' : 'NO') . ")");
        $totalSynced = 0;
        $errors = [];

        // 0. Sync Configuration (ACF Fields)
        $this->syncConfig($site, $errors);

        // 1. Process Deletions (Cleanup)
        $this->processDeletions($site, $errors);

        // 2. Process Updates
        $totalSynced += $this->processUpdates($site, NewYacht::class, 'new_yacht', $errors, $force);
        $totalSynced += $this->processUpdates($site, UsedYacht::class, 'used_yacht', $errors, $force);
        $totalSynced += $this->processUpdates($site, News::class, 'news', $errors, $force);

        // 3. Update Site Status
        $site->update([
            'last_synced_at' => now(),
            'last_sync_result' => [
                'success' => empty($errors),
                'imported' => $totalSynced,
                'errors' => $errors,
                'timestamp' => now()->toIso8601String(),
            ],
        ]);

        return [
            'success' => empty($errors),
            'message' => "Synced {$totalSynced} items.",
            'errors' => $errors
        ];
    }

    protected function processDeletions(SyncSite $site, array &$errors)
    {
        // Find items that are marked as 'synced' but shouldn't be anymore
        $syncedItems = SyncStatus::where('sync_site_id', $site->id)
            ->where('status', 'synced')
            ->get();

        $toDelete = [];

        foreach ($syncedItems as $status) {
            $shouldDelete = false;
            $modelClass = $this->getModelClass($status->model_type);

            if (!$modelClass) {
                $shouldDelete = true;
            } else {
                $record = $modelClass::find($status->model_id);
                if (!$record) {
                    $shouldDelete = true; // Deleted from DB
                } elseif ($this->isFilteredOut($record, $site)) {
                    $shouldDelete = true; // Filtered out
                }
            }

            if ($shouldDelete) {
                $toDelete[] = [
                    'id' => $status->model_id,
                    'type' => $status->model_type, // 'new_yacht', 'used_yacht', 'news'
                ];
                // Update status to pending delete to avoid re-checking until success? 
                // Actually keep as synced until confirmed delete from WP.
            }
        }

        if (!empty($toDelete)) {
            // Push deletion batch
            $chunks = array_chunk($toDelete, 50); // Larger chunks for deletes
            foreach ($chunks as $chunk) {
                if ($this->pushToWordPress($site, 'delete', $chunk)) {
                    // Remove status records
                    foreach ($chunk as $item) {
                        SyncStatus::where('sync_site_id', $site->id)
                            ->where('model_type', $item['type'])
                            ->where('model_id', $item['id'])
                            ->delete();
                    }
                } else {
                    $errors[] = "Failed to delete batch for site {$site->name}";
                }
            }
        }
    }

    protected function processUpdates(SyncSite $site, string $modelClass, string $typeKey, array &$errors, bool $force = false): int
    {
        $records = $modelClass::all(); // Optimize with chunks/cursors if needed for thousands
        $dirtyItems = [];
        $syncedCount = 0;

        foreach ($records as $record) {
            if ($this->isFilteredOut($record, $site)) {
                continue;
            }

            $payload = $this->preparePayload($record, $site, $typeKey);

            // DEBUG: Log first payload of each type to verify content
            if ($syncedCount === 0) {
                Log::info("DEBUG PAYLOAD [{$typeKey}]: " . json_encode($payload, JSON_PRETTY_PRINT));
            }

            $hash = md5(json_encode($payload));

            // Check if dirty
            $status = SyncStatus::firstOrNew([
                'sync_site_id' => $site->id,
                'model_type' => $typeKey,
                'model_id' => $record->id,
            ]);

            if (!$force && $status->status === 'synced' && $status->content_hash === $hash) {
                continue; // Not dirty
            }

            $dirtyItems[] = [
                'record' => $record,
                'payload' => $payload,
                'status_model' => $status,
                'hash' => $hash,
            ];
        }

        // Batch Push
        $chunks = array_chunk($dirtyItems, 5); // Small chunks for safe processing
        foreach ($chunks as $chunk) {
            $batchPayload = array_map(fn($item) => $item['payload'], $chunk);

            if ($this->pushToWordPress($site, 'update', $batchPayload)) {
                // Update statuses
                foreach ($chunk as $item) {
                    $item['status_model']->fill([
                        'status' => 'synced',
                        'content_hash' => $item['hash'],
                        'last_synced_at' => now(),
                        'error_message' => null,
                    ])->save();
                    $syncedCount++;
                }
            } else {
                $errors[] = "Failed to sync batch of {$typeKey} to {$site->name}";
                foreach ($chunk as $item) {
                    $item['status_model']->fill([
                        'status' => 'failed',
                        'last_synced_at' => now(),
                        'error_message' => 'Batch sync failed',
                    ])->save();
                }
            }
        }

        return $syncedCount;
    }

    protected function isFilteredOut($record, SyncSite $site): bool
    {
        // 1. Language checks? No, we transform content.
        // 2. Brand/Model checks (Only for Yachts)
        if ($record instanceof NewYacht || $record instanceof UsedYacht) {
            if ($site->sync_all_brands) {
                return false;
            }

            $brandId = $record->brand_id; // Check if model has brand_id
            if (!$brandId)
                return true; // Safety

            $restrictions = $site->brand_restrictions ?? [];
            // Format: [{'brand_id': 1, 'allowed': true, 'model_type_restriction': [...]}]

            $rule = collect($restrictions)->firstWhere('brand_id', $brandId);

            if (!$rule || empty($rule['allowed'])) {
                return true; // Not in list or allowed=false -> Blocked
            }

            // Check Model Restrictions
            $allowedModels = $rule['model_type_restriction'] ?? [];
            if (empty($allowedModels)) {
                return false; // All models allowed
            }

            // Assuming record has yacht_model_id
            if (in_array($record->yacht_model_id, $allowedModels)) {
                return false; // Allowed
            }

            return true; // Model not in allowed list
        }

        return false;
    }

    protected function preparePayload($record, SyncSite $site, string $type): array
    {
        $payload = [
            'id' => $record->id, // Master ID
            'type' => $type,
            'source_id' => ($type === 'news' ? 'news-' : 'yacht-') . $record->id,
            'slug' => $record->slug ?? Str::slug($record->name ?? 'item-' . $record->id),
            'url' => method_exists($record, 'getUrl') ? $record->getUrl() : null, // If exists
        ];

        // Determine Languages
        // Master Language (default) vs Site Default Language
        // Ideally we map Master Default -> Site Default.
        // For simplicity, we send ALL configured supported languages for the site.
        $supportedLangs = $site->supported_languages ?? ['en'];
        $defaultLang = $site->default_language ?? 'en';

        // Helper to get translated value
        $getTrans = function ($attribute) use ($record, $defaultLang) {
            // If record uses HasTranslations
            if (method_exists($record, 'getTranslation')) {
                return $record->getTranslation($attribute, $defaultLang, false)
                    ?? $record->getAttribute($attribute); // Fallback
            }
            // If attribute is array (like News title sometimes)
            $val = $record->getAttribute($attribute);
            if (is_array($val))
                return $val[$defaultLang] ?? reset($val);
            return $val;
        };

        if ($type === 'news') {
            $payload['title'] = $getTrans('title');
            $payload['content'] = $getTrans('content'); // Assuming 'content' field exists in News
            $payload['published_at'] = $record->published_at;
            $payload['image'] = $record->getFirstMediaUrl('default');

            // Translations
            $translations = [];
            foreach ($supportedLangs as $lang) {
                if ($lang === $defaultLang)
                    continue;

                // Helper for news array access
                $getNewsTrans = function ($field, $code) use ($record) {
                    $vals = $record->$field;
                    return is_array($vals) ? ($vals[$code] ?? '') : '';
                };

                $translations[$lang] = [
                    'title' => $getNewsTrans('title', $lang),
                    'content' => $getNewsTrans('content', $lang),
                ];
            }
            $payload['translations'] = $translations;

            // Custom Fields for News
            $payload['custom_fields'] = $this->extractCustomFields($record, 'news', $defaultLang);

        } elseif ($type === 'new_yacht' || $type === 'used_yacht') {
            $payload['title'] = $getTrans('name'); // WP Post Title
            $payload['name'] = $getTrans('name');
            $payload['featured_image'] = $record->getFirstMediaUrl('default');

            // Basic Fields
            if ($type === 'used_yacht') {
                $payload['price'] = $record->price;
                $payload['year'] = $record->year;
                $payload['location_id'] = $record->location_id; // Map location text later if needed
            }

            // Translations
            $translations = [];
            foreach ($supportedLangs as $lang) {
                if ($lang === $defaultLang)
                    continue;
                $translations[$lang] = [
                    'title' => $record->getTranslation('name', $lang, false),
                    'name' => $record->getTranslation('name', $lang, false),
                ];
            }
            $payload['translations'] = $translations;

            // Custom Fields (Dynamic)
            $payload['custom_fields'] = $this->extractCustomFields($record, $type, $defaultLang, $supportedLangs);

            // Brands and Models
            $payload['brand'] = [
                'id' => $record->brand_id,
                'name' => $record->brand?->name,
                'slug' => $record->brand?->slug,
            ];
            $payload['model'] = [
                'id' => $record->yacht_model_id,
                'name' => $record->yachtModel?->name,
                'slug' => $record->yachtModel?->slug,
            ];

            // Media Galleries
            $media = [];
            $mediaItems = $record->getMedia('gallery');
            foreach ($mediaItems as $index => $item) {
                $media[] = $item->getFullUrl();
            }
            $payload['media'] = $media;
        }

        return $payload;
    }

    protected function extractCustomFields($record, $entityType, $defaultLang, $supportedLangs = [])
    {
        $fields = [];
        $configs = FormFieldConfiguration::where('entity_type', $entityType)->get();

        foreach ($configs as $config) {
            $key = $config->field_key;
            $val = $record->custom_fields[$key] ?? null;

            if ($config->is_multilingual) {
                // If multilingual, custom_fields[key] might be an array [en => val, de => val]
                // OR separate keys in logic. Usually in Filament we save as array or JSON.
                // Let's assume array structure in custom_fields column json.
                if (is_array($val)) {
                    $fields[$key] = $val[$defaultLang] ?? null;
                    // Add translations to payload globally or handled here?
                    // Previous logic put them in specific translation blocks.
                    // For simplicity, let's just send the raw array if WP can handle it, 
                    // BUT our WP plugin expects strict fields.
                    // Let's stick to sending default lang value here, 
                    // and handle translations in the 'translations' payload block if we were traversing there.
                    // Actually, let's inject localized values into payload['translations'] via reference if possible, 
                    // or just flatten keys like 'my_field_en', 'my_field_de' which is easier for WP/ACF to import.
                } else {
                    $fields[$key] = $val;
                }
            } else {
                $fields[$key] = $val;
            }
        }
        return $fields;
    }

    protected function pushToWordPress(SyncSite $site, string $action, array $items): bool
    {
        // Parse the stored URL to get the base (scheme + host)
        $parsed = parse_url($site->url);
        $baseUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        // If there is a path that is NOT part of wp-json (e.g. subdir install), we might need it.
        // But usually users put the full API url. 
        // Safer approach: Regex replace the old endpoint or just take the root if it contains wp-json

        if (str_contains($site->url, '/wp-json/')) {
            $baseUrl = substr($site->url, 0, strpos($site->url, '/wp-json/'));
        } else {
            $baseUrl = rtrim($site->url, '/');
        }

        $url = $baseUrl . '/wp-json/atal-sync/v1/push';
        $apiKey = $site->api_key ?: app(\App\Settings\ApiSettings::class)->sync_api_key;

        try {
            $response = Http::timeout(60)
                ->withHeaders(['X-API-Key' => $apiKey])
                ->post($url, [
                    'action' => $action, // 'update', 'delete'
                    'items' => $items,   // Array of payloads
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("Push failed to {$site->name}: " . $e->getMessage());
            return false;
        }
    }

    protected function getModelClass($type)
    {
        return match ($type) {
            'new_yacht' => NewYacht::class,
            'used_yacht' => UsedYacht::class,
            'news' => News::class,
            default => null
        };
    }
    protected function syncConfig(SyncSite $site, array &$errors)
    {
        $configPayload = $this->prepareConfigPayload();

        // Push Config
        if (!$this->pushToWordPress($site, 'config', $configPayload)) {
            $errors[] = "Failed to sync Field Configuration to site: {$site->name}";
        }
    }

    protected function prepareConfigPayload(): array
    {
        $fieldGroups = [];

        foreach (['new_yacht', 'used_yacht', 'news'] as $entityType) {
            $configs = FormFieldConfiguration::where('entity_type', $entityType)
                ->orderBy('order')
                ->get();

            $fields = $configs->map(function ($config) {
                // Default Type Mapping
                $type = $this->mapInputTypeToACF($config->field_type);
                $fieldData = [
                    'key' => 'field_' . $config->field_key,
                    'name' => $config->field_key,
                    'label' => $config->label,
                    'type' => $type,
                    'required' => $config->is_required ? 1 : 0,
                    'instructions' => '',
                    'conditional_logic' => 0,
                    'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
                    'default_value' => '',
                ];

                // Special Case: Brand/Model as Taxonomy ID
                // Old Plugin logic: If name is 'brand' -> type=taxonomy, taxonomy=yacht_brand
                if ($config->field_key === 'brand' || $config->sync_as_taxonomy) {
                    $fieldData['type'] = 'taxonomy';
                    $fieldData['taxonomy'] = 'yacht_brand'; // Default to yacht_brand for now, or make dynamic later
                    $fieldData['field_type'] = 'select';
                    $fieldData['allow_null'] = 0;
                    $fieldData['add_term'] = 0;
                    $fieldData['save_terms'] = 1;
                    $fieldData['load_terms'] = 1;
                    $fieldData['return_format'] = 'id';
                    $fieldData['multiple'] = 0;
                }

                // Special Case: Image / Gallery params
                if ($type === 'image' || $type === 'gallery') {
                    $fieldData['return_format'] = 'id';
                    $fieldData['library'] = 'all';
                    $fieldData['preview_size'] = 'medium';
                }

                // Choices for Select/Checkbox
                if (in_array($type, ['select', 'checkbox', 'radio'])) {
                    $fieldData['choices'] = collect($config->options ?? [])->pluck('label', 'value')->toArray();
                }

                return $fieldData;
            })->toArray();

            $postType = match ($entityType) {
                'new_yacht' => 'new_yachts',
                'used_yacht' => 'used_yachts',
                'news' => 'post',
                default => 'post',
            };

            $fieldGroups[] = [
                'key' => 'group_' . $entityType,
                'title' => ucfirst(str_replace('_', ' ', $entityType)) . ' Fields',
                'fields' => $fields,
                'location' => [
                    [
                        [
                            'param' => 'post_type',
                            'operator' => '==',
                            'value' => $postType,
                        ],
                    ],
                ],
                'menu_order' => 0,
                'position' => 'normal',
                'style' => 'default',
                'label_placement' => 'top',
                'instruction_placement' => 'label',
                'hide_on_screen' => '',
                'active' => true,
                'description' => '',
            ];
        }

        return $fieldGroups;
    }

    protected function mapInputTypeToACF($filamentType)
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
            'gallery' => 'gallery', // Requires ACF Gallery plugin or similar
            'file' => 'file',
            default => 'text',
        };
    }
}
