<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\YachtModel;
use App\Models\NewYacht;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GaleonMigrationService
{
    /**
     * Import yacht from galeonadriatic.com
     * 
     * @param array $data Yacht data from WordPress
     * @return array Result with success status
     */
    public function importYacht(array $data)
    {
        Log::info('Starting yacht import', ['source_post_id' => $data['source_post_id']]);

        try {
            // Ensure brand exists (Galeon)
            $brand = $this->ensureBrandExists($data['brand']);

            // Ensure model exists
            $model = $this->ensureModelExists($data['model'], $brand->id);

            // Create or update yacht
            $yacht = NewYacht::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => ['en' => $data['name']],
                    'brand_id' => $brand->id,
                    'yacht_model_id' => $model->id,
                    'state' => $data['state'],
                ]
            );

            Log::info('Yacht record created/updated', ['yacht_id' => $yacht->id]);

            // Update custom fields
            $this->updateCustomFields($yacht, $data['fields']);

            // Handle media
            $this->handleMedia($yacht, $data['media']);

            // Log skipped fields
            if (!empty($data['skipped_fields'])) {
                Log::warning('Skipped fields', [
                    'yacht_id' => $yacht->id,
                    'fields' => array_keys($data['skipped_fields']),
                ]);
            }

            return [
                'success' => true,
                'yacht_id' => $yacht->id,
                'yacht_name' => $yacht->name,
                'message' => 'Yacht imported successfully',
            ];

        } catch (\Exception $e) {
            Log::error('Import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Ensure brand exists, create if not
     */
    protected function ensureBrandExists($brand_name)
    {
        $brand = Brand::firstOrCreate(
            ['name' => $brand_name],
            ['name' => $brand_name]
        );

        Log::info('Brand ensured', ['brand_id' => $brand->id, 'name' => $brand_name]);
        return $brand;
    }

    /**
     * Ensure model exists, create if not
     */
    protected function ensureModelExists($model_name, $brand_id)
    {
        $slug = Str::slug($model_name);

        $model = YachtModel::firstOrCreate(
            [
                'name' => $model_name,
                'brand_id' => $brand_id,
            ],
            [
                'name' => $model_name,
                'slug' => $slug,
                'brand_id' => $brand_id,
            ]
        );

        Log::info('Model ensured', ['model_id' => $model->id, 'name' => $model_name]);
        return $model;
    }

    /**
     * Update custom fields
     */
    protected function updateCustomFields($yacht, $fields)
    {
        // Build custom_fields JSON with multilingual support
        $customFields = [];

        foreach ($fields as $key => $value) {
            if ($value !== null && $value !== '') {
                // For multilingual fields, set only EN
                if (in_array($key, ['sub_titile', 'full_description', 'specifications'])) {
                    $customFields[$key] = ['en' => $value];
                } else {
                    $customFields[$key] = $value;
                }
            }
        }

        // Store in custom_fields JSON column with EN language
        $yacht->custom_fields = ['en' => $customFields];
        $yacht->save();

        Log::info('Custom fields updated', ['yacht_id' => $yacht->id, 'fields' => array_keys($fields)]);
    }

    /**
     * Handle media download and upload
     */
    protected function handleMedia($yacht, $media)
    {
        // Handle single images
        if (!empty($media['cover_image'])) {
            $this->downloadAndUploadMedia($media['cover_image'], $yacht, 'cover_image');
        }

        if (!empty($media['grid_image'])) {
            $this->downloadAndUploadMedia($media['grid_image'], $yacht, 'grid_image');
        }

        if (!empty($media['grid_image_hover'])) {
            $this->downloadAndUploadMedia($media['grid_image_hover'], $yacht, 'grid_image_hover');
        }

        // Handle PDF
        if (!empty($media['pdf_brochure'])) {
            $this->downloadAndUploadMedia($media['pdf_brochure'], $yacht, 'pdf_brochure');
        }

        // Handle video URL - store in custom_fields
        if (!empty($media['video_url'])) {
            $customFields = $yacht->custom_fields ?? ['en' => []];
            if (!isset($customFields['en'])) {
                $customFields['en'] = [];
            }
            $customFields['en']['video_url'] = $media['video_url'];
            $yacht->custom_fields = $customFields;
            $yacht->save();
            Log::info('Video URL saved', ['url' => $media['video_url']]);
        }

        // Handle galleries
        $galleries = [
            'gallery_exterior' => 'gallery_exterior',
            'gallery_interrior' => 'gallery_interrior',
            'gallery_cockpit' => 'gallery_cockpit',
            'gallery_layout' => 'gallery_layout',
        ];

        foreach ($galleries as $key => $collection) {
            if (!empty($media[$key]) && is_array($media[$key])) {
                $this->downloadAndUploadGallery($media[$key], $yacht, $collection);
            }
        }
    }

    /**
     * Download and upload single media file
     */
    protected function downloadAndUploadMedia($url, $yacht, $collection)
    {
        try {
            Log::info("Downloading media", ['url' => $url, 'collection' => $collection]);

            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                Log::error("Failed to download media", ['url' => $url, 'status' => $response->status()]);
                return;
            }

            $filename = basename(parse_url($url, PHP_URL_PATH));
            $tempPath = storage_path('app/temp/' . $filename);

            // Ensure temp directory exists
            if (!file_exists(dirname($tempPath))) {
                mkdir(dirname($tempPath), 0755, true);
            }

            file_put_contents($tempPath, $response->body());

            // Add to media library
            $yacht->addMedia($tempPath)
                ->toMediaCollection($collection);

            // Clean up temp file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            Log::info("Media uploaded successfully", ['collection' => $collection]);

        } catch (\Exception $e) {
            Log::error("Media upload failed", [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Download and upload gallery images
     */
    protected function downloadAndUploadGallery(array $urls, $yacht, $collection)
    {
        Log::info("Downloading gallery", ['collection' => $collection, 'count' => count($urls)]);

        foreach ($urls as $url) {
            $this->downloadAndUploadMedia($url, $yacht, $collection);
        }
    }
}
