<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
        'is_featured',
        'brand_id',
        'yacht_model_id',
        'location_id',
        'name',
        'slug',
        'description',
        'specifications',
        'price',
        'year',
        'custom_fields',
        'img_opt_status',
        'translation_status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'year' => 'integer',
        'custom_fields' => 'array',
        'is_featured' => 'boolean',
        'img_opt_status' => 'boolean',
        'translation_status' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();

        // A model name is a product designation, not marketing copy. Keep one
        // canonical English value for every locale regardless of whether it was
        // saved through Filament, an importer, or an AI translation job.
        static::saving(function (self $yacht): void {
            $yacht->synchronizeNameTranslations();
        });
    }

    /**
     * Copy the English yacht name to every configured and already stored locale.
     *
     * If a legacy record has no English value, its first non-empty existing name
     * becomes the canonical English value so the record is not left untranslated.
     *
     * @param iterable<string>|null $locales Allows batch commands and tests to
     *                                        provide an already loaded locale list.
     */
    public function synchronizeNameTranslations(?iterable $locales = null): bool
    {
        $existing = $this->getTranslations('name');
        $englishName = trim((string) ($existing['en'] ?? ''));

        if ($englishName === '') {
            foreach ($existing as $name) {
                $candidate = trim((string) $name);

                if ($candidate !== '') {
                    $englishName = $candidate;
                    break;
                }
            }
        }

        if ($englishName === '') {
            return false;
        }

        $localeCodes = $locales ?? Language::query()->pluck('code');
        $localeCodes = array_values(array_unique(array_filter([
            'en',
            ...collect($localeCodes)->map(fn ($code) => trim((string) $code))->all(),
            ...array_keys($existing),
        ])));

        $normalized = array_fill_keys($localeCodes, $englishName);

        // Array key order is irrelevant for JSON translations. Avoid marking an
        // otherwise identical title as dirty just because a legacy row stored
        // locale keys in a different order.
        if ($existing == $normalized) {
            return false;
        }

        $this->setTranslations('name', $normalized);

        return true;
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function yachtModel(): BelongsTo
    {
        return $this->belongsTo(YachtModel::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Always load media in the saved gallery order.
     *
     * The ID is a deterministic tie-breaker for legacy media that share an
     * order value, so reopening a record cannot randomly shuffle thumbnails.
     */
    public function media(): MorphMany
    {
        return $this->morphMany($this->getMediaModel(), 'model')
            ->orderBy('order_column')
            ->orderBy('id');
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
        $addWebPCollection('grid_image', true);
        $addWebPCollection('grid_image_hover', true);

        // Gallery collections (multiple files)
        $addWebPCollection('gallery_exterior');
        $addWebPCollection('gallery_interior');
        $addWebPCollection('gallery_exterrior'); // Typo legacy
        $addWebPCollection('gallery_interrior'); // Typo legacy
        $addWebPCollection('gallery_cockpit');
        $addWebPCollection('gallery_layout');

        // PDF collection (no conversion)
        $this->addMediaCollection('pdf_presentation')
            ->singleFile()
            ->acceptsMimeTypes(['application/pdf']);

        $this->addMediaCollection('pdf_brochure')
            ->singleFile()
            ->acceptsMimeTypes(['application/pdf']);
    }
}
