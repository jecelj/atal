<?php

namespace App\Console\Commands;

use App\Models\CharterYacht;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanCharterTitles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clean-charter-titles';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Overwrites all translated Charter Yacht names with their original English title';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Scanning all Charter Yachts...");

        $yachts = CharterYacht::all();
        $updatedCount = 0;

        foreach ($yachts as $yacht) {
            $enName = $yacht->getTranslation('name', 'en', false);
            
            // Fallback to whichever array key exists if 'en' is missing
            if (empty($enName) && is_array($yacht->name) && count($yacht->name) > 0) {
                $enName = array_values($yacht->name)[0];
            }

            if (empty($enName)) {
                $this->warn("Skipping yacht ID {$yacht->id} (No valid EN name found)");
                continue;
            }

            // Get existing name array to know which languages exist
            $existingNameArray = is_array($yacht->name) ? $yacht->name : ['en' => $enName];
            $newNameArray = [];

            // Ensure we cover the standard languages from the system
            $locales = ['en', 'sl', 'de', 'it', 'hr', 'cs', 'sk', 'pl', 'es', 'sr'];
            
            // Also include any other obscure languages that might be in this specific row
            foreach (array_keys($existingNameArray) as $existingLocale) {
                if (!in_array($existingLocale, $locales)) {
                    $locales[] = $existingLocale;
                }
            }

            // Overwrite all language keys with the original English name
            foreach ($locales as $locale) {
                $newNameArray[$locale] = $enName;
            }

            // We use DB facade to be completely surgically safe and bypass any events/fillables
            DB::table('yachts')
                ->where('id', $yacht->id)
                ->update(['name' => json_encode($newNameArray, JSON_UNESCAPED_UNICODE)]);
            
            $updatedCount++;
        }

        $this->info("Done! Protected English titles enforced for {$updatedCount} yachts.");
        return 0;
    }
}
