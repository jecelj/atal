<?php

namespace App\Http\Controllers\Api;

use App\Models\UsedYacht;
use App\Models\FormFieldConfiguration;
use App\Models\SyncSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UsedYachtSyncController extends Controller
{
    /**
     * Sync all used yachts to a WordPress site
     */
    public function syncToSite(Request $request, $siteId)
    {
        $site = SyncSite::findOrFail($siteId);

        // First, sync field configuration
        $configResult = $this->syncFieldConfiguration($site);

        if (!$configResult['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync field configuration',
                'error' => $configResult['error'] ?? 'Unknown error',
            ], 500);
        }

        // Then, sync yachts
        $yachts = UsedYacht::with(['brand', 'yachtModel', 'media'])
            ->where('state', 'published')
            ->get();

        $yachtData = $yachts->map(function ($yacht) {
            return $this->prepareYachtData($yacht);
        })->toArray();

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $site->api_key,
                'Content-Type' => 'application/json',
            ])->timeout(300)->post($site->url . '/wp-json/atal-used-yachts/v1/sync', $yachtData);

            if ($response->successful()) {
                $result = $response->json();
                return response()->json([
                    'success' => true,
                    'message' => "Synced {$result['imported']} yachts successfully",
                    'imported' => $result['imported'],
                    'failed' => $result['failed'],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Sync failed',
                    'error' => $response->body(),
                ], $response->status());
            }
        } catch (\Exception $e) {
            Log::error('Used yacht sync failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Sync failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync field configuration to WordPress
     */
    protected function syncFieldConfiguration(SyncSite $site)
    {
        $fields = FormFieldConfiguration::forUsedYachts()->ordered()->get();

        $fieldData = $fields->map(function ($field) {
            return [
                'field_key' => $field->field_key,
                'field_type' => $field->field_type,
                'label' => $field->label,
                'group' => $field->group,
                'is_required' => $field->is_required,
                'is_multilingual' => $field->is_multilingual,
                'options' => $field->options,
            ];
        })->toArray();

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $site->api_key,
                'Content-Type' => 'application/json',
            ])->post($site->url . '/wp-json/atal-used-yachts/v1/config', $fieldData);

            return [
                'success' => $response->successful(),
                'error' => $response->successful() ? null : $response->body(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Prepare yacht data for sync
     */
    protected function prepareYachtData(UsedYacht $yacht)
    {
        $data = [
            'slug' => $yacht->slug,
            'name' => $yacht->name,
            'state' => $yacht->state,
            'brand' => $yacht->brand?->name,
            'model' => $yacht->yachtModel?->name,
            'custom_fields' => $yacht->custom_fields ?? [],
            'media' => [],
        ];

        // Collect all media
        $mediaCollections = $yacht->getMedia('*')->groupBy('collection_name');

        foreach ($mediaCollections as $collection => $items) {
            $data['media'][$collection] = $items->map(function ($media) {
                return [
                    'url' => $media->getUrl(),
                    'name' => $media->name,
                ];
            })->toArray();
        }

        return $data;
    }

    /**
     * Sync all sites
     */
    public function syncAllSites()
    {
        $sites = SyncSite::where('is_active', true)->get();
        $results = [];

        foreach ($sites as $site) {
            $result = $this->syncToSite(request(), $site->id);
            $results[] = [
                'site' => $site->name,
                'result' => $result->getData(),
            ];
        }

        return response()->json([
            'success' => true,
            'results' => $results,
        ]);
    }
}
