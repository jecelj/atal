<?php

namespace App\Listeners;

use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;

class ConvertImageToWebP
{
    public function handle(MediaHasBeenAddedEvent $event): void
    {
        // Increase memory and time limit for image processing to prevent 500 errors
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $media = $event->media;

        Log::info("ConvertImageToWebP: Processing media ID {$media->id} ({$media->file_name}), mime: {$media->mime_type}");

        // Only process images
        if (!str_starts_with($media->mime_type, 'image/')) {
            Log::info("ConvertImageToWebP: Skipping non-image file {$media->id}");
            return;
        }

        // Skip if already WebP
        if ($media->mime_type === 'image/webp') {
            Log::info("ConvertImageToWebP: Skipping already WebP file {$media->id}");
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
                Log::error("ConvertImageToWebP: File not found for {$media->id} at {$originalPath}");
                return;
            }

            Log::info("ConvertImageToWebP: Original file path: {$originalPath}");

            // Create WebP version path
            $webpPath = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $originalPath);
            Log::info("ConvertImageToWebP: Target WebP path: {$webpPath}");

            // Use Intervention Image with GD driver
            $manager = new ImageManager(new GdDriver());
            $image = $manager->read($originalPath);

            // Get original dimensions
            $width = $image->width();
            $height = $image->height();
            Log::info("ConvertImageToWebP: Original dimensions: {$width}x{$height}");

            // Resize if width exceeds 2500px
            if ($width > 2500) {
                $newHeight = (int) round(($height / $width) * 2500);
                Log::info("ConvertImageToWebP: Resizing to 2500x{$newHeight}");
                $image->scale(width: 2500);
            }

            // Convert to WebP and save
            $image->toWebp(quality: 80)->save($webpPath);

            Log::info("ConvertImageToWebP: WebP file saved at {$webpPath}");

            // Delete original file
            if (file_exists($originalPath) && $originalPath !== $webpPath) {
                unlink($originalPath);
                Log::info("ConvertImageToWebP: Deleted original file {$originalPath}");
            }

            // Update media record with new file name and mime type
            $newFileName = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $media->file_name);
            $media->file_name = $newFileName;
            $media->mime_type = 'image/webp';

            // Update file size
            if (file_exists($webpPath)) {
                $media->size = filesize($webpPath);
                Log::info("ConvertImageToWebP: New file size: {$media->size} bytes");
            }

            // Save normally to trigger thumbnail generation!
            $media->save();
            Log::info("ConvertImageToWebP: Successfully converted and saved media {$media->id}");

        } catch (\Throwable $e) {
            Log::error('ConvertImageToWebP: Conversion failed for ' . $media->id . ': ' . $e->getMessage());
            Log::error('ConvertImageToWebP: Stack trace: ' . $e->getTraceAsString());
        }
    }
}
