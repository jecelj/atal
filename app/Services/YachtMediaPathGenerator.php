<?php

namespace App\Services;

use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Support\Str;

class YachtMediaPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->getBasePath($media) . '/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getBasePath($media) . '/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getBasePath($media) . '/responsive/';
    }

    protected function getBasePath(Media $media): string
    {
        // Get the yacht model
        $yacht = $media->model;

        if (!$yacht) {
            return 'media/' . $media->id;
        }

        // Build path: yachts/brand-slug/model-slug/yacht-slug
        $brandSlug = $yacht->brand ? Str::slug($yacht->brand->name) : 'unknown-brand';
        $modelSlug = $yacht->yachtModel ? Str::slug($yacht->yachtModel->name) : 'unknown-model';
        $yachtSlug = Str::slug($yacht->name) ?: 'yacht-' . $yacht->id;

        return "yachts/{$brandSlug}/{$modelSlug}/{$yachtSlug}";
    }
}
