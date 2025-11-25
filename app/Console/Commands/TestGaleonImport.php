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

        // Mock data for yacht 440 FLY
        $mockData = [
            'source_post_id' => 837,
            'name' => '440 FLY',
            'slug' => '440-fly-test',
            'state' => 'new',
            'brand' => 'Galeon',
            'model' => 'Flybridge',
            'fields' => [
                'sub_titile' => '<p>An exciting endeavor</p>',
                'full_description' => '<div class="row"><p>The sleek exterior marks another collaboration...</p></div>',
                'specifications' => '<table class="yacht-tech-table"><tbody><tr><td>Length overall</td><td>13,97 m</td></tr></tbody></table>',
                'lenght' => 13.97,
            ],
            'media' => [
                'cover_image' => 'https://galeonadriatic.com/wp-content/uploads/2025/06/DJI_0311.webp',
                'grid_image' => 'https://galeonadriatic.com/wp-content/uploads/2025/06/DJI_0322.webp',
                'grid_image_hover' => 'https://galeonadriatic.com/wp-content/uploads/2025/06/DJI_0310.webp',
                'pdf_brochure' => 'https://galeonadriatic.com/wp-content/uploads/2025/06/Broszura-Galeon_440-5-1.pdf',
                'video_url' => 'https://www.youtube.com/watch?v=example',
                'gallery_exterior' => [
                    'https://galeonadriatic.com/wp-content/uploads/slider/58/IMG_0814.webp',
                    'https://galeonadriatic.com/wp-content/uploads/slider/58/IMG_0832.webp',
                ],
                'gallery_interrior' => [
                    'https://galeonadriatic.com/wp-content/uploads/slider/60/IMG_0898.webp',
                ],
                'gallery_cockpit' => [
                    'https://galeonadriatic.com/wp-content/uploads/slider/59/IMG_0731.webp',
                ],
                'gallery_layout' => [
                    'https://galeonadriatic.com/wp-content/uploads/2025/06/494-Galeon-440FLY-Coloured-GA-Fly-Deck-14.10.2022.webp',
                    'https://galeonadriatic.com/wp-content/uploads/2025/06/494-Galeon-440FLY-Coloured-GA-Main-Deck-14.10.2022.webp',
                ],
            ],
            'skipped_fields' => [],
        ];

        $this->info('Mock data prepared:');
        $this->line('  Name: ' . $mockData['name']);
        $this->line('  Model: ' . $mockData['model']);
        $this->line('  Length: ' . $mockData['fields']['lenght'] . ' m');

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
