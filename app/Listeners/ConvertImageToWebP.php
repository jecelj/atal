<?php

namespace App\Listeners;

use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\Image\Image;
use Illuminate\Support\Facades\Log;

class ConvertImageToWebP
{
    public function handle(MediaHasBeenAddedEvent $event): void
    {
        $media = $event->media;

        Log::info("Listener: Processing media ID {$media->id} ({$media->file_name})");

        // Only process images
        if (!str_starts_with($media->mime_type, 'image/')) {
            return;
        }

        // Skip if already WebP
        if ($media->mime_type === 'image/webp') {
            return;
        }

        try {
            // Get the original file path
            $originalPath = $media->getPath();

            // Handle array path
            if (is_array($originalPath)) {
                $originalPath = $originalPath[0] ?? null;
            }

            if (!$originalPath || !file_exists($originalPath)) {
                Log::error("Listener: File not found for {$media->id} at {$originalPath}");
                return;
            }

            // Create WebP version path
            $webpPath = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $originalPath);
            Log::info("Listener: Converting {$media->id} to {$webpPath}");

            // Convert to WebP using Spatie Image
            Image::load($originalPath)
                ->format('webp')
                ->quality(80)
                ->save($webpPath);

            // Delete original file
            if (file_exists($originalPath) && $originalPath !== $webpPath) {
                unlink($originalPath);
                Log::info("Listener: Deleted original file for {$media->id}");
            }

            // Update media record with new file name and mime type
            $newFileName = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $media->file_name);
            $media->file_name = $newFileName;
            $media->mime_type = 'image/webp';

            // Update file size
            if (file_exists($webpPath)) {
                $media->size = filesize($webpPath);
            }

            // Save normally to trigger thumbnail generation!
            $media->save();
            Log::info("Listener: Successfully converted and saved {$media->id}");

        } catch (\Exception $e) {
            Log::error('Listener: WebP conversion failed for ' . $media->id . ': ' . $e->getMessage());
        }
    }
}
