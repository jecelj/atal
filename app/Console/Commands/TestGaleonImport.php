<?php

namespace App\Console\Commands;

use App\Services\GaleonMigrationService;
use Illuminate\Console\Command;

class TestGaleonImport extends Command
{
    protected $signature = 'galeon:test-import';
    protected $description = 'Test Galeon yacht import with mock data';

    protected $migrationService;

    public function __construct(GaleonMigrationService $migrationService)
    {
        parent::__construct();
        $this->migrationService = $migrationService;
    }

    public function handle()
    {
        $this->info('Testing Galeon yacht import...');

        // Real data for yacht 800 FLY (Post ID 907) - LIMITED GALLERIES FOR TEST
        $mockData = [
            'source_post_id' => 907,
            'name' => '800 FLY',
            'slug' => '800-fly-test',
            'state' => 'new',
            'brand' => 'Galeon',
            'model' => 'Flybridge',
            'fields' => [
                'sub_titile' => '<p>The Flagship</p>',
                'full_description' => '<div class="row"><div class="col-5 col-push-1 text-first"><p class="p1">Thanks to many years of experience, passed down in our company from generation to generation, we have created the Galeon 800 FLY, which combines a sporty silhouette and impressive performance with a sense of space and luxury previously reserved only for megayachts.</p></div></div>',
                'specifications' => '<table class="yacht-tech-table"><tbody><tr><td class="name">Length overall</td><td class="unit-si">[m]</td><td class="value-si">25,35</td><td class="unit-imperial">[ft]</td><td class="value-imperial">83\'2"</td></tr></tbody></table>',
                'lenght' => 25.35,
            ],
            'media' => [
                'cover_image' => 'https://galeonadriatic.com/wp-content/uploads/2025/06/DJI_0961.webp',
                'grid_image' => 'https://galeonadriatic.com/wp-content/uploads/2025/06/DJI_0068.webp',
                'grid_image_hover' => 'https://galeonadriatic.com/wp-content/uploads/2025/06/DJI_0046.webp',
                'pdf_brochure' => 'https://galeonadriatic.com/wp-content/uploads/2025/06/Galeon-800fly-brochure-1.pdf',
                'video_url' => 'https://www.youtube.com/watch?v=Scx',
                'gallery_exterior' => [
                    'https://galeonadriatic.com/wp-content/uploads/slider36/DJI_0025.webp',
                    'https://galeonadriatic.com/wp-content/uploads/slider36/DJI_0023.webp',
                    'https://galeonadriatic.com/wp-content/uploads/slider36/DJI_0046.webp',
                    'https://galeonadriatic.com/wp-content/uploads/slider36/DJI_0068.webp',
                    'https://galeonadriatic.com/wp-content/uploads/slider36/DJI_0100.webp',
                ],
                'gallery_interrior' => [
                    'https://galeonadriatic.com/wp-content/uploads/slider38/_MG_6477-HDR.webp',
                    'https://galeonadriatic.com/wp-content/uploads/slider38/_MG_6480-HDR.webp',
                    'https://galeonadriatic.com/wp-content/uploads/slider38/_MG_6497-HDR.webp',
                    'https://galeonadriatic.com/wp-content/uploads/slider38/_MG_6510-HDR.webp',
                ],
                'gallery_cockpit' => [
                    'https://galeonadriatic.com/wp-content/uploads/slider37/_MG_4280.webp',
                    'https://galeonadriatic.com/wp-content/uploads/slider37/_MG_4305.webp',
                    'https://galeonadriatic.com/wp-content/uploads/slider37/_MG_4267.webp',
                ],
                'gallery_layout' => [
                    'https://galeonadriatic.com/wp-content/uploads/2025/06/498_Galeon-800-Fly-_Marketing-GA-01_2020-01-08_Upper-Deck-v2-HIGH-RES__MODYFIKACJA.webp',
                    'https://galeonadriatic.com/wp-content/uploads/2025/06/498_Galeon-800-Fly-_Marketing-GA-01_2020-01-08_Upper-Deck-HIGH-RES__MODYFIKACJA.webp',
                    'https://galeonadriatic.com/wp-content/uploads/2025/06/498_Galeon-800-Fly-_Marketing-GA-01_2020-01-08_Main-Deck-HIGH-RES__MODYFIKACJA.webp',
                ],
            ],
            'skipped_fields' => [],
        ];

        $this->info('Mock data prepared:');
        $this->line('  Name: ' . $mockData['name']);
        $this->line('  Model: ' . $mockData['model']);
        $this->line('  Length: ' . $mockData['fields']['lenght'] . ' m');
        $this->line('  Galleries: ' . (count($mockData['media']['gallery_exterior']) + count($mockData['media']['gallery_interrior']) + count($mockData['media']['gallery_cockpit']) + count($mockData['media']['gallery_layout'])) . ' images (limited for test)');

        $this->newLine();
        $this->info('Starting import...');

        $result = $this->migrationService->importYacht($mockData);

        if ($result['success']) {
            $this->newLine();
            $this->info('✅ Import successful!');
            $this->line('  Yacht ID: ' . $result['yacht_id']);
            $this->line('  Yacht Name: ' . $result['yacht_name']);
            $this->newLine();
            $this->info('Check Filament admin to verify the yacht was created correctly.');
        } else {
            $this->newLine();
            $this->error('❌ Import failed!');
            $this->error('  Error: ' . $result['error']);
        }

        return $result['success'] ? 0 : 1;
    }
}
