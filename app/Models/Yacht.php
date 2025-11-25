<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Image\Enums\Fit;

class Yacht extends Model implements HasMedia
{
    use HasFactory, HasTranslations, InteractsWithMedia;

    public $translatable = ['name', 'description', 'specifications'];

    protected $fillable = [
        'type',
        'state',
        'brand_id',
        'yacht_model_id',
        'name',
        'slug',
        'description',
        'specifications',
        'price',
        'year',
        'custom_fields',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'year' => 'integer',
        'custom_fields' => 'array',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function yachtModel(): BelongsTo
    {
        return $this->belongsTo(YachtModel::class);
    }

    /**
     * Register media conversions - Convert all images to WebP only
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        // Skip non-image files (PDFs, etc.)
        if ($media && !str_starts_with($media->mime_type, 'image/')) {
            return;
        }

        // No conversions needed - we'll use manipulations to convert original to WebP
    }

    /**
     * Register media collections with WebP conversion
     */
    public function registerMediaCollections(): void
    {
        // Helper to add WebP-only image collection
        $addWebPCollection = function ($name, $singleFile = false) {
            $collection = $this->addMediaCollection($name)
                ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'])
                ->useDisk('public');

            if ($singleFile) {
                $collection->singleFile();
            }

            return $collection;
        };

        // Single file collections
        $addWebPCollection('featured_image', true);
        $addWebPCollection('cover_image', true);
        $addWebPCollection('cover_image_hover', true);

        // Gallery collections (multiple files)
        $addWebPCollection('gallery_exterior');
        $addWebPCollection('gallery_interior');
        $addWebPCollection('gallery_exterrior');
        $addWebPCollection('gallery_interrior');
        $addWebPCollection('gallery_cockpit');

        // PDF collection (no conversion)
        $this->addMediaCollection('pdf_presentation')
            ->singleFile()
            ->acceptsMimeTypes(['application/pdf']);

        $this->addMediaCollection('pdf_brochure')
            ->singleFile()
            ->acceptsMimeTypes(['application/pdf']);
    }
}
