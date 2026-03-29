<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RestoreCharterTranslations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:restore-charter-translations {file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Surgically restores only the translated descriptions from a SQL GZ backup for Charter Yachts';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $backupFile = $this->argument('file');

        if (!file_exists($backupFile)) {
            $this->error("Backup file not found at: {$backupFile}");
            return 1;
        }

        $this->info("Starting surgical restoration of Charter Yacht translations!");
        
        // 1. Create a safe temporary table matching exact yachts structure
        $this->info("Creating safe temporary table: yachts_backup...");
        DB::statement('DROP TABLE IF EXISTS yachts_backup');
        DB::statement('CREATE TABLE yachts_backup LIKE yachts');

        // 2. Extract ONLY the INSERTs for the yachts table into a temp SQL file
        $tempSqlPath = storage_path('app/temp_yachts_inserts.sql');
        $this->info("Extracting INSERT statements from compressed SQL to {$tempSqlPath}...");
        
        $command = sprintf(
            'gunzip -c %s | grep "INSERT INTO \`yachts\`" | sed -e "s/\`yachts\`/\`yachts_backup\`/g" > %s',
            escapeshellarg($backupFile),
            escapeshellarg($tempSqlPath)
        );
        exec($command);

        if (!file_exists($tempSqlPath) || filesize($tempSqlPath) < 10) {
            $this->error("Failed to extract INSERT statements or file is empty.");
            return 1;
        }

        // 3. Import the backup data into the temporary table
        $this->info("Loading backup data into yachts_backup...");
        try {
            DB::unprepared(file_get_contents($tempSqlPath));
        } catch (\Exception $e) {
            $this->error("Failed to load backup data: " . $e->getMessage());
            return 1; // Critical failure
        }
        
        // 4. Surgically update ONLY the description translations for charter yachts where they match
        // We use JSON_EXTRACT and JSON_SET to keep today's prices & other fields fully intact.
        $this->info("Surgically injecting translated descriptions...");
        
        $affected = DB::update("
            UPDATE yachts y
            JOIN yachts_backup b ON y.id = b.id
            SET y.custom_fields = JSON_SET(
                y.custom_fields,
                '$.description', JSON_EXTRACT(b.custom_fields, '$.description'),
                '$.Description', JSON_EXTRACT(b.custom_fields, '$.description')
            )
            WHERE y.type = 'charter'
        ");

        $this->info("Successfully restored translated descriptions for {$affected} Charter Yachts!");

        // 5. Cleanup
        $this->info("Cleaning up temporary tables and files...");
        DB::statement('DROP TABLE IF EXISTS yachts_backup');
        File::delete($tempSqlPath);

        $this->info("All done! Run 'php artisan sync:sites' or click 'Sync to WordPress' to push recovered translations to the live site.");

        return 0;
    }
}
