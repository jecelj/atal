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
            'imported' => $totalSynced,
            'message' => "Synced {$totalSynced} items.",
            'errors' => $errors
        ];
    }

    protected function processDeletions(SyncSite $site, array &$errors)
    {
        // Find items that are marked as 'synced' or 'pending' but shouldn't be anymore
        $syncedItems = SyncStatus::where('sync_site_id', $site->id)
            ->whereIn('status', ['synced', 'pending', 'failed'])
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
                // If filtered out, mark as skipped so it doesn't show as Pending (Orange) or Failed (Red)
                SyncStatus::updateOrCreate(
                    [
                        'sync_site_id' => $site->id,
                        'model_type' => $typeKey,
                        'model_id' => $record->id,
                    ],
                    [
                        'status' => 'skipped',
                        'last_synced_at' => now(),
                        'error_message' => 'Filtered by configuration',
                        'content_hash' => null, // Clear hash to force re-evaluation if filter changes
                    ]
                );
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
        // 1. BRAND & MODEL CHECKS (Yachts Only)
        if ($record instanceof NewYacht || $record instanceof UsedYacht) {
            // Check Published State
            if ($record->state !== 'published') {
                return true;
            }

            if ($site->sync_all_brands) {
                return false;
            }

            $brandId = $record->brand_id;
            if (!$brandId)
                return true;

            $restrictions = $site->brand_restrictions ?? [];
            $rule = collect($restrictions)->firstWhere('brand_id', $brandId);

            if (!$rule || empty($rule['allowed'])) {
                return true;
            }

            // Check Model Restrictions
            $allowedModels = $rule['model_type_restriction'] ?? [];
            if (empty($allowedModels)) {
                return false;
            }

            if (in_array($record->yacht_model_id, $allowedModels)) {
                return false;
            }

            return true;
        }

        // 2. NEWS CHECKS
        if ($record instanceof News) {
            // Check Active Status
            if (!$record->is_active) {
                return true;
            }
            // Check Publish Date
            if ($record->published_at && $record->published_at->isFuture()) {
                return true;
            }
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

                // Get translatable custom fields
                $transFields = $this->extractCustomFields($record, 'news', $lang, [], true);

                $translations[$lang] = array_merge([
                    'title' => $getNewsTrans('title', $lang),
                    'content' => $getNewsTrans('content', $lang),
                ], $transFields);
            }
            $payload['translations'] = $translations;

            // Custom Fields for News
            $payload['custom_fields'] = $this->extractCustomFields($record, 'news', $defaultLang);

        } elseif ($type === 'new_yacht' || $type === 'used_yacht') {
            $payload['title'] = $getTrans('name'); // WP Post Title
            $payload['name'] = $getTrans('name');
            $payload['featured_image'] = $record->getFirstMediaUrl('featured_image'); // Collection name from Yacht.php

            // Basic Fields
            if ($type === 'used_yacht') {
                $payload['price'] = $record->price;
                $payload['year'] = $record->year;
                // Fix: Send location Name as 'location' text field instead of ID
                $payload['location'] = $record->location?->name;
            }

            // Translations
            $translations = [];
            foreach ($supportedLangs as $lang) {
                if ($lang === $defaultLang)
                    continue;

                // Get translatable custom fields
                $transFields = $this->extractCustomFields($record, $type, $lang, [], true);

                $translations[$lang] = array_merge([
                    'title' => $record->getTranslation('name', $lang, false),
                    'name' => $record->getTranslation('name', $lang, false),
                ], $transFields);
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

    protected function extractCustomFields($record, $entityType, $defaultLang, $supportedLangs = [], $onlyMultilingual = false)
    {
        $fields = [];
        $configs = FormFieldConfiguration::where('entity_type', $entityType)->get();

        foreach ($configs as $config) {
            // Skip non-multilingual fields if requested
            if ($onlyMultilingual && !$config->is_multilingual) {
                continue;
            }

            $key = $config->field_key;
            $type = $this->mapInputTypeToACF($config->field_type); // Get normalized type logic

            // 1. Handle Media Fields (Image/File)
            if ($type === 'image' || $type === 'file') {
                // Fetch from Spatie Media Library using key as collection name
                $fields[$key] = $record->getFirstMediaUrl($key);
                continue;
            }

            // 2. Handle Gallery Fields
            if ($type === 'gallery') {
                $mediaItems = $record->getMedia($key);
                $urls = [];
                foreach ($mediaItems as $item) {
                    $urls[] = $item->getFullUrl();
                }
                // Fix: User reports reversed order in WP. Reversing array to compensate.
                $fields[$key] = array_reverse($urls);
                continue;
            }

            // 3. Handle Normal Fields (Text, Repeater, etc.)
            $val = $record->custom_fields[$key] ?? null;

            // REPEATER FLATTENING (Data)
            if ($config->field_type === 'repeater') {
                $limit = 3;
                if (is_array($val)) {
                    for ($i = 0; $i < $limit; $i++) {
                        $item = $val[$i] ?? null;
                        $itemValue = '';
                        if (is_array($item)) {
                            $itemValue = $item['url'] ?? (array_values($item)[0] ?? '');
                        } elseif (is_string($item)) {
                            $itemValue = $item;
                        }
                        $fields[$key . '_' . ($i + 1)] = $itemValue;
                    }
                } else {
                    // Empty values if not array
                    for ($i = 1; $i <= $limit; $i++)
                        $fields[$key . '_' . $i] = '';
                }
                continue;
            }

            // Handle Select/Checkbox/Radio fields for display (convert value to label)
            if (in_array($type, ['select', 'checkbox', 'radio'])) {
                $options = collect($config->options ?? []);

                // Helper to get label (Robust)
                $findLabel = function ($v) use ($options, $defaultLang) {
                    // Safeguard: If $v is an array, flatten it (messy data protection)
                    // This prevents "Array to string conversion" in implode later
                    if (is_array($v)) {
                        // Attempt to find based on first value or just return encoded
                        $v = implode(',', $v);
                    }

                    $opt = $options->firstWhere('value', $v);

                    // FALLBACK: Reverse Lookup (Match by Label)
                    // If the DB stored the text "VAT excl." instead of key "vat_excluded",
                    // we try to find the option that has this label (in default language usually).
                    if (!$opt) {
                        $opt = $options->first(function ($item) use ($v) {
                            $lbl = data_get($item, 'label');
                            return strcasecmp((string) $lbl, (string) $v) === 0;
                        });
                    }

                    if (!$opt)
                        return (string) $v; // Fallback to value


                    // Use data_get to support both Array and Object (stdClass)
                    $translated = data_get($opt, 'label_' . $defaultLang);
                    $default = data_get($opt, 'label');

                    $result = $translated ?? ($default ?? $v);

                    // Final safeguard: Ensure result is string
                    return is_array($result) ? implode(',', $result) : (string) $result;
                };

                if (is_array($val)) {
                    // Checkbox list
                    $labels = array_map($findLabel, $val);
                    $fields[$key] = implode(', ', $labels);
                } else {
                    $fields[$key] = $val ? $findLabel($val) : '';
                }

                // DEBUG LOGGING
                if ($key === 'tax_price') {
                    \Illuminate\Support\Facades\Log::info("Sync Debug [{$defaultLang}]: Resolving tax_price '{$val}' -> '{$fields[$key]}'");
                }

                continue;
            }

            if ($config->is_multilingual) {
                if (is_array($val)) {
                    $fields[$key] = $val[$defaultLang] ?? null;
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
    public function syncConfig(SyncSite $site, array &$errors)
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
                // Fix: User wants to hide Brand/Model from ACF as they are handled via Taxonomies
                if (in_array($config->field_key, ['brand', 'model', 'yacht_model'])) {
                    return null;
                }

                // Default Type Mapping
                $type = $this->mapInputTypeToACF($config->field_type);

                // REPEATER FLATTENING
                if ($config->field_type === 'repeater') {
                    $expandedFields = [];
                    for ($i = 1; $i <= 3; $i++) {
                        $expandedFields[] = [
                            'key' => 'field_' . $config->field_key . '_' . $i,
                            'name' => $config->field_key . '_' . $i,
                            'label' => $config->label . ' ' . $i,
                            'type' => 'text',
                            'required' => 0,
                            'instructions' => '',
                            'conditional_logic' => 0,
                            'wrapper' => ['width' => '33', 'class' => '', 'id' => ''],
                            'default_value' => '',
                        ];
                    }
                    return $expandedFields;
                }

                $fieldData = [
                    'key' => 'field_' . $config->field_key,
                    'name' => $config->field_key,
                    'label' => $config->label,
                    'type' => $type, // ...
                    'required' => $config->is_required ? 1 : 0,
                    'instructions' => '',
                    'conditional_logic' => 0,
                    'wrapper' => ['width' => '', 'class' => '', 'id' => ''],
                    'default_value' => '',
                ];

                // Override Type for Selects -> Text
                // To support "Translated Values" as simple text in WP
                if (in_array($type, ['select', 'checkbox', 'radio'])) {
                    $fieldData['type'] = 'text';
                }

                // Special Case: Brand/Model as Taxonomy ID
                // Old Plugin logic: If name is 'brand' -> type=taxonomy, taxonomy=yacht_brand
                if ($config->field_key === 'brand') {
                    $fieldData['type'] = 'taxonomy';
                    $fieldData['taxonomy'] = 'yacht_brand';
                    $fieldData['field_type'] = 'select'; // ACF taxonomy field props
                    $fieldData['allow_null'] = 0;
                    $fieldData['add_term'] = 0;
                    $fieldData['save_terms'] = 1;
                    $fieldData['load_terms'] = 1;
                    $fieldData['return_format'] = 'id';
                    $fieldData['multiple'] = 0;
                }
                // Fix: Do not force 'taxonomy' type for other sync_as_taxonomy fields unless we have a specific taxonomy mapping.
                // For now, let generic 'sync_as_taxonomy' fields fall through to be handled as Text/Select META fields in WP
                // because the WP plugin implementation of "Sync as Taxonomy" implies we send the *Translated Value* as text, 
                // NOT that we map it to a WP Taxonomy ID (except for Brand/Model).
                // See formatYacht() logic: it sends text values for these fields.
                if ($config->sync_as_taxonomy && !in_array($config->field_key, ['brand', 'model'])) {
                    $type = 'text';
                    $fieldData['type'] = 'text';
                    // Clear choices/select props if it's becoming text
                    unset($fieldData['choices']);
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

                return [$fieldData]; // Wrap single item in array for consistent flattening
            })
                ->filter()
                ->flatten(1) // Flatten the array of arrays
                ->values()
                ->toArray();

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

            // FIX: Inject Static Fields (Price, Year, Location) for Used Yachts
            if ($entityType === 'used_yacht') {
                $staticFields = [
                    [
                        'key' => 'field_price',
                        'name' => 'price',
                        'label' => 'Price',
                        'type' => 'text', // Simple text for formatted price
                        'required' => 0,
                        'wrapper' => ['width' => '50'],
                    ],
                    [
                        'key' => 'field_year',
                        'name' => 'year',
                        'label' => 'Year',
                        'type' => 'number',
                        'required' => 0,
                        'wrapper' => ['width' => '50'],
                    ],
                    [
                        'key' => 'field_location', // This was missing!
                        'name' => 'location',
                        'label' => 'Location',
                        'type' => 'text',
                        'required' => 0,
                        'wrapper' => ['width' => '100'],
                    ]
                ];

                // Merge static fields at start of fields array
                $fieldGroups[count($fieldGroups) - 1]['fields'] = array_merge(
                    $staticFields,
                    $fieldGroups[count($fieldGroups) - 1]['fields']
                );
            }
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
    public function syncNews(News $record): array
    {
        $results = [];
        $sites = SyncSite::where('is_active', true)->get();

        foreach ($sites as $site) {
            // Check if site is assigned (if using many-to-many relation for News)
            if ($record->syncSites()->exists() && !$record->syncSites->contains($site->id)) {
                continue;
            }

            if ($this->isFilteredOut($record, $site)) {
                SyncStatus::updateOrCreate(
                    [
                        'sync_site_id' => $site->id,
                        'model_type' => 'news',
                        'model_id' => $record->id,
                    ],
                    [
                        'status' => 'skipped',
                        'last_synced_at' => now(),
                        'error_message' => 'Filtered by configuration',
                        'content_hash' => null,
                    ]
                );
                continue;
            }

            $success = $this->syncSingleItem($record, $site, 'news');
            $results[] = [
                'site' => $site->name,
                'success' => $success
            ];
        }

        return $results;
    }

    protected function syncSingleItem($record, SyncSite $site, string $type): bool
    {
        $payload = $this->preparePayload($record, $site, $type);
        $hash = md5(json_encode($payload));

        if ($this->pushToWordPress($site, 'update', [$payload])) {
            SyncStatus::updateOrCreate(
                [
                    'sync_site_id' => $site->id,
                    'model_type' => $type,
                    'model_id' => $record->id,
                ],
                [
                    'status' => 'synced',
                    'content_hash' => $hash,
                    'last_synced_at' => now(),
                    'error_message' => null,
                ]
            );
            return true;
        } else {
            SyncStatus::updateOrCreate(
                [
                    'sync_site_id' => $site->id,
                    'model_type' => $type,
                    'model_id' => $record->id,
                ],
                [
                    'status' => 'failed',
                    'last_synced_at' => now(),
                    'error_message' => 'Manual sync failed',
                ]
            );
            return false;
        }
    }
}
