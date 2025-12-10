<?php

namespace App\Listeners;

use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Illuminate\Support\Facades\Log;

class ConvertImageToWebP
{
    /**
     * Flag to control whether conversion should run.
     * Use to FALSE to prevent auto-optimization on upload.
     * Enabled only for specific bulk imports or if explicitly requested.
     */
    public static bool $shouldConvert = false;

    public function handle(MediaHasBeenAddedEvent $event): void
    {
        if (!self::$shouldConvert) {
            return;
        }

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

                // Preserve transparency
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);

                imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $resizedImage;
            } else {
                // Even if not resizing, ensure transparency is preserved for the original image resource if it's PNG/GIF
                imagepalettetotruecolor($image);
                imagealphablending($image, false);
                imagesavealpha($image, true);
            }

            // Convert to WebP and save
            $success = imagewebp($image, $webpPath, 80);
            imagedestroy($image);

            // Clear cache to ensure filesize is correct
            clearstatcache(true, $webpPath);

            // Strict Validation
            $isValid = false;
            if ($success && file_exists($webpPath) && filesize($webpPath) > 0) {
                // 1. Check if file is too small (absolute minimum: 10KB)
                $newSize = filesize($webpPath);

                // Absolute minimum: 10KB for any yacht image
                if ($newSize < 10240) {
                    Log::error("ConvertImageToWebP: WebP file is too small ({$newSize} bytes). Rejecting.");
                } else {
                    // 2. Check dimensions (absolute minimum: 50x50px)
                    $imageInfo = @getimagesize($webpPath);
                    if (!$imageInfo || $imageInfo[0] < 50 || $imageInfo[1] < 50) {
                        Log::error("ConvertImageToWebP: WebP dimensions are too small or invalid. Rejecting.");
                    } else {
                        // 3. Try to load the new WebP file to ensure it's valid
                        try {
                            $checkImage = @imagecreatefromwebp($webpPath);
                            if ($checkImage) {
                                $isValid = true;
                                imagedestroy($checkImage);
                            } else {
                                Log::error("ConvertImageToWebP: Generated WebP file is invalid (cannot be loaded).");
                            }
                        } catch (\Throwable $e) {
                            Log::error("ConvertImageToWebP: Exception checking WebP validity: " . $e->getMessage());
                        }
                    }
                }
            } else {
                Log::error("ConvertImageToWebP: Failed to create WebP file at {$webpPath}");
            }

            if (!$isValid) {
                if (file_exists($webpPath)) {
                    unlink($webpPath); // Clean up corrupt file
                }
                Log::warning("ConvertImageToWebP: Conversion failed validation. Keeping original file.");
                return;
            }

            Log::info("ConvertImageToWebP: WebP file saved at {$webpPath}");

            // Update media record with new file name and mime type
            $newFileName = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $media->file_name);

            // CRITICAL: Only delete original if validation passed AND paths are different
            if ($isValid && file_exists($originalPath) && $originalPath !== $webpPath) {
                // Double-check that WebP exists and is valid before deleting original
                if (file_exists($webpPath) && filesize($webpPath) > 10240) {
                    unlink($originalPath);
                    Log::info("ConvertImageToWebP: Deleted original file {$originalPath}");
                } else {
                    Log::warning("ConvertImageToWebP: Skipping original deletion - WebP invalid");
                }
            }

            $media->file_name = $newFileName;
            $media->mime_type = 'image/webp';
            $media->size = filesize($webpPath);
            Log::info("ConvertImageToWebP: New file size: {$media->size} bytes");

            // Save normally to trigger thumbnail generation!
            $media->save();
            Log::info("ConvertImageToWebP: Successfully converted and saved media {$media->id}");

        } catch (\Throwable $e) {
            Log::error('ConvertImageToWebP: Conversion failed for ' . $media->id . ': ' . $e->getMessage());
            Log::error('ConvertImageToWebP: Stack trace: ' . $e->getTraceAsString());
        }
    }
}
