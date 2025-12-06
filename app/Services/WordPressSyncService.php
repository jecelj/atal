<?php

namespace App\Services;

use App\Models\SyncSite;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WordPressSyncService
{
    public function syncSite(SyncSite $site, ?string $type = null): array
    {
        try {
            Log::info("Starting sync for site: {$site->name}" . ($type ? " (Type: $type)" : ""));

            // Use site-specific API key, or fall back to global API key
            $apiKey = $site->api_key ?: app(\App\Settings\ApiSettings::class)->sync_api_key;

            $headers = [];
            if ($apiKey) {
                $headers['X-API-Key'] = $apiKey;
            }

            $results = [];
            $errors = [];
            $importedTotal = 0;
            $success = true;

            // Determine what to sync
            $typesToSync = $type ? [$type] : ['new', 'used'];

            foreach ($typesToSync as $syncType) {
                Log::info("Syncing " . ucfirst($syncType) . " Yachts for site: {$site->name}");

                $url = $site->url . (str_contains($site->url, '?') ? '&' : '?') . "type={$syncType}";

                $response = Http::timeout(120)
                    ->withHeaders($headers)
                    ->post($url, ['type' => $syncType]);

                $result = $response->json();
                $isSuccessful = $response->successful();

                $results[$syncType] = $result;

                if (!$isSuccessful) {
                    $success = false;
                    $errors[] = ucfirst($syncType) . " Yachts Sync Failed: " . $response->body();
                } else {
                    $importedTotal += ($result['imported'] ?? 0);
                    if (isset($result['errors']) && is_array($result['errors'])) {
                        $errors = array_merge($errors, $result['errors']);
                    }
                }
            }

            $site->update([
                'last_synced_at' => now(),
                'last_sync_result' => [
                    'success' => $success,
                    'imported' => $importedTotal,
                    'errors' => $errors,
                    'timestamp' => now()->toIso8601String(),
                    'details' => $results
                ],
            ]);

            if ($success) {
                Log::info("Sync completed for site: {$site->name}", ['imported' => $importedTotal]);
                return [
                    'success' => true,
                    'message' => "Successfully synced {$importedTotal} items (" . implode(' + ', $typesToSync) . ")",
                    'data' => ['imported' => $importedTotal],
                ];
            } else {
                Log::error("Sync failed for site: {$site->name}", ['errors' => $errors]);
                return [
                    'success' => false,
                    'message' => "Sync failed. Check logs for details.",
                    'error' => implode('; ', array_slice($errors, 0, 3)),
                ];
            }

        } catch (\Exception $e) {
            $site->update([
                'last_synced_at' => now(),
                'last_sync_result' => [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);

            Log::error("Sync exception for site: {$site->name}", [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => "Sync failed: {$e->getMessage()}",
                'error' => $e->getMessage(),
            ];
        }
    }

    public function syncAll(): array
    {
        $sites = SyncSite::active()->ordered()->get();
        $results = [];

        foreach ($sites as $site) {
            $results[$site->name] = $this->syncSite($site);
        }

        return $results;
    }

    public function syncNews(\App\Models\News $news): array
    {
        $sites = $news->syncSites()->where('is_active', true)->get();
        $results = [];
        $languages = \App\Models\Language::all();
        $defaultLanguage = $languages->where('is_default', true)->first();

        // 1. Prepare Data for Default Language
        $defaultData = [
            'title' => $this->getTranslation($news, 'title', $defaultLanguage->code),
            'content' => $this->getTranslation($news, 'content', $defaultLanguage->code),
            'excerpt' => $this->getTranslation($news, 'excerpt', $defaultLanguage->code),
            'custom_fields' => [],
        ];

        // 2. Prepare Data for Translations
        $translationsData = [];
        foreach ($languages as $language) {
            // INCLUDE ALL LANGUAGES, even default one.
            // This ensures the plugin has access to all content needed to populate its own default language post if it differs from Master.

            $translationsData[$language->code] = [
                'title' => $this->getTranslation($news, 'title', $language->code),
                'description' => $this->getTranslation($news, 'content', $language->code),
                'excerpt' => $this->getTranslation($news, 'excerpt', $language->code),
                'custom_fields' => [],
            ];
        }

        // 3. Process Custom Fields (Definitions)
        $fieldConfigs = \App\Models\FormFieldConfiguration::forNews()->get();

        foreach ($fieldConfigs as $config) {
            // Get value for Default Language
            $defaultData['custom_fields'][$config->field_key] = $this->resolveFieldValue($news, $config, $defaultLanguage->code);

            // Get value for Other Languages (if multilingual)
            if ($config->is_multilingual) {
                foreach ($languages as $language) {
                    $val = $this->resolveFieldValue($news, $config, $language->code);
                    if ($val) {
                        $translationsData[$language->code]['custom_fields'][$config->field_key] = $val;
                    }
                }
            }
        }

        // 4. Prepare Media (Featured Image)
        $featuredImage = null;
        if ($news->hasMedia('featured_image')) {
            $featuredImage = $news->getFirstMediaUrl('featured_image');
        }

        foreach ($sites as $site) {
            try {
                // Use site-specific API key, or fall back to global API key
                $apiKey = $site->api_key ?: app(\App\Settings\ApiSettings::class)->sync_api_key;
                $headers = [];
                if ($apiKey) {
                    $headers['X-API-Key'] = $apiKey;
                }

                $payload = [
                    'type' => 'news',
                    'data' => [
                        'slug' => $news->slug,
                        'title' => $defaultData['title'] ?? ($defaultData['title']['en'] ?? ''), // Fallback if still array
                        'content' => $defaultData['content'] ?? '',
                        'excerpt' => $defaultData['excerpt'] ?? '',
                        'published_at' => $news->published_at ? $news->published_at->toIso8601String() : null,
                        'featured_image' => $featuredImage,
                        'custom_fields' => $defaultData['custom_fields'],
                        'translations' => $translationsData,
                    ],
                ];

                $response = Http::timeout(60)
                    ->withHeaders($headers)
                    ->post($site->url, $payload);

                if ($response->successful()) {
                    $results[$site->name] = [
                        'success' => true,
                        'message' => 'Synced successfully',
                    ];
                } else {
                    $results[$site->name] = [
                        'success' => false,
                        'message' => 'Failed: ' . $response->body(),
                    ];
                }
            } catch (\Exception $e) {
                $results[$site->name] = [
                    'success' => false,
                    'message' => 'Exception: ' . $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    protected function getTranslation($model, $field, $code)
    {
        $value = $model->$field;
        if (is_array($value)) {
            return $value[$code] ?? null;
        }
        // If not array, return value only if it matches default language? 
        // Or return raw value? For News, these fields are cast to array, so should be array.
        return $value;
    }

    protected function resolveFieldValue($news, $config, $langCode)
    {
        // Handle media field types
        $defaultLang = \App\Models\Language::where('is_default', true)->value('code') ?? 'sl';

        if ($config->field_type === 'gallery') {
            // Only sync media for default language
            if ($langCode !== $defaultLang) {
                return null;
            }

            $mediaItems = $news->getMedia($config->field_key);
            $urls = [];
            foreach ($mediaItems as $media) {
                $urls[] = $media->getUrl();
            }
            return $urls;

        } elseif ($config->field_type === 'image' || $config->field_type === 'file') {
            // Only sync media for default language
            if ($langCode !== $defaultLang) {
                return null;
            }

            if ($news->hasMedia($config->field_key)) {
                return $news->getFirstMediaUrl($config->field_key);
            }
            return null;

        } elseif ($config->field_type === 'select' && $config->sync_as_taxonomy) {
            // Text Strategy for Custom Fields (Selects synced as text)
            $customFields = $news->custom_fields ?? [];
            $rawValue = $customFields[$config->field_key] ?? null;

            // Raw value is usually key/value pair or just value.
            // For News (array cast), custom_fields might be ['field_key' => 'value'] (not multilingual) 
            // or ['field_key' => ['en' => '...', 'sl' => '...']]?
            // Actually News form handles custom fields as array.

            // If multilingual is enabled for this field, rawValue IS an array of langs.
            if (is_array($rawValue) && isset($rawValue[$langCode])) {
                $optionValue = $rawValue[$langCode];
            } elseif (!is_array($rawValue) && $langCode == 'sl') { // Assuming SL is default
                $optionValue = $rawValue;
            } else {
                return null;
            }

            if ($optionValue) {
                // Find label
                $option = collect($config->options)->firstWhere('value', $optionValue);
                if ($option) {
                    return $option['label_' . $langCode] ?? $option['label'];
                }
            }
            return null;

        } else {
            // Regular text/textarea fields
            $customFields = $news->custom_fields ?? [];
            $val = $customFields[$config->field_key] ?? null;

            if (is_array($val)) {
                return $val[$langCode] ?? null;
            }
            return ($langCode == 'sl') ? $val : null; // Fallback
        }
    }
}
