<?php

namespace App\Services;

use App\Models\SyncSite;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WordPressSyncService
{
    public function syncSite(SyncSite $site): array
    {
        try {
            Log::info("Starting sync for site: {$site->name}");

            // Use site-specific API key, or fall back to global API key
            $apiKey = $site->api_key ?: app(\App\Settings\GeneralSettings::class)->api_key;

            $headers = [];
            if ($apiKey) {
                $headers['X-API-Key'] = $apiKey;
            }

            $response = Http::timeout(120)
                ->withHeaders($headers)
                ->post($site->url);

            if ($response->successful()) {
                $result = $response->json();

                $site->update([
                    'last_synced_at' => now(),
                    'last_sync_result' => [
                        'success' => true,
                        'imported' => $result['imported'] ?? 0,
                        'errors' => $result['errors'] ?? [],
                        'timestamp' => now()->toIso8601String(),
                    ],
                ]);

                Log::info("Sync completed for site: {$site->name}", $result);

                return [
                    'success' => true,
                    'message' => "Successfully synced {$result['imported']} items",
                    'data' => $result,
                ];
            }

            $errorMessage = $response->body();

            $site->update([
                'last_synced_at' => now(),
                'last_sync_result' => [
                    'success' => false,
                    'error' => $errorMessage,
                    'status_code' => $response->status(),
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);

            Log::error("Sync failed for site: {$site->name}", [
                'status' => $response->status(),
                'error' => $errorMessage,
            ]);

            return [
                'success' => false,
                'message' => "Sync failed: HTTP {$response->status()}",
                'error' => $errorMessage,
            ];

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
}
