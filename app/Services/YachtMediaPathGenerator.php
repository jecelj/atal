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
        // Get the yacht model (NewYacht or UsedYacht)
        $yacht = $media->model;

        if (!$yacht) {
            return 'media/' . $media->id;
        }

        // Updated Strategy (Dec 2025): Use ID-based path for stability
        // This prevents broken images when renaming Brand/Model or switching Languages
        // ONLY apply to Yachts (New/Used) to avoid breaking News or colliding IDs
        if ($yacht instanceof \App\Models\NewYacht || $yacht instanceof \App\Models\UsedYacht) {
            return "yachts/{$yacht->id}";
        }

        // Fallback for News or other models (Preserve legacy "unknown-brand" structure)
        $brandSlug = $yacht->brand ? \Illuminate\Support\Str::slug($yacht->brand->name) : 'unknown-brand';
        $modelSlug = $yacht->yachtModel ? \Illuminate\Support\Str::slug($yacht->yachtModel->name) : 'unknown-model';
        $nameSlug = $yacht->name;
        // Handle translatable name array for fallback
        if (is_array($nameSlug)) {
            $nameSlug = $nameSlug['en'] ?? reset($nameSlug);
        }
        $yachtSlug = \Illuminate\Support\Str::slug($nameSlug) ?: 'item-' . $yacht->id;

        return "yachts/{$brandSlug}/{$modelSlug}/{$yachtSlug}";
    }
}
