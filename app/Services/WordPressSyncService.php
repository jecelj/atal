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
                    } else {
                        // Regular fields - get from custom_fields JSON
                        $fieldValue = $newsCustomFields[$config->field_key] ?? '';

                        // If multilingual, keep the array structure (WordPress will handle it)
                        // If not multilingual, use the value as-is
                        if ($config->is_multilingual && is_array($fieldValue)) {
                            $value = $fieldValue; // Keep array: ['en' => 'text', 'sl' => 'besedilo']
                        } else {
                            $value = $fieldValue;
                        }
                    }

                    $customFields[$config->field_key] = $value;
                }

                // Get featured image URL from Media Library if available
                $featuredImageUrl = null;
                if ($news->hasMedia('featured_image')) {
                    $featuredImageUrl = $news->getFirstMediaUrl('featured_image');
                } elseif ($news->featured_image) {
                    $featuredImageUrl = url('storage/' . $news->featured_image);
                }

                $payload = [
                    'type' => 'news',
                    'data' => [
                        'slug' => $news->slug,
                        'title' => $news->title,
                        'content' => $news->content,
                        'excerpt' => $news->excerpt,
                        'published_at' => $news->published_at ? $news->published_at->toIso8601String() : null,
                        'featured_image' => $featuredImageUrl,
                        'custom_fields' => $customFields,
                    ],
                ];

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
