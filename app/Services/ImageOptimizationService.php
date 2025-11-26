<?php

namespace App\Services;

use App\Models\Yacht;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ImageOptimizationService
{
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

        foreach ($collections as $collectionName => $mediaItems) {
            foreach ($mediaItems as $index => $media) {
                try {
                    // Skip non-images
                    if (!str_starts_with($media->mime_type, 'image/')) {
                        continue;
                    }

                    $stats['processed']++;
                    $needsSave = false;

                    // 1. Determine new filename
                    // Format: Brand-Model-Collection-Index.webp
                    // Index is 1-based
                    $newFileNameBase = "{$baseName}-{$collectionName}-" . ($index + 1);
                    $newFileName = "{$newFileNameBase}.webp";

                    // Check if renaming is needed
                    if ($media->file_name !== $newFileName) {
                        $oldPath = $media->getPath();
                        $newPath = str_replace($media->file_name, $newFileName, $oldPath);

                        // If we are just renaming (and maybe converting), we need to be careful
                        // But first, let's handle the processing (resize/convert) which might create a new file anyway

                        $stats['renamed']++;
                        $needsSave = true;
                    }

                    // 2. Process Image (Resize & Convert)
                    $originalPath = $media->getPath();

                    if (!file_exists($originalPath)) {
                        Log::warning("ImageOptimizationService: File not found for media {$media->id}");
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
                    imagewebp($image, $targetPath, 80);
                    imagedestroy($image);

                    // If the file name changed or we converted format, delete the old file
                    if ($originalPath !== $targetPath) {
                        if (file_exists($originalPath)) {
                            unlink($originalPath);
                        }
                        $stats['converted']++;
                        $needsSave = true;
                    }

                    // Update Media Model
                    if ($needsSave) {
                        $media->name = $newFileNameBase; // Name without extension
                        $media->file_name = $newFileName;
                        $media->mime_type = 'image/webp';
                        $media->size = filesize($targetPath);
                        $media->save();
                    }

                } catch (\Throwable $e) {
                    Log::error("ImageOptimizationService: Error processing media {$media->id}: " . $e->getMessage());
                    $stats['errors']++;
                }
            }
        }

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
