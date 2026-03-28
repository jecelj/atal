<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\CharterLocation;
use App\Models\CharterYacht;
use App\Models\YachtModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImportCharterYachts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:charter-yachts {--limit=0 : Number of yachts to import (0 for all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import charter yachts from YachtsCroatia API';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Charter Yachts Import...');

        $username = 'yachts';
        $password = 'croatia';
        $baseUrl = 'https://www.yachtscroatia.com';
        
        $page = 1;
        $totalPages = 1;
        $totalImported = 0;

        do {
            $this->info("Fetching page {$page}...");
            
            $response = Http::withBasicAuth($username, $password)
                ->timeout(60)
                ->get("{$baseUrl}/yachtsapi/charters", [
                    'page' => $page
                ]);

            if (!$response->successful()) {
                $this->error("Failed to fetch page {$page}. Status: " . $response->status());
                break;
            }

            $data = $response->json();
            
            if (!isset($data['charters']) || empty($data['charters'])) {
                break;
            }

            // Calculate total pages
            if ($page === 1 && isset($data['paging']['last'])) {
                parse_str(parse_url($data['paging']['last'], PHP_URL_QUERY), $query);
                if (isset($query['page'])) {
                    $totalPages = (int) $query['page'];
                    $this->info("Total pages found: {$totalPages}");
                }
            }

            foreach ($data['charters'] as $charter) {
                try {
                    $this->importCharter($charter);
                    $totalImported++;
                    
                    if ($limit > 0 && $totalImported >= $limit) {
                        $this->info("Test mode enabled/limit reached: stopping after {$limit} yachts.");
                        break 2;
                    }
                    
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error("Import Charter Error: " . $e->getMessage());
                    $this->error("Failed to import {$charter['name']}: " . $e->getMessage());
                }
            }

            $page++;
        } while ($page <= $totalPages);

        $this->info("Import completed! Total yachts processed: {$totalImported}");
    }

    protected function importCharter(array $charter)
    {
        $fields = $charter['fields'] ?? [];
        $title = $fields['title'] ?? 'Unknown Yacht';
        
        $this->info("Processing: {$title}");

        // 1. Resolve Brand
        $brandId = null;
        if (!empty($fields['builder'])) {
            $brand = Brand::firstOrCreate(
                ['name' => $fields['builder']],
                ['slug' => Str::slug($fields['builder'])]
            );
            $brandId = $brand->id;
        }

        // 2. Resolve Charter Location
        $locationId = null;
        if (!empty($fields['homeport'])) {
            $location = CharterLocation::firstOrCreate(
                ['name' => $fields['homeport']],
                ['slug' => Str::slug($fields['homeport'])]
            );
            $locationId = $location->id;
        }

        // Assemble Description
        $descriptionStr = '';
        // Note: the API does not provide a root 'content' field
        if (!empty($fields['full_intro'])) $descriptionStr .= "<strong>Intro</strong><br>" . $fields['full_intro'] . "<br><br>";
        if (!empty($fields['interior_intro'])) $descriptionStr .= "<strong>Interior</strong><br>" . $fields['interior_intro'] . "<br><br>";
        if (!empty($fields['exterior_intro'])) $descriptionStr .= "<strong>Exterior</strong><br>" . $fields['exterior_intro'];
        
        // Append Key Features to rich text
        if (!empty($charter['key_features']) && is_array($charter['key_features'])) {
            $descriptionStr .= "<br><h3>Key Features</h3><ul>";
            foreach ($charter['key_features'] as $feature) {
                if (!empty($feature['title'])) {
                    $descriptionStr .= "<li><strong>{$feature['title']}</strong>";
                    if (!empty($feature['body'])) {
                        $bodyRaw = strip_tags($feature['body']);
                        $descriptionStr .= ": {$bodyRaw}";
                    }
                    $descriptionStr .= "</li>";
                }
            }
            $descriptionStr .= "</ul>";
        }
        
        $description = !empty($descriptionStr) ? [
            'en' => $descriptionStr,
            'sl' => $descriptionStr
        ] : null;

        // Custom fields map based on user array
        $customFields = [
            'api_id' => null, // No ID provided by the API
            'year' => $fields['production_year'] ?? null,
            'lenght' => $fields['length'] ?? null, 
            'Cabins' => isset($fields['cabins_number']) ? (string)$fields['cabins_number'] : '',
            'Bathrooms' => '', // API doesn't provide explicit count
            'guests_number' => $fields['guests_number'] ?? null,
            'crew_number' => $fields['crew_number'] ?? null,
            'engine' => $fields['engines'] ?? '',
            'engine_fuel' => '',
            'low_season_price' => $fields['low_season_price'] ?? null,
            'high_season_price' => $fields['high_season_price'] ?? null,
            'description' => $description, // Saved to dynamic custom_fields
            'Description' => $description, // Just in case it's capitalized
            
            // extra data stored just in case
            'beam' => $fields['beam'] ?? null,
            'draft' => $fields['draft'] ?? null,
            'maximum_speed' => $fields['maximum_speed'] ?? null,
            'amenities_tags' => $fields['amenities_tags'] ?? null,
            'water_toys_tags' => $fields['water_toys_tags'] ?? null,
        ];

        // Find purely by slug since the API provides no unique IDs
        $yacht = CharterYacht::where('slug', Str::slug($title))->first() ?: new CharterYacht();
        
        // Use property assignment instead of updateOrCreate to bypass fillable limits
        $yacht->slug = Str::slug($title);
        $yacht->name = [
            'en' => $title,
            'sl' => $title
        ];
        $yacht->brand_id = $brandId;
        $yacht->charter_location_id = $locationId;
        $yacht->state = 'published';
        $yacht->price = $fields['low_season_price'] ?? null;
        $yacht->custom_fields = $customFields;
        
        $yacht->save();

        $this->syncMedia($yacht, $charter, $fields);
    }

    protected function syncMedia(CharterYacht $yacht, array $charter, array $fields)
    {
        // 1. Grid Image
        $gridImageUrl = $fields['image'] ?? $fields['image_portrait'] ?? null;
        if ($gridImageUrl) {
            $this->addMediaIfNotExists($yacht, $gridImageUrl, 'grid_image', true);
        }

        // 2. Collection Images (Gallery)
        if (!empty($charter['images']) && is_array($charter['images'])) {
            $imagesReversed = array_reverse($charter['images']); // Reverse to show the first image at the top
            foreach ($imagesReversed as $imgObj) {
                if (isset($imgObj['image'])) {
                    // Added gallery and Gallery to match potential dynamic field keys
                    $this->addMediaIfNotExists($yacht, $imgObj['image'], 'gallery_exterior', false);
                    $this->addMediaIfNotExists($yacht, $imgObj['image'], 'gallery', false);
                    $this->addMediaIfNotExists($yacht, $imgObj['image'], 'Gallery', false);
                }
            }
        }

        // 3. PDF Brochure
        if (!empty($fields['brochure_file'])) {
            // Added brochure and Brochure to match potential dynamic field keys
            $this->addMediaIfNotExists($yacht, $fields['brochure_file'], 'pdf_brochure', true);
            $this->addMediaIfNotExists($yacht, $fields['brochure_file'], 'brochure', true);
            $this->addMediaIfNotExists($yacht, $fields['brochure_file'], 'Brochure', true);
        }

        // 4. Sample Menu -> PDF Presentation
        if (!empty($fields['sample_menu_file'])) {
            $this->addMediaIfNotExists($yacht, $fields['sample_menu_file'], 'pdf_presentation', true);
        }
    }

    protected function addMediaIfNotExists(CharterYacht $yacht, $url, $collectionName, $isSingle = false)
    {
        // Check if media already exists by original URL
        $exists = $yacht->getMedia($collectionName)->contains(function ($media) use ($url) {
            return $media->getCustomProperty('original_url') === $url;
        });

        if (!$exists) {
            try {
                if ($isSingle) {
                    $yacht->clearMediaCollection($collectionName);
                }
                
                // Sometimes urls lack extensions, force it if missing so Spatie accepts it
                $filename = basename(parse_url($url, PHP_URL_PATH));
                if (empty($filename) || !str_contains($filename, '.')) {
                    $extension = str_contains($collectionName, 'pdf') ? '.pdf' : '.jpg';
                    $filename = 'media-' . uniqid() . $extension;
                }
                
                $yacht->addMediaFromUrl($url)
                      ->usingFileName($filename)
                      ->withCustomProperties(['original_url' => $url])
                      ->toMediaCollection($collectionName);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Media download failed for {$url}: " . $e->getMessage());
                $this->error("Failed to download media: {$url}");
            }
        }
    }
}
