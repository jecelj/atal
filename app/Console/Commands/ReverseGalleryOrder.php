<?php

namespace App\Console\Commands;

use App\Models\NewYacht;
use App\Models\FormFieldConfiguration;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ReverseGalleryOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'atal:reverse-gallery {--yacht_id= : The ID of the yacht to process} {--collection= : Specific collection to reverse}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reverse the order of images in gallery collections for New Yachts';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $yachtId = $this->option('yacht_id');
        $specificCollection = $this->option('collection');

        $query = NewYacht::query();
        if ($yachtId) {
            $query->where('id', $yachtId);
        }

        $yachts = $query->get();
        $this->info("Found {$yachts->count()} yachts to process.");

        // 1. Identify all gallery collections
        $collections = [
            'gallery_exterior',
            'gallery_interior',
            'gallery_exterrior', // typo handling
            'gallery_interrior', // typo handling
            'gallery_cockpit',
        ];

        // Add dynamic collections from configuration
        $dynamicCollections = FormFieldConfiguration::forNewYachts()
            ->where('field_type', 'gallery')
            ->pluck('field_key')
            ->toArray();

        $allCollections = array_unique(array_merge($collections, $dynamicCollections));

        if ($specificCollection) {
            if (!in_array($specificCollection, $allCollections)) {
                $this->warn("Collection '{$specificCollection}' is not a known gallery collection, but processing it anyway.");
            }
            $allCollections = [$specificCollection];
        }

        foreach ($yachts as $yacht) {
            $this->info("Processing Yacht ID: {$yacht->id} ({$yacht->name})");

            foreach ($allCollections as $collection) {
                $mediaItems = $yacht->getMedia($collection)->sortBy('order_column');

                if ($mediaItems->count() <= 1) {
                    continue;
                }

                $this->line("  - Reversing collection: {$collection} ({$mediaItems->count()} items)");

                $reversed = $mediaItems->reverse()->values();

                foreach ($reversed as $index => $media) {
                    $media->order_column = $index + 1;
                    $media->save();
                }
            }
        }

        $this->info('Gallery order reversal completed.');
    }
}
