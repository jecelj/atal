<?php

namespace App\Listeners;

use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Illuminate\Support\Facades\Log;

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

            // Load image using GD based on mime type
            $image = match ($media->mime_type) {
                'image/jpeg' => imagecreatefromjpeg($originalPath),
                'image/png' => imagecreatefrompng($originalPath),
                'image/gif' => imagecreatefromgif($originalPath),
                default => null,
            };

            if (!$image) {
                Log::error("ConvertImageToWebP: Failed to load image for {$media->id}");
                return;
            }

            // Get original dimensions
            $width = imagesx($image);
            $height = imagesy($image);
            Log::info("ConvertImageToWebP: Original dimensions: {$width}x{$height}");

            // Resize if width exceeds 2500px
            if ($width > 2500) {
                $newWidth = 2500;
                $newHeight = (int) round(($height / $width) * 2500);
                Log::info("ConvertImageToWebP: Resizing to {$newWidth}x{$newHeight}");

                $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $resizedImage;
            }

            // Convert to WebP and save
            imagewebp($image, $webpPath, 80);
            imagedestroy($image);

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
