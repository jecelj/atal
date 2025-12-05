<?php

namespace App\Services;

use App\Models\Yacht;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ImageOptimizationService
{
    /**
     * Track processed files to avoid reprocessing the same physical file
     * when it's used in multiple collections.
     * Maps: original_path => ['new_path' => string, 'new_filename' => string]
     */
    protected array $processedFiles = [];

    public function processYachtImages(Yacht $yacht): array
    {
        // Increase limits for heavy processing
        ini_set('memory_limit', '512M');
        set_time_limit(600);

        $stats = [
            'processed' => 0,
            'renamed' => 0,
            'converted' => 0,
            'resized' => 0,
            'errors' => 0,
        ];

        $brandName = $yacht->brand ? Str::slug($yacht->brand->name) : 'yacht';
        $modelName = $yacht->yachtModel ? Str::slug($yacht->yachtModel->name) : $yacht->id;
        $baseName = "{$brandName}-{$modelName}";

        // Group media by collection to handle indexing
        $collections = $yacht->getMedia('*')->groupBy('collection_name');

        Log::info("ImageOptimizationService: Starting optimization for yacht {$yacht->id}");

        foreach ($collections as $collectionName => $mediaItems) {
            Log::info("ImageOptimizationService: Processing collection '{$collectionName}' with " . $mediaItems->count() . " items");

            foreach ($mediaItems as $index => $media) {
                Log::info("ImageOptimizationService: [START] Processing media ID {$media->id} (index {$index}), file: {$media->file_name}, size: {$media->size} bytes, mime: {$media->mime_type}");

                try {
                    // Skip non-images
                    if (!str_starts_with($media->mime_type, 'image/')) {
                        continue;
                    }

                    // CHECK: Already optimized?
                    if ($media->getCustomProperty('optimized') === true) {
                        continue;
                    }

                    $stats['processed']++;
                    $needsSave = false;

                    // 1. Determine new filename
                    // Format: Brand-Model-Collection-Index.webp
                    // Index is 1-based
                    $newFileNameBase = "{$baseName}-{$collectionName}-" . ($index + 1);
                    $newFileName = "{$newFileNameBase}.webp";

                    // SPECIAL HANDLING FOR WEBP: Check size and recompress if needed
                    if ($media->mime_type === 'image/webp') {
                        $originalPath = $media->getPath();
                        Log::info("ImageOptimizationService: WebP detected for media {$media->id}, path: {$originalPath}");

                        // CRITICAL: Check if this file was already processed in this run
                        if (isset($this->processedFiles[$originalPath])) {
                            $processedInfo = $this->processedFiles[$originalPath];
                            Log::info("ImageOptimizationService: File '{$originalPath}' was already processed. Linking media {$media->id} to existing file: {$processedInfo['new_path']}");

                            // Update media record to point to the already-processed file
                            $media->file_name = $processedInfo['new_filename'];
                            $media->size = filesize($processedInfo['new_path']);
                            $media->setCustomProperty('optimized', true);
                            $media->save();

                            $stats['renamed']++; // Count as renamed since we updated the reference
                            Log::info("ImageOptimizationService: [END] Successfully linked media ID {$media->id} to already processed file");
                            continue;
                        }

                        // Check if file actually exists
                        if (!file_exists($originalPath)) {
                            Log::warning("ImageOptimizationService: File not found for media {$media->id} at '{$originalPath}' and not in processed cache. Skipping.");
                            $stats['errors']++;
                            continue;
                        }

                        $fileSize = $media->size;

                        // If WebP is larger than 500KB, recompress it
                        if ($fileSize > 512000) {
                            Log::info("ImageOptimizationService: WebP file {$media->id} is {$fileSize} bytes (>500KB), recompressing...");

                            try {
                                // Load WebP image
                                $image = imagecreatefromwebp($originalPath);
                                if (!$image) {
                                    Log::error("ImageOptimizationService: Failed to load WebP {$media->id} for recompression");
                                    $stats['errors']++;
                                    continue;
                                }

                                $width = imagesx($image);
                                $height = imagesy($image);

                                // Resize if width > 2000px
                                if ($width > 2000) {
                                    $newWidth = 2000;
                                    $newHeight = (int) round(($height / $width) * 2000);
                                    Log::info("ImageOptimizationService: Resizing WebP from {$width}x{$height} to {$newWidth}x{$newHeight}");

                                    $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

                                    // Preserve transparency
                                    imagealphablending($resizedImage, false);
                                    imagesavealpha($resizedImage, true);

                                    imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                                    imagedestroy($image);
                                    $image = $resizedImage;
                                    $stats['resized']++;
                                }

                                // Determine target path (with new filename if renamed)
                                $directory = dirname($originalPath);
                                $targetPath = $directory . DIRECTORY_SEPARATOR . $newFileName;

                                // Save with 80% quality
                                $success = imagewebp($image, $targetPath, 80);
                                imagedestroy($image);

                                if ($success && file_exists($targetPath)) {
                                    clearstatcache(true, $targetPath);
                                    $newSize = filesize($targetPath);

                                    // Delete old file if path changed
                                    if ($originalPath !== $targetPath && file_exists($originalPath)) {
                                        unlink($originalPath);
                                    }

                                    // Update media record
                                    $media->name = $newFileNameBase;
                                    $media->file_name = $newFileName;
                                    $media->size = $newSize;
                                    $media->setCustomProperty('optimized', true);
                                    $media->save();

                                    $stats['converted']++;
                                    if ($media->file_name !== $newFileName) {
                                        $stats['renamed']++;
                                    }

                                    // Cache this file so other media records can reuse it
                                    $this->processedFiles[$originalPath] = [
                                        'new_path' => $targetPath,
                                        'new_filename' => $newFileName,
                                    ];

                                    Log::info("ImageOptimizationService: Recompressed WebP from {$fileSize} to {$newSize} bytes. Cached for reuse.");
                                } else {
                                    Log::error("ImageOptimizationService: Failed to save recompressed WebP");
                                    $stats['errors']++;
                                }

                            } catch (\Throwable $e) {
                                Log::error("ImageOptimizationService: Error recompressing WebP {$media->id}: " . $e->getMessage());
                                $stats['errors']++;
                            }
                        } else {
                            // WebP is small enough, just rename if needed
                            if ($media->file_name !== $newFileName) {
                                $oldPath = $media->getPath();
                                $directory = dirname($oldPath);
                                $newPath = $directory . DIRECTORY_SEPARATOR . $newFileName;

                                if (file_exists($oldPath)) {
                                    // Rename file on disk
                                    if (rename($oldPath, $newPath)) {
                                        $media->name = $newFileNameBase;
                                        $media->file_name = $newFileName;
                                        $media->setCustomProperty('optimized', true);
                                        $media->save();

                                        $stats['renamed']++;

                                        // Cache this file so other media records can reuse it
                                        $this->processedFiles[$oldPath] = [
                                            'new_path' => $newPath,
                                            'new_filename' => $newFileName,
                                        ];

                                        Log::info("ImageOptimizationService: Renamed WebP only: {$oldPath} -> {$newPath}. Cached for reuse.");
                                    } else {
                                        Log::error("ImageOptimizationService: Failed to rename WebP: {$oldPath} -> {$newPath}");
                                        $stats['errors']++;
                                    }
                                }
                            } else {
                                // Already WebP, correct name, and small enough. Just mark optimized.
                                if (!$media->getCustomProperty('optimized')) {
                                    $media->setCustomProperty('optimized', true);
                                    $media->save();
                                }
                            }
                        }
                        // Skip heavy processing for WebP (already handled above)
                        continue;
                    }

                    // Check if renaming is needed (for non-WebP, we will handle it during processing)
                    if ($media->file_name !== $newFileName) {
                        $stats['renamed']++;
                        $needsSave = true;
                    }

                    // 2. Process Image (Resize & Convert)
                    $originalPath = $media->getPath();

                    // CRITICAL: Check if this file was already processed in this run
                    if (isset($this->processedFiles[$originalPath])) {
                        $processedInfo = $this->processedFiles[$originalPath];
                        Log::info("ImageOptimizationService: File '{$originalPath}' was already processed. Linking media {$media->id} to existing file: {$processedInfo['new_path']}");

                        // Update media record to point to the already-processed file
                        $media->file_name = $processedInfo['new_filename'];
                        $media->mime_type = 'image/webp'; // It was converted to WebP
                        $media->size = filesize($processedInfo['new_path']);
                        $media->setCustomProperty('optimized', true);
                        $media->save();

                        $stats['renamed']++; // Count as renamed since we updated the reference
                        Log::info("ImageOptimizationService: [END] Successfully linked media ID {$media->id} to already processed file");
                        continue;
                    }

                    if (!file_exists($originalPath)) {
                        Log::warning("ImageOptimizationService: File not found for media {$media->id} at '{$originalPath}' and not in processed cache. Skipping.");
                        $stats['errors']++;
                        continue;
                    }

                    // Load image
                    $image = $this->loadImage($originalPath, $media->mime_type);
                    if (!$image) {
                        Log::error("ImageOptimizationService: Failed to load image {$media->id}");
                        $stats['errors']++;
                        continue;
                    }

                    $width = imagesx($image);
                    $height = imagesy($image);
                    $originalWidth = $width;

                    // Resize if needed
                    if ($width > 2500) {
                        $newWidth = 2500;
                        $newHeight = (int) round(($height / $width) * 2500);

                        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

                        // Preserve transparency for PNG/WebP
                        imagealphablending($resizedImage, false);
                        imagesavealpha($resizedImage, true);

                        imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                        imagedestroy($image);
                        $image = $resizedImage;

                        $stats['resized']++;
                        $needsSave = true;
                    }

                    // Convert/Save as WebP
                    // We always save as WebP to the new filename path
                    // Construct new path based on the directory of the original file
                    $directory = dirname($originalPath);
                    $targetPath = $directory . DIRECTORY_SEPARATOR . $newFileName;

                    // Save to target path
                    $success = imagewebp($image, $targetPath, 80);
                    imagedestroy($image);

                    // Clear cache to ensure filesize is correct
                    clearstatcache(true, $targetPath);

                    // Strict Validation
                    $isValid = false;
                    if ($success && file_exists($targetPath) && filesize($targetPath) > 0) {
                        // 1. Check if file is too small (absolute minimum: 10KB)
                        $newSize = filesize($targetPath);

                        // Absolute minimum: 10KB for any yacht image
                        if ($newSize < 10240) {
                            Log::error("ImageOptimizationService: WebP file is too small ({$newSize} bytes). Rejecting.");
                        } else {
                            // 2. Check dimensions (absolute minimum: 50x50px)
                            $imageInfo = @getimagesize($targetPath);
                            if (!$imageInfo || $imageInfo[0] < 50 || $imageInfo[1] < 50) {
                                Log::error("ImageOptimizationService: WebP dimensions are too small or invalid. Rejecting.");
                            } else {
                                // 3. Try to load the new WebP file to ensure it's valid
                                try {
                                    $checkImage = @imagecreatefromwebp($targetPath);
                                    if ($checkImage) {
                                        $isValid = true;
                                        imagedestroy($checkImage);
                                    } else {
                                        Log::error("ImageOptimizationService: Generated WebP file is invalid (cannot be loaded).");
                                    }
                                } catch (\Throwable $e) {
                                    Log::error("ImageOptimizationService: Exception checking WebP validity: " . $e->getMessage());
                                }
                            }
                        }
                    } else {
                        Log::error("ImageOptimizationService: Failed to create WebP file at {$targetPath}");
                    }

                    if (!$isValid) {
                        if (file_exists($targetPath)) {
                            unlink($targetPath);
                        }
                        $stats['errors']++;
                        continue;
                    }

                    // CRITICAL: Only delete original if validation passed AND paths are different
                    if ($isValid && $originalPath !== $targetPath && file_exists($originalPath)) {
                        // Double-check that target exists and is valid before deleting original
                        if (file_exists($targetPath) && filesize($targetPath) > 10240) {
                            unlink($originalPath);
                            Log::info("ImageOptimizationService: Deleted original file {$originalPath}");
                            $stats['converted']++;
                            $needsSave = true;
                        } else {
                            Log::warning("ImageOptimizationService: Skipping original deletion - target invalid");
                        }
                    }

                    // Update Media Model
                    if ($needsSave) {
                        $media->name = $newFileNameBase; // Name without extension
                        $media->file_name = $newFileName;
                        $media->mime_type = 'image/webp';
                        $media->size = filesize($targetPath);
                        $media->setCustomProperty('optimized', true); // Mark as optimized
                        $media->save();

                        // Cache this file so other media records can reuse it
                        $this->processedFiles[$originalPath] = [
                            'new_path' => $targetPath,
                            'new_filename' => $newFileName,
                        ];
                    } else {
                        // Even if no save was needed (e.g. just validation passed), mark as optimized
                        $media->setCustomProperty('optimized', true);
                        $media->save();
                    }

                    Log::info("ImageOptimizationService: [END] Successfully processed media ID {$media->id}");

                } catch (\Throwable $e) {
                    Log::error("ImageOptimizationService: [ERROR] Failed processing media {$media->id}: " . $e->getMessage());
                    Log::error("ImageOptimizationService: Stack trace: " . $e->getTraceAsString());
                    $stats['errors']++;
                }
            }
        }

        Log::info("ImageOptimizationService: Optimization complete for yacht {$yacht->id}", $stats);

        return $stats;
    }

    protected function loadImage(string $path, string $mimeType)
    {
        return match ($mimeType) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/gif' => imagecreatefromgif($path),
            'image/webp' => imagecreatefromwebp($path),
            default => null,
        };
    }
}
