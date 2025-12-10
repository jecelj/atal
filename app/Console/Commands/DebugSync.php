<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SyncSite;
use App\Services\WordPressSyncService;

class DebugSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:debug {site_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug synchronization logic for a site';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $siteId = $this->argument('site_id');

        $siteId = $this->argument('site_id');

        if (!$siteId) {
            $sites = SyncSite::all();
            $this->info("Available Sites:");
            $this->table(
                ['ID', 'Name', 'URL', 'Active'],
                $sites->map(fn($s) => [
                    $s->id,
                    $s->name,
                    $s->url,
                    $s->is_active ? 'Yes' : 'No'
                ])
            );
            $this->info("Usage: php artisan sync:debug <site_id>");
            return;
        }

        $site = SyncSite::find($siteId);
        if (!$site) {
            $this->error("Site with ID $siteId not found.");
            return;
        }

        $this->info("Starting DEBUG Sync for: " . $site->name);
        $this->info("URL: " . $site->url);

        $service = app(WordPressSyncService::class);

        // Output raw URL that will be used (replicate logic from Service)
        $parsed = parse_url($site->url);
        $baseUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        if (str_contains($site->url, '/wp-json/')) {
            $baseUrl = substr($site->url, 0, strpos($site->url, '/wp-json/'));
        } else {
            $baseUrl = rtrim($site->url, '/');
        }
        $targetUrl = $baseUrl . '/wp-json/atal-sync/v1/push';

        $this->info("Calculated Target API URL: " . $targetUrl);

        try {
            $result = $service->syncSite($site);

            $this->info("Sync Completed.");
            $this->info("Success: " . ($result['success'] ? 'YES' : 'NO'));
            if (!$result['success']) {
                $this->error("Error Message: " . $result['message']);
            }
            if (isset($result['imported'])) {
                $this->info("Items Imported: " . $result['imported']);
            }
            if (!empty($result['errors'])) {
                $this->error("Detailed Errors:");
                foreach ($result['errors'] as $err) {
                    $this->line("- " . $err);
                }
            }

        } catch (\Exception $e) {
            $this->error("EXCEPTION OCCURRED:");
            $this->error($e->getMessage());
            $this->line($e->getTraceAsString());
        }
    }
}
