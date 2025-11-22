<?php

namespace App\Services;

use Spatie\MediaLibrary\Support\FileNamer\FileNamer;
use Spatie\MediaLibrary\Conversions\Conversion;

/**
 * Default file namer - uses original filename.
 * Custom naming is handled in YachtMediaObserver instead.
 */
class YachtMediaFileNamer extends FileNamer
{
    public function originalFileName(string $fileName): string
    {
        // Return the original filename without extension
        return pathinfo($fileName, PATHINFO_FILENAME);
    }

    public function conversionFileName(string $fileName, Conversion $conversion): string
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        return "{$name}-{$conversion->getName()}";
    }

    public function responsiveFileName(string $fileName): string
    {
        return pathinfo($fileName, PATHINFO_FILENAME);
    }
}
