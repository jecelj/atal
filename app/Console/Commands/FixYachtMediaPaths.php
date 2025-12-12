<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\NewYacht;
use App\Models\UsedYacht;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class FixYachtMediaPaths extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:fix-paths {--dry-run : Only show what would be moved}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate yacht media from legacy named paths to ID-based paths';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Starting Media Path Migration...");

        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->warn("DRY RUN MODE: No files will be moved.");
        }

        // Process New Yachts
        $this->processYachts(NewYacht::all(), 'NewYacht', $dryRun);

        // Process Used Yachts
        $this->processYachts(UsedYacht::all(), 'UsedYacht', $dryRun);

        $this->info("Migration Complete.");
    }

    protected function processYachts($yachts, $type, $dryRun)
    {
        $disk = Storage::disk('public');
        $rootPath = $disk->path('');

        $this->info("Processing {$yachts->count()} {$type}s...");

        foreach ($yachts as $yacht) {
            $nameDisplay = $yacht->getTranslation('name', 'en', false) ?: 'Unknown';
            $this->line("Checking Yacht: {$yacht->id} - {$nameDisplay}");

            // 1. Calculate Target Path (New Logic)
            $targetPath = "yachts/{$yacht->id}";
            $absTargetPath = $rootPath . $targetPath;

            // 2. Calculate Source Path (Legacy Logic)
            // We need to guess the folder name. It was based on TRANSLATED name.
            // But imports usually happen in English default?
            // Or did it use the current locale context of the seeding?
            // Strategy: Check English first.

            $sourceFound = false;
            $absSourcePath = '';

            // Try English Name
            $nameEn = $yacht->getTranslation('name', 'en', false);
            if ($nameEn) {
                $path = $this->calculateLegacyPath($yacht, $nameEn);
                if ($disk->exists($path)) {
                    $absSourcePath = $rootPath . $path;
                    $sourceFound = true;
                    $this->line("  Found source (EN): $path");
                }
            }

            // If not found, try all translations
            if (!$sourceFound) {
                foreach ($yacht->getTranslations('name') as $locale => $name) {
                    if ($locale === 'en')
                        continue;
                    $path = $this->calculateLegacyPath($yacht, $name);
                    if ($disk->exists($path)) {
                        $absSourcePath = $rootPath . $path;
                        $sourceFound = true;
                        $this->line("  Found source ($locale): $path");
                        break;
                    }
                }
            }

            // If still not found, try raw slug? 
            // In the "Grand Turismo" case, the name might be "Gran Turismo" but slug "grand-turismo".
            // The folder is usually created from Name Slug.

            // Special Check for Manual Renames (Gran -> Grand)
            // If we still haven't found it, maybe search specifically for this ID's media?
            // Spatie stores media paths in DB... wait!
            // Spatie Media Library stores the path in the 'media' table? 
            // Usually it stores 'file_name' and computed path is dynamic.
            // BUT, if we can inspect the media records for this yacht, maybe we can see if THEY exist?
            // Actually, we are moving the DIRECTORY. 

            if (!$sourceFound) {
                // If the target already exists, maybe already migrated?
                if ($disk->exists($targetPath)) {
                    $this->info("  Target directory already exists. Skipping.");
                    continue;
                }

                $this->error("  ⚠️  Source directory not found for Yacht {$yacht->id}. Skipped.");
                continue;
            }

            // 3. Move
            if ($sourceFound) {
                if ($absSourcePath === $absTargetPath) {
                    $this->info("  Already at target path.");
                    continue;
                }

                $this->info("  Moving: $absSourcePath -> $absTargetPath");

                if (!$dryRun) {
                    if (!File::exists($absTargetPath)) {
                        File::makeDirectory($absTargetPath, 0755, true);
                    }

                    // Move contents
                    // We move the CONTENTS of source to target, or the folder itself?
                    // Structure was: yachts/brand/model/name/UUID/image.jpg (Default Spatie)
                    // OR simple: yachts/brand/model/name/image.jpg?
                    // YachtMediaPathGenerator said: return "yachts/{brand}/{model}/{slug}/";
                    // Spatie appends "/" + media_id + "/" + filename usually?
                    // Let's check the Generator again.
                    // getPath($media) return basePath . '/';
                    // So files are directly in `yachts/brand/model/slug/`.
                    // Wait, usually Spatie uses `id` folder.
                    // Checking existing files...
                    // `Gt50-alpine-B.00004.jpg.webp` was found at `.../gran-turismo-50/Gt50...`
                    // It seems files are DIRECTLY in the yacht folder, NOT in subfolders per media ID.
                    // Code: return $this->getBasePath($media) . '/';
                    // Yes. No media ID.

                    // So we verify we are moving the *directory* `yachts/brand/model/name`.
                    // Does this directory contain *only* this yacht's files?
                    // Yes, `yachts/brand/model/slug` is unique to the yacht.

                    // Move the directory rename?
                    // We want to move `.../slug/*` to `.../id/*`.

                    // Check if Target already has stuff (merge?)
                    // Move files one by one to be safe.
                    $files = File::allFiles($absSourcePath);
                    foreach ($files as $file) {
                        $filename = $file->getFilename();
                        $targetFile = $absTargetPath . '/' . $filename;
                        File::move($file->getPathname(), $targetFile);
                    }

                    // Check if source is empty, then delete?
                    if (count(File::allFiles($absSourcePath)) === 0) {
                        File::deleteDirectory($absSourcePath);
                        // Try to delete parent (model) if empty?
                        // Try to delete parent (brand) if empty? (Clean up)
                        $this->cleanupEmptyParents($absSourcePath);
                    }
                }
            }

        }
    }

    protected function calculateLegacyPath($yacht, $name)
    {
        $brandSlug = $yacht->brand ? Str::slug($yacht->brand->name) : 'unknown-brand';
        $modelSlug = $yacht->yachtModel ? Str::slug($yacht->yachtModel->name) : 'unknown-model';
        $yachtSlug = Str::slug($name) ?: 'yacht-' . $yacht->id;

        return "yachts/{$brandSlug}/{$modelSlug}/{$yachtSlug}";
    }

    protected function cleanupEmptyParents($path)
    {
        // Try delete model folder
        $parent = dirname($path);
        if (count(File::allFiles($parent)) === 0 && count(File::directories($parent)) === 0) {
            File::deleteDirectory($parent);
            // Try delete brand folder
            $grandparent = dirname($parent);
            if (count(File::allFiles($grandparent)) === 0 && count(File::directories($grandparent)) === 0) {
                File::deleteDirectory($grandparent);
            }
        }
    }
}
