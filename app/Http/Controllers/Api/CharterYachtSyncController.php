<?php

namespace App\Http\Controllers\Api;

use App\Models\CharterYacht;
use App\Models\FormFieldConfiguration;
use App\Models\SyncSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controller;

class CharterYachtSyncController extends Controller
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
        $yachts = CharterYacht::with(['brand', 'yachtModel', 'media'])
            ->where('state', 'published')
            ->get();

        $yachtData = $yachts->map(function ($yacht) {
            return $this->prepareYachtData($yacht);
        })->toArray();

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $site->api_key,
                'Content-Type' => 'application/json',
            ])->timeout(300)->post($site->url . '/wp-json/atal-charter-yachts/v1/sync', $yachtData);

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
        $fields = FormFieldConfiguration::forCharterYachts()->ordered()->get();

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
            ])->post($site->url . '/wp-json/atal-charter-yachts/v1/config', $fieldData);

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
    protected function prepareYachtData(CharterYacht $yacht)
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

        // Build taxonomies payload
        $configs = FormFieldConfiguration::forCharterYachts()->get();
        $languages = \App\Models\Language::all();
        $taxonomies = [];

        foreach ($configs as $config) {
            if ($config->sync_as_taxonomy && $config->field_type === 'select') {
                $value = $yacht->custom_fields[$config->field_key] ?? null;

                if (is_array($value)) {
                    $value = array_values($value)[0] ?? null;
                }

                if (!$value)
                    continue;

                $option = collect($config->options)->firstWhere('value', $value);
                if (!$option)
                    continue;

                $termTranslations = [];
                foreach ($languages as $language) {
                    if ($language->is_default)
                        continue;
                    $termLabel = $option['label_' . $language->code] ?? null;
                    if ($termLabel) {
                        $termTranslations[$language->code] = $termLabel;
                    }
                }

                $taxonomies[$config->field_key] = [
                    'term' => $option['label'],
                    'translations' => $termTranslations,
                ];
            }
        }

        $data['taxonomies'] = $taxonomies;

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

    /**
     * Sync a single yacht to a site (for testing)
     */
    public function syncSingleYacht(Request $request, $siteId, $yachtId)
    {
        $site = SyncSite::findOrFail($siteId);
        $yacht = CharterYacht::with(['brand', 'yachtModel', 'media'])->findOrFail($yachtId);

        // First, sync field configuration
        $configResult = $this->syncFieldConfiguration($site);

        if (!$configResult['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync field configuration',
                'error' => $configResult['error'] ?? 'Unknown error',
            ], 500);
        }

        $yachtData = [$this->prepareYachtData($yacht)];

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $site->api_key,
                'Content-Type' => 'application/json',
            ])->timeout(300)->post($site->url . '/wp-json/atal-charter-yachts/v1/sync', $yachtData);

            if ($response->successful()) {
                $result = $response->json();
                return response()->json([
                    'success' => true,
                    'message' => "Synced yacht '{$yacht->name}' successfully",
                    'result' => $result,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Sync failed',
                    'error' => $response->body(),
                ], $response->status());
            }
        } catch (\Exception $e) {
            Log::error('Single yacht sync failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Sync failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
