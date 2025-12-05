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

        foreach ($sites as $site) {
            try {
                // Use site-specific API key, or fall back to global API key
                $apiKey = $site->api_key ?: app(\App\Settings\ApiSettings::class)->sync_api_key;

                $headers = [];
                if ($apiKey) {
                    $headers['X-API-Key'] = $apiKey;
                }

                // Get custom fields with proper Media Library URLs
                $customFields = [];
                $newsCustomFields = $news->custom_fields ?? [];

                // Get field configurations to know which fields are images/galleries
                $fieldConfigs = \App\Models\FormFieldConfiguration::forNews()->get();

                foreach ($fieldConfigs as $config) {
                    $value = null;

                    // Handle media field types - fetch from media table
                    if ($config->field_type === 'gallery') {
                        $mediaItems = $news->getMedia($config->field_key);
                        $value = $mediaItems->map(fn($m) => $m->getUrl())->toArray();
                    } elseif ($config->field_type === 'image' || $config->field_type === 'file') {
                        $media = $news->getMedia($config->field_key)->first();
                        $value = $media ? $media->getUrl() : '';
                    } elseif ($config->field_type === 'select' && $config->sync_as_taxonomy) {
                        // SPECIAL HANDLING FOR SYNC_AS_TAXONOMY (Now Text Field Strategy)
                        $rawValue = $newsCustomFields[$config->field_key] ?? null;
                        if (is_array($rawValue))
                            $rawValue = array_values($rawValue)[0] ?? null;

                        if ($rawValue) {
                            $option = collect($config->options)->firstWhere('value', $rawValue);
                            if ($option) {
                                // Set Label as Value
                                $value = $option['label'];

                                // Prepare translations for this field (labels)
                                foreach ($languages as $language) {
                                    if ($language->is_default)
                                        continue;
                                    $termLabel = $option['label_' . $language->code] ?? null;
                                    if ($termLabel) {
                                        // Store in a temporary array or structure to be merged into payload later?
                                        // Unlike SyncController, we don't build the 'translations' array here explicitly yet.
                                        // We need to inject these into the 'custom_fields' of the payload BUT 
                                        // we need to know the structure 'translatable' custom fields expect on the receiving end.

                                        // Usually, `custom_fields` is one flat array.
                                        // The plugin expects multilingual custom fields to be sent.. how?
                                        // A) As an array in the main field? ['en' => 'Val', 'sl' => 'Val'] -> NO, standard fields logic below handles this if $config->is_multilingual is true.
                                        // But here we are resolving the value dynamically.

                                        // Let's look at how SyncController sends them:
                                        // $data['translations'][$language->code]['custom_fields'][$config->field_key] = $termLabel;

                                        // We need to mirror that structure in the payload construction.
                                        // We'll collect these side-translations here.
                                        $sideTranslations[$language->code][$config->field_key] = $termLabel;
                                    }
                                }
                            }
                        }
                    } else {
                        // Regular fields - get from custom_fields JSON
                        $fieldValue = $newsCustomFields[$config->field_key] ?? '';
                        if ($config->is_multilingual && is_array($fieldValue)) {
                            $value = $fieldValue;
                        } else {
                            $value = $fieldValue;
                        }
                    }

                    $customFields[$config->field_key] = $value;
                }

                // ... (existing image logic) ...

                $payload = [
                    'type' => 'news',
                    'data' => [
                        'slug' => $news->slug,
                        // ...
                        'custom_fields' => $customFields,
                        'translations' => [], // Initialize translations array
                    ],
                ];

                // Add Collected Side Translations to Payload
                if (!empty($sideTranslations)) {
                    foreach ($sideTranslations as $lang => $fields) {
                        $payload['data']['translations'][$lang]['custom_fields'] = $fields;
                    }
                }

                // REMOVED: Taxonomies payload logic
                // $taxonomies = []; ...

                $response = Http::timeout(30)
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
}
