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
    protected $signature = 'import:charter-yachts';

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
                    
                    // TEMPORARY: Break after 1 for testing
                    if ($totalImported >= 1) {
                        $this->info("Test mode enabled: stopping after 1 yacht.");
                        break 2; // Break both foreach and do-while loops
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

        // 2. Resolve Charter Location (we match first, or create)
        $locationId = null;
        if (!empty($fields['homeport'])) {
            $location = CharterLocation::where('name', 'like', "%{$fields['homeport']}%")
                ->orWhereRaw("JSON_EXTRACT(name, '$.en') = ?", [$fields['homeport']])
                ->first();
                
            if (!$location) {
                $location = CharterLocation::create([
                    'name' => ['en' => $fields['homeport']],
                ]);
            }
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
        
        $description = !empty($descriptionStr) ? ['en' => $descriptionStr] : null;

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
        $yacht->name = ['en' => $title];
        if (isset($fields['model'])) {
            // If we use yacht_model_id we'd map it here, but since the form maps to model input directly...
            // Let's store model string in custom_fields or name
        }
        $yacht->brand_id = $brandId;
        $yacht->charter_location_id = $locationId;
        $yacht->description = $description;
        $yacht->state = 'published';
        $yacht->price = $fields['low_season_price'] ?? null;
        $yacht->year = $fields['production_year'] ?? null;
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
            foreach ($charter['images'] as $imgObj) {
                if (isset($imgObj['image'])) {
                    $this->addMediaIfNotExists($yacht, $imgObj['image'], 'gallery_exterior', false);
                }
            }
        }

        // 3. PDF Brochure
        if (!empty($fields['brochure_file'])) {
            $this->addMediaIfNotExists($yacht, $fields['brochure_file'], 'pdf_brochure', true);
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
                $yacht->addMediaFromUrl($url)
                      ->withCustomProperties(['original_url' => $url])
                      ->toMediaCollection($collectionName);
            } catch (\Exception $e) {
                $this->error("Failed to download media: {$url}");
            }
        }
    }
}
